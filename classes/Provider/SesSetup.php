<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailAmazon\Provider;

use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\Email\Providers\SetupResult;
use Grav\Plugin\Email\Providers\WebhookSetup;
use Grav\Plugin\EmailAmazon\Aws\AwsApi;
use Grav\Plugin\EmailAmazon\Aws\AwsAnswer;

/**
 * Delivery reports set up from the access key the merchant already pasted in.
 *
 * Every other provider in this family has one call behind the button: post a
 * URL to a webhooks endpoint and read back an id. Amazon has six, because SES
 * does not have webhooks at all. What it has is *event publishing*, and the
 * chain from "a message bounced" to "a POST arrives at this store" is:
 *
 * 1. An **SNS topic** exists. `CreateTopic` is idempotent — a topic that is
 *    already there answers with its own ARN and nothing changes — so this is
 *    safe to press twice.
 * 2. That topic's **policy lets SES publish to it**. This is the step everybody
 *    forgets, including the merchant following a blog post: without it SES
 *    accepts the event destination, reports success, and then silently
 *    publishes nothing. The statement added is the narrowest one that works —
 *    the `ses.amazonaws.com` service principal, `SNS:Publish`, this topic only,
 *    and only for events from this account.
 * 3. The store's **URL is subscribed** to the topic over HTTPS. Amazon then
 *    posts a `SubscriptionConfirmation` to it, which {@see SesReports} reads
 *    and the caller confirms; nothing else arrives until it has.
 * 4. A **configuration set** exists, created when there is none.
 * 5. An **event destination** on that configuration set points at the topic
 *    for the event types asked for, updated in place when one is already there
 *    under the same name.
 * 6. The configuration set is the **default for the sending identity**, so
 *    ordinary messages publish events without every sender having to remember
 *    an `X-SES-CONFIGURATION-SET` header.
 *
 * Step six is the one with a real side effect outside this plugin: it makes
 * every message from that identity — including any sent by something other than
 * Grav — publish to this configuration set. It is only done when an identity is
 * named in the plugin's own settings, it is said plainly on the button's answer,
 * and it is skipped with a sentence rather than guessed at when the box is
 * empty.
 *
 * ## Nothing here runs unless the button is pressed
 *
 * Six network calls is six chances for somebody else's API to be slow, and none
 * of them may ever be on the path of a settings screen being drawn. The
 * contract says so and this is the class it is saying it about.
 *
 * ## Failures are sentences
 *
 * Each step answers with Amazon's own words when it is refused, prefixed with
 * which step it was, because "AccessDenied" on step two and "AccessDenied" on
 * step five need two different IAM actions added and a merchant cannot tell
 * them apart otherwise. {@see permissionsNeeded()} is the full list.
 */
final class SesSetup implements WebhookSetup
{
    /** What the topic is called when the settings do not say. */
    public const DEFAULT_TOPIC = 'grav-email-events';

    /** What the configuration set is called when the settings do not say. */
    public const DEFAULT_CONFIGURATION_SET = 'grav-email';

    /** The event destination's name on that configuration set. */
    public const DESTINATION = 'grav-email-sns';

    /** The statement id this plugin owns in the topic's policy, so pressing twice replaces rather than stacks. */
    public const POLICY_SID = 'GravEmailAllowSesPublish';

    /** @var array<string, string> the contract's event words to Amazon's `MatchingEventTypes` */
    public const EVENTS = [
        Event::DELIVERED => 'DELIVERY',
        Event::BOUNCED => 'BOUNCE',
        Event::COMPLAINED => 'COMPLAINT',
        Event::OPENED => 'OPEN',
        Event::CLICKED => 'CLICK',
        Event::DROPPED => 'REJECT',
    ];

    /** @var (callable(array<string, mixed>): AwsApi) */
    private $api;

    /**
     * @param (callable(array<string, mixed>): AwsApi)|null $api how an API
     *        client is built from the plugin's config; null is the real one,
     *        and a test hands over a closure with a fake HTTP client behind it
     */
    public function __construct(?callable $api = null)
    {
        $this->api = $api ?? static fn (array $config): AwsApi => new AwsApi(
            trim((string)($config['region'] ?? '')),
            trim((string)($config['access_key'] ?? '')),
            trim((string)($config['secret_key'] ?? '')),
            trim((string)($config['session_token'] ?? '')),
        );
    }

    public function permissionsNeeded(): string
    {
        return 'The access key needs an IAM policy allowing sns:CreateTopic, sns:GetTopicAttributes, '
            . 'sns:SetTopicAttributes, sns:Subscribe and sns:ListSubscriptionsByTopic on the topic, plus '
            . 'ses:GetConfigurationSet, ses:CreateConfigurationSet, ses:GetConfigurationSetEventDestinations, '
            . 'ses:CreateConfigurationSetEventDestination and ses:UpdateConfigurationSetEventDestination. '
            . 'Naming a sending identity also needs ses:PutEmailIdentityConfigurationSetAttributes, and reading '
            . 'a domain\'s DKIM records needs ses:GetEmailIdentity. In the AWS console these are set on the key\'s '
            . 'own user or role under IAM, not in SES.';
    }

    public function create(string $url, array $events, array $config): SetupResult
    {
        $url = trim($url);
        if (!str_starts_with(strtolower($url), 'https://')) {
            return SetupResult::failed('Amazon only posts to an https address, so the webhook URL has to be one.');
        }

        $api = ($this->api)($config);
        if (!$api->ready()) {
            return SetupResult::failed(
                'Fill in the access key, the secret key and the AWS region on this page first. '
                . 'Delivery reports are set up with the same key that sends the mail.'
            );
        }

        $topicName = self::name($config, 'sns_topic', self::DEFAULT_TOPIC);
        $setName = self::name($config, 'configuration_set', self::DEFAULT_CONFIGURATION_SET);
        $identity = trim((string)($config['identity'] ?? ''));

        // 1. The topic.
        $topic = $api->sns('CreateTopic', ['Name' => $topicName]);
        if (!$topic->ok) {
            return self::refused('create the SNS topic ' . $topicName, $topic);
        }

        $topicArn = $topic->string('TopicArn');
        if ($topicArn === '') {
            return SetupResult::failed('Amazon created the topic but did not say what it is called, so nothing else could be set up.');
        }

        // 2. The policy that lets SES publish to it. Without this the rest
        //    succeeds and no event ever arrives.
        $policy = $this->allowSesToPublish($api, $topicArn);
        if ($policy !== null) {
            return $policy;
        }

        // 3. The subscription, unless this URL is already on the topic.
        $subscription = $this->subscribe($api, $topicArn, $url);
        if ($subscription instanceof SetupResult) {
            return $subscription;
        }

        // 4. The configuration set.
        $set = $this->configurationSet($api, $setName);
        if ($set !== null) {
            return $set;
        }

        // 5. The event destination.
        $destination = $this->eventDestination($api, $setName, $topicArn, $events);
        if ($destination !== null) {
            return $destination;
        }

        // 6. The identity default, where one is named.
        $note = $this->defaultForIdentity($api, $setName, $identity);
        if ($note instanceof SetupResult) {
            return $note;
        }

        return SetupResult::ok(
            sprintf(
                'Delivery reports are set up. %s publishes to the SNS topic %s, which posts to this store. %s %s',
                $setName,
                $topicName,
                $subscription === '' ? 'This address was already subscribed to the topic.' : 'Amazon will post a subscription confirmation to this address within a minute or two and events start after that.',
                $note,
            ),
            $topicArn,
        );
    }

    // ------------------------------------------------------------- the steps

    /**
     * Add this plugin's statement to the topic's policy, keeping whatever else
     * is on it.
     *
     * A topic created by hand usually has AWS's default owner statement and
     * nothing else; a topic shared with something else may have several. Ours
     * is replaced rather than appended when it is already there, which is what
     * makes pressing the button twice do nothing the second time.
     *
     * @return SetupResult|null null when it worked
     */
    private function allowSesToPublish(AwsApi $api, string $topicArn): ?SetupResult
    {
        $attributes = $api->sns('GetTopicAttributes', ['TopicArn' => $topicArn]);
        if (!$attributes->ok) {
            return self::refused('read the SNS topic\'s policy', $attributes);
        }

        $existing = [];
        $current = $attributes->data['Attributes'] ?? [];
        if (\is_array($current)) {
            foreach ($current as $entry) {
                if (\is_array($entry) && trim((string)($entry['key'] ?? '')) === 'Policy') {
                    $decoded = json_decode((string)($entry['value'] ?? ''), true);
                    $existing = \is_array($decoded) ? $decoded : [];
                }
            }
        }

        $statements = [];
        foreach ((array)($existing['Statement'] ?? []) as $statement) {
            if (\is_array($statement) && trim((string)($statement['Sid'] ?? '')) !== self::POLICY_SID) {
                $statements[] = $statement;
            }
        }

        $statements[] = [
            'Sid' => self::POLICY_SID,
            'Effect' => 'Allow',
            'Principal' => ['Service' => 'ses.amazonaws.com'],
            'Action' => 'SNS:Publish',
            'Resource' => $topicArn,
            // Narrower than the policy AWS's own console writes: only this
            // account's SES may publish here, so a topic ARN that leaks is not
            // a topic anybody else can fill with fake bounces.
            'Condition' => ['StringEquals' => ['AWS:SourceAccount' => self::accountIn($topicArn)]],
        ];

        $policy = json_encode(['Version' => '2012-10-17', 'Statement' => array_values($statements)], \JSON_UNESCAPED_SLASHES);
        if ($policy === false) {
            return SetupResult::failed('The topic policy could not be built, which is a bug rather than a setting.');
        }

        $set = $api->sns('SetTopicAttributes', [
            'TopicArn' => $topicArn,
            'AttributeName' => 'Policy',
            'AttributeValue' => $policy,
        ]);

        return $set->ok ? null : self::refused('let SES publish to the SNS topic', $set);
    }

    /**
     * Subscribe the store's URL, unless it is already there.
     *
     * @return SetupResult|string a failure, or the new subscription ARN, or an
     *         empty string when this address was already subscribed
     */
    private function subscribe(AwsApi $api, string $topicArn, string $url): SetupResult|string
    {
        $existing = $api->sns('ListSubscriptionsByTopic', ['TopicArn' => $topicArn]);

        // A key that may subscribe but may not list is a perfectly ordinary
        // policy, and refusing here would be refusing over a check rather than
        // over the work. So a refused listing costs the duplicate check and
        // nothing else.
        if ($existing->ok) {
            foreach ((array)($existing->data['Subscriptions'] ?? []) as $row) {
                if (\is_array($row) && trim((string)($row['Endpoint'] ?? '')) === $url) {
                    return '';
                }
            }
        }

        $answer = $api->sns('Subscribe', [
            'TopicArn' => $topicArn,
            'Protocol' => 'https',
            'Endpoint' => $url,
            'ReturnSubscriptionArn' => 'true',
        ]);

        return $answer->ok ? $answer->string('SubscriptionArn') : self::refused('subscribe this store to the SNS topic', $answer);
    }

    /** @return SetupResult|null null when the set is there or was created */
    private function configurationSet(AwsApi $api, string $name): ?SetupResult
    {
        $existing = $api->ses('GET', ['v2', 'email', 'configuration-sets', $name]);
        if ($existing->ok) {
            return null;
        }

        if (!$existing->missing()) {
            return self::refused('look for the configuration set ' . $name, $existing);
        }

        $created = $api->ses('POST', ['v2', 'email', 'configuration-sets'], ['ConfigurationSetName' => $name]);

        return $created->ok ? null : self::refused('create the configuration set ' . $name, $created);
    }

    /**
     * @param list<string> $events
     * @return SetupResult|null null when it worked
     */
    private function eventDestination(AwsApi $api, string $setName, string $topicArn, array $events): ?SetupResult
    {
        $destination = [
            'Enabled' => true,
            'MatchingEventTypes' => self::matching($events),
            'SnsDestination' => ['TopicArn' => $topicArn],
        ];

        $existing = $api->ses('GET', ['v2', 'email', 'configuration-sets', $setName, 'event-destinations']);

        $already = false;
        if ($existing->ok) {
            foreach ((array)($existing->data['EventDestinations'] ?? []) as $row) {
                if (\is_array($row) && trim((string)($row['Name'] ?? '')) === self::DESTINATION) {
                    $already = true;
                }
            }
        }

        $answer = $already
            ? $api->ses(
                'PUT',
                ['v2', 'email', 'configuration-sets', $setName, 'event-destinations', self::DESTINATION],
                ['EventDestination' => $destination],
            )
            : $api->ses(
                'POST',
                ['v2', 'email', 'configuration-sets', $setName, 'event-destinations'],
                ['EventDestinationName' => self::DESTINATION, 'EventDestination' => $destination],
            );

        if ($answer->ok) {
            return null;
        }

        // A destination created between the listing and the write, which is
        // what a second browser tab looks like. Updating it is the same
        // outcome, so it is not worth a failure.
        if (!$already && str_contains(strtolower($answer->code . ' ' . $answer->error), 'already exists')) {
            $retry = $api->ses(
                'PUT',
                ['v2', 'email', 'configuration-sets', $setName, 'event-destinations', self::DESTINATION],
                ['EventDestination' => $destination],
            );

            return $retry->ok ? null : self::refused('point the configuration set at the SNS topic', $retry);
        }

        return self::refused('point the configuration set at the SNS topic', $answer);
    }

    /**
     * Make the configuration set the identity's default, where one is named.
     *
     * @return SetupResult|string a failure, or the sentence to add to the
     *         success message
     */
    private function defaultForIdentity(AwsApi $api, string $setName, string $identity): SetupResult|string
    {
        if ($identity === '') {
            return sprintf(
                'One thing is left: messages only publish events when they are sent with the %s configuration set. '
                . 'Fill in the sending identity on this page and press the button again to make it the default for '
                . 'that domain, or set the X-SES-CONFIGURATION-SET header to %s on the mail you send.',
                $setName,
                $setName,
            );
        }

        $answer = $api->ses(
            'PUT',
            ['v2', 'email', 'identities', $identity, 'configuration-set'],
            ['ConfigurationSetName' => $setName],
        );

        if (!$answer->ok) {
            return self::refused('make ' . $setName . ' the default configuration set for ' . $identity, $answer);
        }

        return sprintf('Every message sent from %s now publishes its events, with no header to set.', $identity);
    }

    // ------------------------------------------------------------- internals

    /**
     * The event types Amazon understands, out of the ones asked for.
     *
     * An empty list, or one naming nothing Amazon has a word for, falls back to
     * the four that matter: a store that asked for nothing recognisable should
     * end up with working bounce handling rather than a destination that
     * matches no event at all.
     *
     * @param list<string> $events
     * @return list<string>
     */
    public static function matching(array $events): array
    {
        $out = [];
        foreach ($events as $event) {
            $name = self::EVENTS[strtolower(trim((string)$event))] ?? null;
            if ($name !== null && !\in_array($name, $out, true)) {
                $out[] = $name;
            }
        }

        return $out === [] ? ['DELIVERY', 'BOUNCE', 'COMPLAINT', 'REJECT'] : $out;
    }

    /** The account id out of a topic ARN, which is its fifth colon-separated part. */
    public static function accountIn(string $arn): string
    {
        $parts = explode(':', $arn);

        return trim($parts[4] ?? '');
    }

    /** @param array<string, mixed> $config */
    private static function name(array $config, string $key, string $fallback): string
    {
        $value = trim((string)($config[$key] ?? ''));

        return $value === '' ? $fallback : $value;
    }

    private static function refused(string $step, AwsAnswer $answer): SetupResult
    {
        $sentence = rtrim($answer->error, '.') . '.';

        if ($answer->denied()) {
            return SetupResult::failed(sprintf(
                'Amazon would not let this key %s. %s %s',
                $step,
                $sentence,
                'The key is real but is not allowed to do this yet.',
            ));
        }

        return SetupResult::failed(sprintf('This key could not %s. %s', $step, $sentence));
    }
}
