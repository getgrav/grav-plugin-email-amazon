<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailAmazon\Tests\Unit;

use Grav\Plugin\Email\Providers\DeliveryReports;
use Grav\Plugin\Email\Providers\Provider;
use Grav\Plugin\Email\Providers\ProviderRegistry;
use Grav\Plugin\Email\Providers\WebhookSetup;
use Grav\Plugin\EmailAmazon\Aws\AwsApi;
use Grav\Plugin\EmailAmazon\Provider\SesProvider;
use Grav\Plugin\EmailAmazon\Tests\Support\FakeHttp;
use PHPUnit\Framework\TestCase;

/**
 * What this plugin says about Amazon SES, and about itself.
 *
 * The half of these that matter are the capabilities, because they depend on a
 * setting rather than on Amazon. The `api` transport sends the parts of a
 * message rather than the message and leaves every custom header behind,
 * `List-Unsubscribe` included; `https` and `smtp` send the whole thing. A bulk
 * sender on the wrong one of those has no unsubscribe button in Gmail and no
 * way to tie a bounce to the message it came from, and nothing anywhere would
 * say so unless this does.
 */
final class SesProviderTest extends TestCase
{
    public function testItAnswersForTheEnginesThisPluginRegisters(): void
    {
        $provider = new SesProvider();

        self::assertSame('ses', $provider->key());
        self::assertSame('Amazon SES', $provider->label());
        self::assertContains('amazon', $provider->engines(), 'the engine this plugin has always registered');
        self::assertContains('ses', $provider->engines(), 'the name the transport is known by everywhere else');
    }

    /** It goes into the Email plugin's registry and comes back out under both names. */
    public function testItRegistersAndIsFoundByEngineAndByKey(): void
    {
        $registry = new ProviderRegistry();
        $registry->add(new SesProvider());

        self::assertInstanceOf(Provider::class, $registry->byKey('ses'));
        self::assertSame('ses', $registry->forEngine('amazon')?->key());
        self::assertSame('ses', $registry->forEngine('ses')?->key());
        self::assertNull($registry->forEngine('smtp2go'));
    }

    /**
     * The API transport drops every custom header, and that is the one thing on
     * this page worth knowing before forty thousand messages go out.
     */
    public function testTheApiTransportSaysItDropsHeaders(): void
    {
        foreach ([['transport' => 'api'], ['transport' => 'API'], ['transport' => ' api ']] as $config) {
            $capabilities = (new SesProvider($config))->capabilities();

            self::assertFalse($capabilities->customHeaders, 'API transport, custom headers');
            self::assertFalse($capabilities->unsubscribeHeaders, 'API transport, unsubscribe headers');
            self::assertFalse($capabilities->echoesHeaders, 'nothing to echo when nothing was sent');
            self::assertStringContainsString('List-Unsubscribe', $capabilities->echoNote);
            self::assertStringContainsString('HTTPS or SMTP', $capabilities->echoNote);
        }
    }

    /** HTTPS and SMTP send the whole message, so the headers are the message. HTTPS is what an unset transport means. */
    public function testTheOtherTwoTransportsCarryEverything(): void
    {
        self::assertSame('https', (new SesProvider())->transport(), 'nothing chosen is HTTPS');
        self::assertSame('https', (new SesProvider(['transport' => '']))->transport(), 'blank is HTTPS');

        foreach (['https', 'smtp'] as $transport) {
            $capabilities = (new SesProvider(['transport' => $transport]))->capabilities();

            self::assertTrue($capabilities->customHeaders, $transport);
            self::assertTrue($capabilities->unsubscribeHeaders, $transport);
            self::assertTrue($capabilities->echoesHeaders, $transport);
            self::assertStringContainsString('Include original', $capabilities->echoNote, $transport);
        }
    }

    /** Amazon's DNS facts: an SPF host, a DKIM zone, and no return-path zone, on purpose. */
    public function testTheDomainFactsAreAmazons(): void
    {
        $facts = (new SesProvider())->domain();

        self::assertSame('amazonses.com', $facts->spfInclude);
        self::assertSame('dkim.amazonses.com', $facts->dkimZone);
        self::assertNull(
            $facts->returnPathZone,
            'SES aligns a custom return path with an MX record on the store\'s own subdomain, not a CNAME into a zone of Amazon\'s',
        );
        self::assertNotNull($facts->lookup);
    }

    /**
     * `GetEmailIdentity` answers the three DKIM tokens Amazon generated and the
     * custom MAIL FROM domain, which is exactly what a deliverability check
     * would otherwise have to ask a person for.
     */
    public function testTheDomainLookupReadsTheSelectorsAndTheReturnPath(): void
    {
        $http = (new FakeHttp())->answer('/v2/email/identities/example.com', 200, [
            'IdentityType' => 'DOMAIN',
            'DkimAttributes' => ['SigningEnabled' => true, 'Tokens' => ['tokenone', 'tokentwo', 'tokenthree']],
            'MailFromAttributes' => ['MailFromDomain' => 'mail.example.com', 'MailFromDomainStatus' => 'SUCCESS'],
        ]);

        $answer = self::provider($http)->domain()->ask('Example.COM.');

        self::assertSame(['tokenone', 'tokentwo', 'tokenthree'], $answer['selectors']);
        self::assertSame(['mail.example.com'], $answer['return_paths']);
        self::assertContains('GET https://email.us-east-1.amazonaws.com/v2/email/identities/example.com', $http->trace());
    }

    /**
     * The lookup never throws and never guesses.
     *
     * A revoked key, an identity in another region, an API having an outage and
     * a network with no route out are all the empty answer, and the caller falls
     * back to asking. A deliverability screen that fails because a third party
     * is having a bad morning is worse than one that says it could not find out.
     */
    public function testTheDomainLookupAnswersNothingRatherThanFailing(): void
    {
        $refused = (new FakeHttp())->answer('/v2/email/identities/', 404, [
            'message' => 'Email identity example.com does not exist.',
        ]);
        self::assertSame([], self::provider($refused)->domain()->ask('example.com'));

        $unreachable = (new FakeHttp())->unreachable();
        self::assertSame([], self::provider($unreachable)->domain()->ask('example.com'));

        // No credentials at all, so nothing is even attempted.
        $unasked = new FakeHttp();
        self::assertSame([], (new SesProvider([], null, null, static fn (array $c): AwsApi => new AwsApi('', '', '', '', $unasked)))->domain()->ask('example.com'));
        self::assertSame([], $unasked->requests);

        // A domain that is not one is refused before a request is built, which
        // is what keeps a crafted value out of a signed URL.
        $crafted = new FakeHttp();
        self::assertSame([], self::provider($crafted)->domain()->ask('example.com/../../v2/email/identities'));
        self::assertSame([], $crafted->requests);
        self::assertSame([], self::provider($crafted)->domain()->ask(''));
    }

    /** Both halves of the contract are answered; neither is null for this provider. */
    public function testItReportsDeliveriesAndCanSetItselfUp(): void
    {
        $provider = new SesProvider();

        self::assertInstanceOf(DeliveryReports::class, $provider->reports());
        self::assertInstanceOf(WebhookSetup::class, $provider->setup());
    }

    /** The instructions name the screens, because "configure a webhook" is not instructions. */
    public function testTheInstructionsNameTheScreensAndTheStepEverybodyForgets(): void
    {
        $instructions = (new SesProvider())->instructions();

        self::assertStringContainsString('Configuration sets', $instructions);
        self::assertStringContainsString('Event destinations', $instructions);
        self::assertStringContainsString('Amazon SNS', $instructions);
        self::assertStringContainsString('X-SES-CONFIGURATION-SET', $instructions);
        self::assertStringContainsString('no event is ever published', $instructions);
    }

    /** None of the cheap methods makes a request, because every one of them runs on a settings screen. */
    public function testNothingDrawnOnAScreenTouchesTheNetwork(): void
    {
        $http = new FakeHttp();
        $provider = self::provider($http);

        $provider->engines();
        $provider->key();
        $provider->label();
        $provider->capabilities();
        $provider->reports();
        $provider->setup();
        $provider->domain();
        $provider->instructions();

        self::assertSame([], $http->requests);
    }

    // ------------------------------------------------------------- internals

    private static function provider(FakeHttp $http): SesProvider
    {
        return new SesProvider(
            ['region' => 'us-east-1', 'access_key' => 'AKIDEXAMPLE', 'secret_key' => 'a-secret'],
            null,
            $http,
            static fn (array $config): AwsApi => new AwsApi(
                trim((string)($config['region'] ?? '')),
                trim((string)($config['access_key'] ?? '')),
                trim((string)($config['secret_key'] ?? '')),
                '',
                $http,
                static fn (): int => 1756900000,
            ),
        );
    }
}
