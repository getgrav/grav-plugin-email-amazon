<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailAmazon\Tests\Unit;

use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\EmailAmazon\Aws\AwsApi;
use Grav\Plugin\EmailAmazon\Provider\SesSetup;
use Grav\Plugin\EmailAmazon\Tests\Support\FakeHttp;
use PHPUnit\Framework\TestCase;

/**
 * The six calls behind the delivery-reports button, against a fake API.
 *
 * SES has no webhooks. What it has is event publishing to an SNS topic, and the
 * chain from "a message bounced" to "a POST arrives at this store" is a
 * configuration set, an event destination, a topic, a topic policy and a
 * subscription. Every one of them can be refused separately and every refusal
 * needs a different IAM action added, so the tests here are about the sequence
 * and about the sentence a merchant reads rather than about any one request.
 *
 * The one that would otherwise be missed is the topic policy: without it SES
 * accepts the event destination, reports success, and then publishes nothing at
 * all. It has its own test because it is invisible when it goes wrong.
 */
final class SesSetupTest extends TestCase
{
    private const URL = 'https://shop.example.com/newsletter/webhook/ses/a-long-secret';
    private const TOPIC = 'arn:aws:sns:us-east-1:123456789012:grav-email-events';

    public function testTheWholeChainIsSetUpAndSaidPlainly(): void
    {
        $http = self::happyPath();
        $result = self::wiring($http)->create(self::URL, [Event::DELIVERED, Event::BOUNCED, Event::COMPLAINED], self::config());

        self::assertTrue($result->ok, $result->message);
        self::assertSame(self::TOPIC, $result->webhookId);

        self::assertSame([
            'POST https://sns.us-east-1.amazonaws.com/',
            'POST https://sns.us-east-1.amazonaws.com/',
            'POST https://sns.us-east-1.amazonaws.com/',
            'POST https://sns.us-east-1.amazonaws.com/',
            'POST https://sns.us-east-1.amazonaws.com/',
            'GET https://email.us-east-1.amazonaws.com/v2/email/configuration-sets/grav-email',
            'GET https://email.us-east-1.amazonaws.com/v2/email/configuration-sets/grav-email/event-destinations',
            'POST https://email.us-east-1.amazonaws.com/v2/email/configuration-sets/grav-email/event-destinations',
        ], $http->trace());

        self::assertTrue($http->sent('Action=CreateTopic'));
        self::assertTrue($http->sent('Action=SetTopicAttributes'));
        self::assertTrue($http->sent('Action=Subscribe'));
        self::assertTrue($http->sent('DELIVERY'));

        // No identity was named, so the button says what is left rather than
        // pretending the job is done.
        self::assertStringContainsString('One thing is left', $result->message);
        self::assertStringContainsString('X-SES-CONFIGURATION-SET', $result->message);
    }

    /**
     * The topic's policy has to let SES publish, and this is the step that is
     * invisible when it is missing.
     */
    public function testSesIsGivenPermissionToPublishToTheTopic(): void
    {
        $http = self::happyPath();
        self::wiring($http)->create(self::URL, [Event::BOUNCED], self::config());

        $policy = null;
        foreach ($http->requests as $request) {
            if (str_contains($request['body'], 'Action=SetTopicAttributes')) {
                parse_str($request['body'], $fields);
                $policy = json_decode((string)($fields['AttributeValue'] ?? ''), true);
            }
        }

        self::assertIsArray($policy, 'the topic policy was never set');

        $sids = array_column($policy['Statement'], 'Sid');
        self::assertContains(SesSetup::POLICY_SID, $sids);
        self::assertContains('AwsDefaultOwnerStatement', $sids, 'whatever was already on the topic stays on it');

        $ours = null;
        foreach ($policy['Statement'] as $statement) {
            if (($statement['Sid'] ?? '') === SesSetup::POLICY_SID) {
                $ours = $statement;
            }
        }

        self::assertSame('ses.amazonaws.com', $ours['Principal']['Service']);
        self::assertSame('SNS:Publish', $ours['Action']);
        self::assertSame(self::TOPIC, $ours['Resource']);
        self::assertSame('123456789012', $ours['Condition']['StringEquals']['AWS:SourceAccount']);
    }

    /** Pressing the button twice does not leave two subscriptions or two statements. */
    public function testPressingTheButtonTwiceChangesNothing(): void
    {
        $http = (new FakeHttp())
            ->answer('Action=CreateTopic', 200, self::createTopicXml())
            ->answer('Action=GetTopicAttributes', 200, self::attributesXml(self::policyWithOurs()))
            ->answer('Action=SetTopicAttributes', 200, self::plainXml('SetTopicAttributes'))
            ->answer('Action=ListSubscriptionsByTopic', 200, self::subscriptionsXml(self::URL))
            ->answer('/v2/email/configuration-sets/grav-email', 200, ['ConfigurationSetName' => 'grav-email'])
            ->answer('/event-destinations', 200, ['EventDestinations' => [['Name' => SesSetup::DESTINATION]]])
            ->answer('PUT https://email', 200, []);

        $result = self::wiring($http)->create(self::URL, [Event::BOUNCED], self::config());

        self::assertTrue($result->ok, $result->message);
        self::assertFalse($http->sent('Action=Subscribe'), 'this address was already subscribed');
        self::assertStringContainsString('already subscribed', $result->message);

        // The event destination was updated in place rather than added again.
        self::assertContains(
            'PUT https://email.us-east-1.amazonaws.com/v2/email/configuration-sets/grav-email/event-destinations/'
            . SesSetup::DESTINATION,
            $http->trace(),
        );

        // And our statement replaced the one that was already there rather than
        // being appended beside it.
        foreach ($http->requests as $request) {
            if (str_contains($request['body'], 'Action=SetTopicAttributes')) {
                parse_str($request['body'], $fields);
                $policy = json_decode((string)($fields['AttributeValue'] ?? ''), true);

                self::assertSame(
                    [SesSetup::POLICY_SID],
                    array_values(array_filter(
                        array_column($policy['Statement'], 'Sid'),
                        static fn (string $sid): bool => $sid === SesSetup::POLICY_SID,
                    )),
                );
            }
        }
    }

    /**
     * A subscription this store left under an older secret is unsubscribed
     * before the new address is subscribed.
     *
     * An SNS subscription's endpoint cannot be edited — there is no call for it
     * — so the old one has to go, or the topic keeps posting at an address that
     * answers 404 and the merchant is left counting subscriptions in the
     * console.
     */
    public function testASubscriptionOnAnOlderSecretIsRemovedBeforeTheNewOneIsMade(): void
    {
        $old = 'arn:aws:sns:us-east-1:123456789012:grav-email-events:1111';

        $http = (new FakeHttp())
            ->answer('Action=CreateTopic', 200, self::createTopicXml())
            ->answer('Action=GetTopicAttributes', 200, self::attributesXml(self::defaultPolicy()))
            ->answer('Action=SetTopicAttributes', 200, self::plainXml('SetTopicAttributes'))
            ->answer('Action=ListSubscriptionsByTopic', 200, self::subscriptionsXmlFor([
                'https://somebody.else/' => 'arn:aws:sns:us-east-1:123456789012:grav-email-events:9999',
                'https://shop.example.com/newsletter/webhook/ses/the-old-secret' => $old,
            ]))
            ->answer('Action=Unsubscribe', 200, self::plainXml('Unsubscribe'))
            ->answer('Action=Subscribe', 200, self::subscribeXml())
            ->answer('GET https://email.us-east-1.amazonaws.com/v2/email/configuration-sets/grav-email', 200, [])
            ->answer('/event-destinations', 200, ['EventDestinations' => []])
            ->answer('POST https://email', 200, []);

        $result = self::wiring($http)->create(self::URL, [Event::BOUNCED], self::config());

        self::assertTrue($result->ok, $result->message);
        self::assertStringContainsString('older secret', $result->message);
        self::assertStringContainsString('has been removed', $result->message);

        $unsubscribed = [];
        $subscribed = [];
        foreach ($http->requests as $request) {
            parse_str($request['body'], $fields);

            if (($fields['Action'] ?? '') === 'Unsubscribe') {
                $unsubscribed[] = (string)$fields['SubscriptionArn'];
            }

            if (($fields['Action'] ?? '') === 'Subscribe') {
                $subscribed[] = (string)$fields['Endpoint'];
            }
        }

        self::assertSame([$old], $unsubscribed, 'somebody else\'s subscription is not this plugin\'s to remove');
        self::assertSame([self::URL], $subscribed);
    }

    /**
     * A key that may not unsubscribe still gets the store subscribed, and is
     * told in plain words what to add.
     *
     * The leftover subscription posts at an address that answers 404 and breaks
     * nothing, so refusing the whole setup over it would leave a merchant with
     * working delivery reports and a red message.
     */
    public function testAKeyThatCannotUnsubscribeSaysSoAndCarriesOn(): void
    {
        $http = (new FakeHttp())
            ->answer('Action=CreateTopic', 200, self::createTopicXml())
            ->answer('Action=GetTopicAttributes', 200, self::attributesXml(self::defaultPolicy()))
            ->answer('Action=SetTopicAttributes', 200, self::plainXml('SetTopicAttributes'))
            ->answer('Action=ListSubscriptionsByTopic', 200, self::subscriptionsXml(
                'https://shop.example.com/newsletter/webhook/ses/the-old-secret'
            ))
            ->answer('Action=Unsubscribe', 403, self::errorXml(
                'AuthorizationError',
                'User: arn:aws:iam::123456789012:user/grav is not authorized to perform: SNS:Unsubscribe',
            ))
            ->answer('Action=Subscribe', 200, self::subscribeXml())
            ->answer('GET https://email.us-east-1.amazonaws.com/v2/email/configuration-sets/grav-email', 200, [])
            ->answer('/event-destinations', 200, ['EventDestinations' => []])
            ->answer('POST https://email', 200, []);

        $result = self::wiring($http)->create(self::URL, [Event::BOUNCED], self::config());

        self::assertTrue($result->ok, $result->message);
        self::assertStringContainsString('older secret', $result->message);
        self::assertStringContainsString('sns:Unsubscribe', $result->message);
        self::assertTrue($http->sent('Action=Subscribe'), 'the store is still subscribed');
    }

    /**
     * A subscription still waiting to be confirmed is named rather than acted
     * on, because Amazon gives it no ARN to unsubscribe with and deletes it
     * itself after three days.
     */
    public function testAnUnconfirmedOlderSubscriptionIsSaidRatherThanRemoved(): void
    {
        $http = (new FakeHttp())
            ->answer('Action=CreateTopic', 200, self::createTopicXml())
            ->answer('Action=GetTopicAttributes', 200, self::attributesXml(self::defaultPolicy()))
            ->answer('Action=SetTopicAttributes', 200, self::plainXml('SetTopicAttributes'))
            ->answer('Action=ListSubscriptionsByTopic', 200, self::subscriptionsXmlFor([
                'https://shop.example.com/newsletter/webhook/ses/the-old-secret' => 'PendingConfirmation',
            ]))
            ->answer('Action=Subscribe', 200, self::subscribeXml())
            ->answer('GET https://email.us-east-1.amazonaws.com/v2/email/configuration-sets/grav-email', 200, [])
            ->answer('/event-destinations', 200, ['EventDestinations' => []])
            ->answer('POST https://email', 200, []);

        $result = self::wiring($http)->create(self::URL, [Event::BOUNCED], self::config());

        self::assertTrue($result->ok, $result->message);
        self::assertFalse($http->sent('Action=Unsubscribe'), 'Amazon will not remove one of those');
        self::assertStringContainsString('waiting to be confirmed', $result->message);
        self::assertTrue($http->sent('Action=Subscribe'));
    }

    /** A configuration set that is not there yet is created. */
    public function testAMissingConfigurationSetIsCreated(): void
    {
        $http = (new FakeHttp())
            ->answer('Action=CreateTopic', 200, self::createTopicXml())
            ->answer('Action=GetTopicAttributes', 200, self::attributesXml(self::defaultPolicy()))
            ->answer('Action=SetTopicAttributes', 200, self::plainXml('SetTopicAttributes'))
            ->answer('Action=ListSubscriptionsByTopic', 200, self::subscriptionsXml('https://somebody.else/'))
            ->answer('Action=Subscribe', 200, self::subscribeXml())
            ->answer('GET https://email.us-east-1.amazonaws.com/v2/email/configuration-sets/grav-email', 404, [
                'message' => 'Configuration set grav-email does not exist.',
                '__type' => 'com.amazonaws.ses#NotFoundException',
            ])
            ->answer('POST https://email.us-east-1.amazonaws.com/v2/email/configuration-sets', 200, [])
            ->answer('/event-destinations', 200, ['EventDestinations' => []])
            ->answer('POST https://email', 200, []);

        $result = self::wiring($http)->create(self::URL, [Event::BOUNCED], self::config());

        self::assertTrue($result->ok, $result->message);
        self::assertTrue($http->sent('"ConfigurationSetName":"grav-email"'));
    }

    /** Naming a sending identity makes the configuration set that identity's default. */
    public function testNamingAnIdentityMakesTheConfigurationSetItsDefault(): void
    {
        $http = self::happyPath();
        $http->answer('PUT https://email.us-east-1.amazonaws.com/v2/email/identities/example.com/configuration-set', 200, []);

        $result = self::wiring($http)->create(self::URL, [Event::BOUNCED], self::config(['identity' => 'example.com']));

        self::assertTrue($result->ok, $result->message);
        self::assertStringContainsString('no header to set', $result->message);
        self::assertContains(
            'PUT https://email.us-east-1.amazonaws.com/v2/email/identities/example.com/configuration-set',
            $http->trace(),
        );
    }

    /**
     * A key that is refused gets Amazon's own sentence back, and which step it
     * was refused on.
     *
     * "AccessDenied" on the topic policy and "AccessDenied" on the event
     * destination need two different IAM actions added, and a merchant cannot
     * tell them apart from a status code.
     */
    public function testARefusedKeyComesBackInAmazonsOwnWords(): void
    {
        $http = (new FakeHttp())->answer('Action=CreateTopic', 403, self::errorXml(
            'AuthorizationError',
            'User: arn:aws:iam::123456789012:user/grav is not authorized to perform: SNS:CreateTopic',
        ));

        $result = self::wiring($http)->create(self::URL, [Event::BOUNCED], self::config());

        self::assertFalse($result->ok);
        self::assertStringContainsString('create the SNS topic grav-email-events', $result->message);
        self::assertStringContainsString('is not authorized to perform: SNS:CreateTopic', $result->message);
        self::assertStringContainsString('not allowed to do this yet', $result->message);
    }

    /** A refusal part way through stops there rather than carrying on. */
    public function testARefusalStopsTheChain(): void
    {
        $http = (new FakeHttp())
            ->answer('Action=CreateTopic', 200, self::createTopicXml())
            ->answer('Action=GetTopicAttributes', 200, self::attributesXml(self::defaultPolicy()))
            ->answer('Action=SetTopicAttributes', 403, self::errorXml('AuthorizationError', 'not authorized to perform: SNS:SetTopicAttributes'));

        $result = self::wiring($http)->create(self::URL, [Event::BOUNCED], self::config());

        self::assertFalse($result->ok);
        self::assertStringContainsString('let SES publish', $result->message);
        self::assertFalse($http->sent('Action=Subscribe'), 'nothing after the failed step should run');
    }

    /** A key that may subscribe but may not list still gets a subscription. */
    public function testAKeyThatCannotListSubscriptionsStillSubscribes(): void
    {
        $http = (new FakeHttp())
            ->answer('Action=CreateTopic', 200, self::createTopicXml())
            ->answer('Action=GetTopicAttributes', 200, self::attributesXml(self::defaultPolicy()))
            ->answer('Action=SetTopicAttributes', 200, self::plainXml('SetTopicAttributes'))
            ->answer('Action=ListSubscriptionsByTopic', 403, self::errorXml('AuthorizationError', 'not authorized'))
            ->answer('Action=Subscribe', 200, self::subscribeXml())
            ->answer('GET https://email.us-east-1.amazonaws.com/v2/email/configuration-sets/grav-email', 200, [])
            ->answer('/event-destinations', 200, ['EventDestinations' => []])
            ->answer('POST https://email', 200, []);

        $result = self::wiring($http)->create(self::URL, [Event::BOUNCED], self::config());

        self::assertTrue($result->ok, $result->message);
        self::assertTrue($http->sent('Action=Subscribe'));
    }

    /** A network that does not get through is a sentence, not an exception. */
    public function testANetworkFailureIsASentence(): void
    {
        $http = (new FakeHttp())->unreachable('Could not resolve host: sns.us-east-1.amazonaws.com');

        $result = self::wiring($http)->create(self::URL, [Event::BOUNCED], self::config());

        self::assertFalse($result->ok);
        self::assertStringContainsString('did not get through', $result->message);
        self::assertStringContainsString('Could not resolve host', $result->message);
    }

    /** Nothing is attempted at all until the key, the secret and the region are filled in. */
    public function testAnEmptyKeyIsRefusedBeforeAnythingIsSent(): void
    {
        $http = new FakeHttp();

        $result = self::wiring($http)->create(self::URL, [Event::BOUNCED], ['region' => 'us-east-1']);

        self::assertFalse($result->ok);
        self::assertStringContainsString('access key', $result->message);
        self::assertSame([], $http->requests);
    }

    /** Amazon only posts to https, so a webhook URL that is not one is refused here. */
    public function testAnAddressThatIsNotHttpsIsRefused(): void
    {
        $http = new FakeHttp();

        $result = self::wiring($http)->create('http://shop.example.com/hook', [Event::BOUNCED], self::config());

        self::assertFalse($result->ok);
        self::assertStringContainsString('https', $result->message);
        self::assertSame([], $http->requests);
    }

    /** The contract's event words as Amazon's, and a sensible answer for an empty list. */
    public function testTheEventNamesAreTranslatedToAmazons(): void
    {
        self::assertSame(
            ['DELIVERY', 'BOUNCE', 'COMPLAINT', 'OPEN', 'CLICK', 'REJECT'],
            SesSetup::matching(Event::TYPES),
        );

        self::assertSame(['BOUNCE', 'COMPLAINT'], SesSetup::matching([Event::BOUNCED, Event::COMPLAINED]));
        self::assertSame(['BOUNCE'], SesSetup::matching([Event::BOUNCED, 'BOUNCED', 'something-else']));
        self::assertSame(['DELIVERY', 'BOUNCE', 'COMPLAINT', 'REJECT'], SesSetup::matching([]));
        self::assertSame(['DELIVERY', 'BOUNCE', 'COMPLAINT', 'REJECT'], SesSetup::matching(['nothing amazon knows']));
    }

    /** The account id is the fifth part of a topic ARN and nothing else. */
    public function testTheAccountIdIsReadOutOfTheTopicArn(): void
    {
        self::assertSame('123456789012', SesSetup::accountIn(self::TOPIC));
        self::assertSame('', SesSetup::accountIn('nonsense'));
    }

    /** The permissions sentence names the actions, because "webhooks" is called something else here. */
    public function testThePermissionsSentenceNamesTheIamActions(): void
    {
        $needed = (new SesSetup())->permissionsNeeded();

        foreach ([
            'sns:CreateTopic',
            'sns:SetTopicAttributes',
            'sns:Subscribe',
            'sns:Unsubscribe',
            'ses:CreateConfigurationSet',
            'ses:CreateConfigurationSetEventDestination',
            'ses:PutEmailIdentityConfigurationSetAttributes',
        ] as $action) {
            self::assertStringContainsString($action, $needed);
        }
    }

    // ------------------------------------------------------------- internals

    private static function wiring(FakeHttp $http): SesSetup
    {
        return new SesSetup(static fn (array $config): AwsApi => new AwsApi(
            trim((string)($config['region'] ?? '')),
            trim((string)($config['access_key'] ?? '')),
            trim((string)($config['secret_key'] ?? '')),
            '',
            $http,
            static fn (): int => 1756900000,
        ));
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private static function config(array $extra = []): array
    {
        return $extra + [
            'region' => 'us-east-1',
            'access_key' => 'AKIDEXAMPLE',
            'secret_key' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
        ];
    }

    private static function happyPath(): FakeHttp
    {
        return (new FakeHttp())
            ->answer('Action=CreateTopic', 200, self::createTopicXml())
            ->answer('Action=GetTopicAttributes', 200, self::attributesXml(self::defaultPolicy()))
            ->answer('Action=SetTopicAttributes', 200, self::plainXml('SetTopicAttributes'))
            ->answer('Action=ListSubscriptionsByTopic', 200, self::subscriptionsXml('https://somebody.else/'))
            ->answer('Action=Subscribe', 200, self::subscribeXml())
            ->answer('GET https://email.us-east-1.amazonaws.com/v2/email/configuration-sets/grav-email', 200, [
                'ConfigurationSetName' => 'grav-email',
            ])
            ->answer('/event-destinations', 200, ['EventDestinations' => []])
            ->answer('POST https://email', 200, []);
    }

    private static function createTopicXml(): string
    {
        return '<CreateTopicResponse xmlns="http://sns.amazonaws.com/doc/2010-03-31/">'
            . '<CreateTopicResult><TopicArn>' . self::TOPIC . '</TopicArn></CreateTopicResult>'
            . '<ResponseMetadata><RequestId>a-request-id</RequestId></ResponseMetadata>'
            . '</CreateTopicResponse>';
    }

    private static function subscribeXml(): string
    {
        return '<SubscribeResponse xmlns="http://sns.amazonaws.com/doc/2010-03-31/">'
            . '<SubscribeResult><SubscriptionArn>pending confirmation</SubscriptionArn></SubscribeResult>'
            . '</SubscribeResponse>';
    }

    private static function subscriptionsXml(string $endpoint): string
    {
        return self::subscriptionsXmlFor([
            $endpoint => 'arn:aws:sns:us-east-1:123456789012:grav-email-events:abcd',
        ]);
    }

    /** @param array<string, string> $members endpoint => subscription ARN */
    private static function subscriptionsXmlFor(array $members): string
    {
        $rows = '';
        foreach ($members as $endpoint => $arn) {
            $rows .= '<member>'
                . '<TopicArn>' . self::TOPIC . '</TopicArn>'
                . '<Protocol>https</Protocol>'
                . '<SubscriptionArn>' . $arn . '</SubscriptionArn>'
                . '<Endpoint>' . $endpoint . '</Endpoint>'
                . '</member>';
        }

        return '<ListSubscriptionsByTopicResponse xmlns="http://sns.amazonaws.com/doc/2010-03-31/">'
            . '<ListSubscriptionsByTopicResult><Subscriptions>' . $rows . '</Subscriptions></ListSubscriptionsByTopicResult>'
            . '</ListSubscriptionsByTopicResponse>';
    }

    private static function attributesXml(string $policy): string
    {
        return '<GetTopicAttributesResponse xmlns="http://sns.amazonaws.com/doc/2010-03-31/">'
            . '<GetTopicAttributesResult><Attributes>'
            . '<entry><key>Owner</key><value>123456789012</value></entry>'
            . '<entry><key>Policy</key><value>' . htmlspecialchars($policy, \ENT_XML1) . '</value></entry>'
            . '</Attributes></GetTopicAttributesResult>'
            . '</GetTopicAttributesResponse>';
    }

    private static function plainXml(string $action): string
    {
        return '<' . $action . 'Response xmlns="http://sns.amazonaws.com/doc/2010-03-31/">'
            . '<ResponseMetadata><RequestId>a-request-id</RequestId></ResponseMetadata>'
            . '</' . $action . 'Response>';
    }

    private static function errorXml(string $code, string $message): string
    {
        return '<ErrorResponse xmlns="http://sns.amazonaws.com/doc/2010-03-31/">'
            . '<Error><Type>Sender</Type><Code>' . $code . '</Code>'
            . '<Message>' . htmlspecialchars($message, \ENT_XML1) . '</Message></Error>'
            . '<RequestId>a-request-id</RequestId></ErrorResponse>';
    }

    private static function defaultPolicy(): string
    {
        return (string)json_encode([
            'Version' => '2012-10-17',
            'Id' => '__default_policy_ID',
            'Statement' => [[
                'Sid' => 'AwsDefaultOwnerStatement',
                'Effect' => 'Allow',
                'Principal' => ['AWS' => '*'],
                'Action' => 'SNS:Publish',
                'Resource' => self::TOPIC,
            ]],
        ]);
    }

    private static function policyWithOurs(): string
    {
        $policy = json_decode(self::defaultPolicy(), true);
        $policy['Statement'][] = [
            'Sid' => SesSetup::POLICY_SID,
            'Effect' => 'Allow',
            'Principal' => ['Service' => 'ses.amazonaws.com'],
            'Action' => 'SNS:Publish',
            'Resource' => self::TOPIC,
        ];

        return (string)json_encode($policy);
    }
}
