<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailAmazon\Provider;

use Grav\Plugin\Email\Providers\Capabilities;
use Grav\Plugin\Email\Providers\DeliveryReports;
use Grav\Plugin\Email\Providers\DomainFacts;
use Grav\Plugin\Email\Providers\Provider;
use Grav\Plugin\Email\Providers\WebhookSetup;
use Grav\Plugin\EmailAmazon\Aws\AwsApi;

/**
 * Everything Amazon SES knows about itself, answered by the plugin that talks
 * to it.
 *
 * Registered on the Email plugin's `onEmailProviders` event. Before this
 * release the answers lived in whatever add-on happened to need them first —
 * the SES webhook parser, the table of Amazon's SPF and DKIM hosts and the
 * knowledge that the API transport drops custom headers were all in KahunaCart's
 * newsletter add-on, which meant adding a provider meant editing that add-on.
 * They are here now because this is the only code in the world that knows what
 * this plugin does to a message.
 *
 * ## The three answers that depend on a setting rather than on Amazon
 *
 * This plugin sends in one of three ways and they are not equivalent, which is
 * the single most useful thing on this page:
 *
 * - **`api`** builds Amazon's `SendEmail` request out of the parts of the
 *   message — sender, recipients, subject, text body, HTML body — and sends
 *   that. Everything else about the message is left behind, including every
 *   custom header and including `List-Unsubscribe`. Nothing warns about it and
 *   the mail goes out looking fine.
 * - **`https`** sends the same message as raw MIME through the same API, so the
 *   headers are the message and all of them arrive.
 * - **`smtp`** is SMTP, where the headers are the first half of the message.
 *
 * So {@see capabilities()} reads the plugin's own `transport` setting and says
 * so. A bulk sender on the `api` transport has no unsubscribe button in Gmail
 * and no way to tie a bounce to the message it came from, and a screen that can
 * say that before forty thousand messages go out is worth more than one that
 * lists features.
 *
 * ## What is null and why
 *
 * Nothing. This provider reports deliveries and can set itself up, so both
 * {@see reports()} and {@see setup()} answer an object. What has no answer is
 * the return-path zone in {@see domain()}: SES's custom MAIL FROM is a
 * subdomain of the store's own domain with an MX record pointing at Amazon,
 * rather than a CNAME into a zone of Amazon's, so there is no zone to name. The
 * lookup answers the actual value where the key may read it.
 */
final class SesProvider implements Provider
{
    /** The key this provider is known by in routes and config. */
    public const KEY = 'ses';

    /**
     * The engines this answers for.
     *
     * `amazon` is what this plugin has registered on `onEmailEngines` since it
     * existed and is what a store's `email.yaml` says. `ses` is the name the
     * transport is known by everywhere else, including in the DSN this plugin
     * builds, and recognising it costs nothing next to failing to recognise the
     * one somebody picked.
     *
     * @var list<string>
     */
    public const ENGINES = ['amazon', 'ses'];

    /** Amazon's own transports, as this plugin's config spells them. */
    public const TRANSPORT_API = 'api';
    public const TRANSPORT_HTTPS = 'https';
    public const TRANSPORT_SMTP = 'smtp';

    /** Where an SPF record has to end up sending people. */
    public const SPF = 'amazonses.com';

    /** The zone a DKIM selector CNAMEs into. */
    public const DKIM_ZONE = 'dkim.amazonses.com';

    private readonly ?CertificateStore $certificates;

    /** @var (callable(array<string, mixed>): AwsApi) */
    private $api;

    /**
     * @param array<string, mixed> $config this plugin's own config block
     * @param CertificateStore|null $certificates where Amazon's SNS signing
     *        certificates are kept; null in a test that is not about them
     * @param \Grav\Plugin\EmailAmazon\Http\Http|null $http how a certificate is
     *        fetched; null makes every signature refuse
     * @param (callable(array<string, mixed>): AwsApi)|null $api how an API
     *        client is built, for the setup and the domain lookup
     */
    public function __construct(
        private readonly array $config = [],
        ?CertificateStore $certificates = null,
        private readonly ?\Grav\Plugin\EmailAmazon\Http\Http $http = null,
        ?callable $api = null,
    ) {
        $this->certificates = $certificates;
        $this->api = $api ?? static fn (array $config): AwsApi => new AwsApi(
            trim((string)($config['region'] ?? '')),
            trim((string)($config['access_key'] ?? '')),
            trim((string)($config['secret_key'] ?? '')),
            trim((string)($config['session_token'] ?? '')),
        );
    }

    public function engines(): array
    {
        return self::ENGINES;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Amazon SES';
    }

    public function capabilities(): Capabilities
    {
        if ($this->transport() === self::TRANSPORT_API) {
            return new Capabilities(
                customHeaders: false,
                unsubscribeHeaders: false,
                echoesHeaders: false,
                signsWebhooks: true,
                echoNote: 'This plugin is set to the API transport, which sends the parts of a message rather than '
                    . 'the message, so every custom header is left behind — List-Unsubscribe included. Switch the '
                    . 'Transport setting on this plugin to HTTPS or SMTP to send the whole message.',
            );
        }

        return new Capabilities(
            customHeaders: true,
            unsubscribeHeaders: true,
            echoesHeaders: true,
            // SNS signs every notification with a certificate it publishes; the
            // merchant pastes no key, so a screen cannot work this out from keys.
            signsWebhooks: true,
            echoNote: 'SES hands headers back in mail.headers on every event-publishing notification. A feedback '
                . 'notification sent straight from a verified identity carries them only once "Include original '
                . 'email headers" is ticked on that identity, once per feedback type, on the identity\'s page in '
                . 'the SES console.',
        );
    }

    public function reports(): ?DeliveryReports
    {
        return new SesReports($this->certificates, $this->http);
    }

    public function setup(): ?WebhookSetup
    {
        return new SesSetup($this->api);
    }

    public function domain(): DomainFacts
    {
        return new DomainFacts(
            spfInclude: self::SPF,
            dkimZone: self::DKIM_ZONE,
            // SES aligns a custom return path with an MX record on a subdomain
            // of the store's own domain, not with a CNAME into a zone of
            // Amazon's, so there is no zone to name here. The lookup answers
            // the domain that is actually configured.
            returnPathZone: null,
            lookup: fn (string $domain): array => $this->ask($domain),
        );
    }

    public function instructions(): string
    {
        return 'In the SES console, open Configuration sets and either create one or open the one you send with. '
            . 'On its Event destinations tab, add a destination of type Amazon SNS, pick or create a topic, and tick '
            . 'Delivery, Bounce, Complaint and Reject. Then open that topic in the SNS console, create a '
            . 'subscription with protocol HTTPS and paste this store\'s webhook address as the endpoint — Amazon '
            . 'posts a confirmation to it straight away and this store answers it. Last, either set the '
            . 'configuration set as the default on your verified domain, under Identities, or send with an '
            . 'X-SES-CONFIGURATION-SET header naming it; without that step the destination is set up and no event '
            . 'is ever published.';
    }

    // ------------------------------------------------------------- internals

    /**
     * Which of the three ways this plugin is set to send. Its own default is
     * HTTPS, which puts the whole message through Amazon's API so every header
     * arrives — the API transport sends the parts of a message instead and
     * leaves custom headers behind, List-Unsubscribe included.
     */
    public function transport(): string
    {
        $transport = strtolower(trim((string)($this->config['transport'] ?? self::TRANSPORT_HTTPS)));

        return $transport === '' ? self::TRANSPORT_HTTPS : $transport;
    }

    /**
     * What Amazon says this domain's DKIM selectors and return path actually
     * are.
     *
     * `GetEmailIdentity` answers the three DKIM tokens Amazon generated for the
     * domain, which are the selector halves of the `<token>._domainkey.<domain>`
     * CNAMEs a store is asked to publish, and the custom MAIL FROM domain where
     * one is configured. A store that has set SES up at all has already been
     * told these; asking the key that sends the mail is better than asking the
     * person.
     *
     * Never throws, as the contract requires. A revoked key, a region with no
     * such identity, an API having an outage and a network with no route out
     * are all the empty answer, and the caller falls back to asking.
     *
     * @return array{selectors?: list<string>, return_paths?: list<string>}
     */
    private function ask(string $domain): array
    {
        $domain = strtolower(trim($domain, " \t\n\r\0\x0B."));
        if ($domain === '' || preg_match('/^[a-z0-9.\-]+$/', $domain) !== 1) {
            return [];
        }

        try {
            $api = ($this->api)($this->config);
            if (!$api->ready()) {
                return [];
            }

            $answer = $api->ses('GET', ['v2', 'email', 'identities', $domain]);
        } catch (\Throwable) {
            return [];
        }

        if (!$answer->ok) {
            return [];
        }

        $selectors = [];
        $tokens = $answer->data['DkimAttributes']['Tokens'] ?? null;
        if (\is_array($tokens)) {
            foreach ($tokens as $token) {
                $token = is_scalar($token) ? trim((string)$token) : '';
                if ($token !== '') {
                    $selectors[] = $token;
                }
            }
        }

        $returnPaths = [];
        $mailFrom = $answer->data['MailFromAttributes']['MailFromDomain'] ?? null;
        if (is_scalar($mailFrom) && trim((string)$mailFrom) !== '') {
            $returnPaths[] = strtolower(trim((string)$mailFrom, " \t\n\r\0\x0B."));
        }

        return [
            'selectors' => array_values(array_unique($selectors)),
            'return_paths' => array_values(array_unique($returnPaths)),
        ];
    }
}
