<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailAmazon\Provider;

use Grav\Plugin\Email\Providers\Inbound\InboundMessage;
use Grav\Plugin\Email\Providers\Inbound\InboundPayload;
use Grav\Plugin\Email\Providers\Inbound\InboundReceiver;
use Grav\Plugin\Email\Providers\Inbound\InboundReference;
use Grav\Plugin\Email\Providers\Inbound\InboundRequest;
use Grav\Plugin\Email\Providers\Verdict;
use Grav\Plugin\Email\Providers\WebhookRequest;
use Grav\Plugin\EmailAmazon\Aws\SigV4;
use Grav\Plugin\EmailAmazon\Http\CurlHttp;
use Grav\Plugin\EmailAmazon\Http\Http;

/**
 * Amazon SES email receiving, delivered over SNS, read.
 *
 * Documentation: `docs.aws.amazon.com/ses/latest/dg/` —
 * `receiving-email-notifications-contents`, `receiving-email-notifications-examples`,
 * `receiving-email-action-sns` and `receiving-email-action-s3`. Read 2026-09-23.
 * The SNS envelope and its signature are the same as for delivery
 * notifications, and are checked by {@see SesReports::verify()} itself.
 *
 * ## Two ways a message arrives
 *
 * A receipt rule in SES decides, and this reads both.
 *
 * - **SNS action.** The notification carries the whole message in `content`,
 *   either as text (`encoding` `UTF8`, the default) or base64 (`BASE64`, which
 *   is the one to pick: it keeps every byte, where UTF-8 can mangle a message
 *   written in another charset). Amazon bounces anything over 150 KB with this
 *   action, so it suits a helpdesk that never receives attachments.
 * - **S3 action with an SNS topic.** SES writes the message to a bucket and
 *   the notification names it (`receipt.action.bucketName`, `objectKey`). That
 *   is metadata only, so {@see parse()} answers an {@see InboundReference} and
 *   {@see fetch()} downloads the object later, in the consumer's worker, with
 *   this plugin's access key signed by {@see SigV4}. Messages up to 40 MB.
 *
 * Other actions (Lambda, Bounce, Stop, WorkMail) can also notify a topic, and
 * their notifications carry no message, so they are nothing.
 *
 * ## What SES says about the message
 *
 * `receipt` holds five verdicts, each `{"status": "PASS" | "FAIL" | "GRAY" |
 * "PROCESSING_FAILED" | "DISABLED"}`. They become `auth` like this:
 *
 * | SES status | `auth` value |
 * | --- | --- |
 * | `PASS` | `pass` |
 * | `FAIL` | `fail` |
 * | `GRAY` | `none` (for SPF that covers none, softfail and neutral; for DKIM, unsigned or not aligned; for DMARC, no enforcing policy) |
 * | `PROCESSING_FAILED` | `temperror` |
 * | `DISABLED` or absent | no key: unknown |
 *
 * under the keys `spf`, `dkim`, `dmarc`, and also `spam` and `virus`. They win
 * over any `Authentication-Results` header in the message itself.
 *
 * SES gives no spam score, only a verdict, so `spamScore` is `0.0` for `PASS`,
 * `10.0` for `FAIL` (well past SpamAssassin's usual threshold of 5) and null
 * for anything else.
 *
 * **A virus.** `virusVerdict` `FAIL` is not refused here: the request is
 * genuine and refusing it would only make SNS retry. The message is returned
 * with `auth['virus'] === 'fail'`, and a consumer rejects it on that, without
 * storing its attachments or answering the sender. On the S3 path the same is
 * in the reference's `meta['auth']['virus']`, so the consumer can reject it
 * without downloading anything.
 *
 * The envelope comes from SES too: `receipt.recipients` is the RCPT TO list
 * the rule matched, where a `support+token@` address survives, and
 * `mail.source` is the MAIL FROM. `mail.messageId` is SES's own id, kept as
 * `providerId`.
 *
 * ## Subscription confirmation and the topic
 *
 * The first request to the address is a `SubscriptionConfirmation`. Once its
 * signature checks out, {@see verify()} names the `SubscribeURL` (after the same
 * host check as the certificate URL, {@see SesReports::isAmazonUrl()}) and the
 * consumer fetches it. An `UnsubscribeConfirmation` is never confirmed.
 *
 * Any AWS account can create a topic and try to subscribe an address it has
 * learned. The consumer's URL secret already stops that, and the optional
 * `inbound_topic_arn` setting narrows it further: when set, a message from any
 * other topic is refused before its signature is even checked.
 */
final class SesInbound implements InboundReceiver
{
    public const KEY = 'ses';

    /** Optional: the topic ARN(s) mail may come from, one per line or comma separated. */
    public const TOPIC_ARN = 'inbound_topic_arn';

    /** Optional: the bucket's region, when it is not the region the receipt rule runs in. */
    public const S3_REGION = 'inbound_s3_region';

    /**
     * An SNS message is at most 256 KB, and the envelope escapes the SES JSON
     * inside it once more, so the HTTP body can be larger than that. Anything
     * over 1 MiB is not SNS.
     */
    public const MAX_BYTES = 1024 * 1024;

    /** SES's own limit for a message written to S3, plus room. */
    public const MAX_OBJECT_BYTES = 41 * 1024 * 1024;

    /** Seconds allowed for the S3 download, which runs in the worker. */
    public const OBJECT_TIMEOUT = 60;

    /** What SES verdict statuses become in `auth`. */
    public const STATUSES = [
        'PASS' => 'pass',
        'FAIL' => 'fail',
        'GRAY' => 'none',
        'PROCESSING_FAILED' => 'temperror',
    ];

    public const SPAM_FAIL_SCORE = 10.0;

    /**
     * @param SesReports $reports the SNS signature check, with its certificate store
     * @param Http|null  $s3      how the S3 object is downloaded; null is cURL with a 41 MB cap
     * @param (\Closure(): int)|null $clock the current Unix time, for the S3 signature
     */
    public function __construct(
        private readonly SesReports $reports,
        private readonly ?Http $s3 = null,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Amazon SES';
    }

    public function verificationKeys(): array
    {
        return [self::TOPIC_ARN];
    }

    public function maxBytes(): int
    {
        return self::MAX_BYTES;
    }

    public function verify(InboundRequest $request, array $config): Verdict
    {
        $body = $request->json();
        if ($body === null || array_is_list($body)) {
            return Verdict::refused('the body was not an SNS message');
        }

        $allowed = self::topics($config);
        if ($allowed !== []) {
            $topic = trim((string)($body['TopicArn'] ?? ''));
            if (!\in_array($topic, $allowed, true)) {
                return Verdict::refused(sprintf('the SNS topic "%s" is not the one set in inbound_topic_arn', $topic));
            }
        }

        $verdict = $this->reports->verify(
            new WebhookRequest($request->method, $request->path, $request->query, $request->headers, $request->body, $request->remoteAddress),
            [],
        );
        if (!$verdict->ok) {
            return $verdict;
        }

        if (trim((string)($body['Type'] ?? '')) === SesReports::TYPE_SUBSCRIBE) {
            $url = trim((string)($body['SubscribeURL'] ?? ''));

            return SesReports::isAmazonUrl($url)
                ? Verdict::confirm($url)
                : Verdict::refused('the SubscribeURL was not an Amazon SNS address');
        }

        return $verdict;
    }

    public function parse(InboundRequest $request, array $config): InboundPayload
    {
        try {
            return $this->read($request);
        } catch (\Throwable $e) {
            return InboundPayload::unreadable('the SNS message could not be read: ' . $e->getMessage());
        }
    }

    /**
     * Download a message the S3 action stored. Runs in the consumer's worker.
     *
     * Reads `access_key`, `secret_key` and `session_token` from this plugin's
     * config, and the region from `inbound_s3_region`, then the region the
     * receipt rule ran in, then `region`. The key needs `s3:GetObject` on the
     * bucket.
     *
     * @throws \RuntimeException when the object cannot be fetched or is not a message
     */
    public function fetch(InboundReference $ref, array $config): InboundMessage
    {
        $bucket = (string)($ref->meta['bucket'] ?? '');
        $key = (string)($ref->meta['key'] ?? '');
        if ($bucket === '' || $key === '') {
            throw new \RuntimeException('the reference names no S3 bucket and object key');
        }
        if (preg_match('/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/', $bucket) !== 1) {
            throw new \RuntimeException(sprintf('"%s" is not an S3 bucket name', $bucket));
        }

        $region = strtolower(trim((string)($config[self::S3_REGION] ?? '')));
        if ($region === '') {
            $region = strtolower(trim((string)($ref->meta['region'] ?? '')));
        }
        if ($region === '') {
            $region = strtolower(trim((string)($config['region'] ?? '')));
        }
        if (preg_match('/^[a-z0-9-]{1,32}$/', $region) !== 1) {
            throw new \RuntimeException('no AWS region is known for the S3 bucket; set inbound_s3_region');
        }

        $accessKey = trim((string)($config['access_key'] ?? ''));
        $secretKey = trim((string)($config['secret_key'] ?? ''));
        if ($accessKey === '' || $secretKey === '') {
            throw new \RuntimeException('the AWS access key and secret key have to be set to download mail from S3');
        }

        $suffix = str_starts_with($region, 'cn-') ? '.amazonaws.com.cn' : '.amazonaws.com';
        $encodedKey = implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));
        // A bucket name with a dot breaks the certificate of the virtual-hosted
        // name, so those go path-style.
        if (str_contains($bucket, '.')) {
            $host = 's3.' . $region . $suffix;
            $path = '/' . $bucket . '/' . $encodedKey;
        } else {
            $host = $bucket . '.s3.' . $region . $suffix;
            $path = '/' . $encodedKey;
        }

        $headers = SigV4::sign(
            'GET',
            $host,
            $path,
            [],
            ['x-amz-content-sha256' => SigV4::EMPTY_PAYLOAD],
            '',
            's3',
            $region,
            $accessKey,
            $secretKey,
            trim((string)($config['session_token'] ?? '')),
            $this->clock !== null ? ($this->clock)() : null,
        );

        $http = $this->s3 ?? new CurlHttp(self::MAX_OBJECT_BYTES, self::OBJECT_TIMEOUT);
        $answer = $http->send('GET', 'https://' . $host . $path, $headers);

        if ($answer['status'] !== 200) {
            throw new \RuntimeException(self::s3Error($answer, $bucket, $key));
        }

        $raw = $answer['raw'];
        if (!self::looksLikeMime($raw)) {
            throw new \RuntimeException(
                'the S3 object is not a mail message. If the receipt rule encrypts messages with a KMS key, turn that off: '
                . 'SES encrypts on the client side, and only the AWS Java and Ruby SDKs can decrypt it'
            );
        }

        $overrides = \is_array($ref->meta['overrides'] ?? null) ? $ref->meta['overrides'] : [];

        return self::finish(InboundMessage::fromMime($raw, self::KEY), $overrides);
    }

    public function instructions(string $webhookUrl): string
    {
        return 'In the Amazon SES console, in a region that receives email, verify the domain that will receive mail under Identities '
            . '(a subdomain such as reply.example.com keeps your normal mailbox untouched). At your DNS host, add an MX record for it '
            . 'pointing to inbound-smtp.<region>.amazonaws.com (the region you verified it in), priority 10. In the SNS console, in the '
            . 'same region, create a standard topic, then create a subscription on it with protocol HTTPS and ' . $webhookUrl . ' as '
            . 'the endpoint; this site confirms it by itself. Back in SES, under Email receiving, create a rule set (or use the active '
            . 'one), and add a rule for your support address. For mail under 150 KB give it the action "Publish to Amazon SNS topic" with '
            . 'that topic and Base64 encoding. For larger mail and attachments, use "Deliver to Amazon S3 bucket" instead, with no message '
            . 'encryption and that topic as its SNS topic, and give this plugin\'s access key s3:GetObject on the bucket. Make sure the '
            . 'rule set is the active one. Optionally paste the topic ARN into Inbound topic ARN in the Amazon plugin settings, so mail '
            . 'from any other topic is refused.';
    }

    // ------------------------------------------------------------- internals

    private function read(InboundRequest $request): InboundPayload
    {
        $body = $request->json();
        if ($body === null || array_is_list($body)) {
            return InboundPayload::unreadable('the body was not an SNS message');
        }

        $type = trim((string)($body['Type'] ?? ''));

        if ($type === SesReports::TYPE_SUBSCRIBE) {
            $url = trim((string)($body['SubscribeURL'] ?? ''));

            return SesReports::isAmazonUrl($url)
                ? InboundPayload::confirm($url)
                : InboundPayload::nothing('an SNS subscription confirmation whose SubscribeURL was not an Amazon address');
        }
        if ($type === SesReports::TYPE_UNSUBSCRIBE) {
            return InboundPayload::nothing('Amazon reported that the SNS subscription was removed');
        }
        if ($type !== SesReports::TYPE_NOTIFICATION) {
            return InboundPayload::nothing(sprintf('an SNS message of type "%s"', $type));
        }

        $record = \is_string($body['Message'] ?? null) ? json_decode($body['Message'], true, 64) : null;
        if (!\is_array($record)) {
            return InboundPayload::unreadable('the SNS Message field did not hold an SES record');
        }

        $kind = trim((string)($record['notificationType'] ?? $record['eventType'] ?? ''));
        if ($kind !== 'Received') {
            return InboundPayload::nothing(sprintf('Amazon reported "%s", which is not received mail', $kind));
        }

        $receipt = \is_array($record['receipt'] ?? null) ? $record['receipt'] : [];
        $mail = \is_array($record['mail'] ?? null) ? $record['mail'] : [];
        $action = \is_array($receipt['action'] ?? null) ? $receipt['action'] : [];
        $overrides = self::overrides($receipt, $mail);

        switch (strtoupper(trim((string)($action['type'] ?? '')))) {
            case 'SNS':
                $content = $record['content'] ?? null;
                if (!\is_string($content) || trim($content) === '') {
                    return InboundPayload::unreadable('the SNS action notification carried no content');
                }
                // `encoding` is in the action object of every notification seen,
                // but Amazon's field table leaves it out, so a body that is not
                // a message and decodes as base64 into one is read as base64 too.
                $encoding = strtoupper(str_replace(['-', '_'], '', trim((string)($action['encoding'] ?? ''))));
                if ($encoding === '' && !self::looksLikeMime($content)) {
                    $decoded = base64_decode(preg_replace('/\s+/', '', $content) ?? '', true);
                    $encoding = \is_string($decoded) && self::looksLikeMime($decoded) ? 'BASE64' : 'UTF8';
                }
                if ($encoding === 'BASE64') {
                    $content = base64_decode(preg_replace('/\s+/', '', $content) ?? '', true);
                    if ($content === false || $content === '') {
                        return InboundPayload::unreadable('the SNS action content was not base64');
                    }
                }

                return InboundPayload::of([self::finish(InboundMessage::fromMime($content, self::KEY), $overrides)]);

            case 'S3':
                $bucket = trim((string)($action['bucketName'] ?? ''));
                $key = trim((string)($action['objectKey'] ?? ''));
                if ($key === '' && isset($mail['messageId'])) {
                    $key = trim((string)($action['objectKeyPrefix'] ?? '')) . (string)$mail['messageId'];
                }
                if ($bucket === '' || $key === '') {
                    return InboundPayload::unreadable('the S3 action notification named no bucket or object key');
                }

                $common = \is_array($mail['commonHeaders'] ?? null) ? $mail['commonHeaders'] : [];

                return InboundPayload::of([new InboundReference(self::KEY, (string)($mail['messageId'] ?? $key), [
                    'bucket' => $bucket,
                    'key' => $key,
                    'region' => self::region((string)($action['topicArn'] ?? $body['TopicArn'] ?? '')),
                    'auth' => $overrides['auth'] ?? [],
                    'subject' => \is_string($common['subject'] ?? null) ? $common['subject'] : '',
                    'from' => \is_array($common['from'] ?? null) ? implode(', ', array_filter($common['from'], 'is_string')) : '',
                    'overrides' => $overrides,
                ])]);

            default:
                return InboundPayload::nothing(sprintf(
                    'a notification from an SES "%s" action, which carries no message',
                    (string)($action['type'] ?? '')
                ));
        }
    }

    /**
     * The envelope, verdicts, spam score and SES id, as `InboundMessage`
     * property overrides. JSON-encodable, because the S3 path stores them.
     *
     * @param array<array-key, mixed> $receipt
     * @param array<array-key, mixed> $mail
     * @return array<string, mixed>
     */
    private static function overrides(array $receipt, array $mail): array
    {
        $overrides = [];

        $recipients = [];
        foreach ((array)($receipt['recipients'] ?? []) as $recipient) {
            if (\is_string($recipient) && trim($recipient) !== '') {
                $recipients[] = trim($recipient);
            }
        }
        if ($recipients !== []) {
            $overrides['envelopeTo'] = $recipients;
        }

        if (\is_string($mail['source'] ?? null)) {
            $overrides['envelopeFrom'] = trim($mail['source'], " \t<>");
        }
        if (\is_string($mail['messageId'] ?? null) && $mail['messageId'] !== '') {
            $overrides['providerId'] = $mail['messageId'];
        }

        $auth = [];
        foreach (['spf' => 'spfVerdict', 'dkim' => 'dkimVerdict', 'dmarc' => 'dmarcVerdict', 'spam' => 'spamVerdict', 'virus' => 'virusVerdict'] as $name => $field) {
            $status = strtoupper(trim((string)($receipt[$field]['status'] ?? '')));
            if (isset(self::STATUSES[$status])) {
                $auth[$name] = self::STATUSES[$status];
            }
        }
        if ($auth !== []) {
            $overrides['auth'] = $auth;
        }

        $spam = $auth['spam'] ?? null;
        if ($spam === 'pass') {
            $overrides['spamScore'] = 0.0;
        } elseif ($spam === 'fail') {
            $overrides['spamScore'] = self::SPAM_FAIL_SCORE;
        }

        return $overrides;
    }

    /**
     * SES's knowledge laid over the parsed message; its verdicts win over the
     * message's own `Authentication-Results`.
     *
     * @param array<string, mixed> $overrides
     */
    private static function finish(InboundMessage $message, array $overrides): InboundMessage
    {
        if (\is_array($overrides['auth'] ?? null)) {
            $overrides['auth'] = $overrides['auth'] + $message->auth;
        }
        if (\array_key_exists('spamScore', $overrides) && $overrides['spamScore'] !== null) {
            $overrides['spamScore'] = (float)$overrides['spamScore'];
        }

        return $message->with($overrides);
    }

    /** Whether bytes start the way a mail message does: a header line, or an mbox From line. */
    private static function looksLikeMime(string $bytes): bool
    {
        return preg_match('/^(?:From |[A-Za-z0-9-]+:)/', ltrim($bytes)) === 1;
    }

    /** The region in an ARN, `arn:aws:sns:us-east-1:…`, or ''. */
    private static function region(string $arn): string
    {
        $parts = explode(':', $arn);

        return isset($parts[3]) && preg_match('/^[a-z0-9-]{1,32}$/', $parts[3]) === 1 ? $parts[3] : '';
    }

    /** @return list<string> */
    private static function topics(array $config): array
    {
        $value = $config[self::TOPIC_ARN] ?? '';
        $list = \is_array($value) ? $value : preg_split('/[\s,]+/', is_scalar($value) ? (string)$value : '');

        return array_values(array_filter(array_map(
            static fn ($topic): string => is_scalar($topic) ? trim((string)$topic) : '',
            $list ?: []
        ), static fn (string $topic): bool => $topic !== ''));
    }

    /** @param array{status: int, raw: string, error: string} $answer */
    private static function s3Error(array $answer, string $bucket, string $key): string
    {
        $code = preg_match('/<Code>([^<]+)<\/Code>/', $answer['raw'], $m) ? $m[1] : '';

        return match (true) {
            $answer['status'] === 0 => 'S3 could not be reached: ' . $answer['error'],
            $answer['status'] === 301 || $code === 'PermanentRedirect' || $code === 'AuthorizationHeaderMalformed'
                => sprintf('the bucket "%s" is in another region; set inbound_s3_region to it', $bucket),
            $answer['status'] === 403 => sprintf('S3 refused access (%s); the access key needs s3:GetObject on "%s"', $code ?: '403', $bucket),
            $answer['status'] === 404 => sprintf('S3 has no object "%s" in "%s" (%s)', $key, $bucket, $code ?: '404'),
            default => sprintf('S3 answered %d%s', $answer['status'], $code !== '' ? ' (' . $code . ')' : ''),
        };
    }
}
