<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailAmazon\Provider;

use Grav\Plugin\Email\Providers\DeliveryReports;
use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\Email\Providers\Payload;
use Grav\Plugin\Email\Providers\Verdict;
use Grav\Plugin\Email\Providers\WebhookRequest;
use Grav\Plugin\EmailAmazon\Http\Http;

/**
 * Amazon SES event notifications, delivered over SNS, read.
 *
 * Moved from the KahunaCart newsletter add-on's `Providers\SesParser`, where
 * this was written and tested in phase 3. The verification, the field reading
 * and the skipping are unchanged; what changed is the namespace, the value
 * objects (the Email plugin's rather than that add-on's) and the event words
 * (`delivered`/`bounced`/`complained`/`opened`/`clicked` rather than
 * `delivered`/`bounce`/`complaint`/`open`/`click`). The comments below are the
 * reason the reading is right and are worth keeping wherever this lives.
 *
 * Documentation: `docs.aws.amazon.com/ses/latest/dg/` —
 * `event-publishing-retrieving-sns-contents`, `notification-contents` and
 * `event-publishing-retrieving-sns-examples` — plus
 * `docs.aws.amazon.com/sns/latest/dg/` for the envelope and
 * `sns-verify-signature-of-message-verify-message-signature` for the signature.
 * Read 2026-09-04.
 *
 * This is the most involved of the six providers by a distance, and every part
 * of that is Amazon's design rather than anybody's choice.
 *
 * ## Two envelopes deep
 *
 * The request body is an **SNS** message. The SES record is a JSON string
 * inside its `Message` field, so it is decoded twice. The outer envelope is
 * what is signed; the inner record is what happened.
 *
 * ## Three kinds of SNS message
 *
 * - `SubscriptionConfirmation` — the first request an endpoint ever receives.
 *   Amazon will send nothing else until the `SubscribeURL` in it has been
 *   fetched. The signature is checked first and the URL is named rather than
 *   fetched, because fetching is a side effect and a provider is a pure
 *   function of a request; the caller makes the request.
 * - `Notification` — an event.
 * - `UnsubscribeConfirmation` — Amazon saying the topic subscription is going
 *   away. Noted and acted on no further; the `SubscribeURL` in one of these is
 *   **never** named, because confirming it would be resubscribing a store to a
 *   topic somebody just removed it from.
 *
 * ## The host rule, which is the whole security of the certificate fetch
 *
 * `SigningCertURL` arrives inside the message and is used to fetch the
 * certificate the signature is checked against. A receiver that fetched
 * whatever URL it was handed would be verifying signatures against a
 * certificate the attacker supplied, which is a check that always passes.
 *
 * So: the scheme must be `https`, and the host must be exactly
 * `sns.<region>.amazonaws.com` — or the partition equivalent
 * `sns.<region>.amazonaws.com.cn` for China, matched by the same pattern with a
 * different tail. A region is letters, digits and hyphens. Nothing else is
 * fetched, and the same rule is applied to `SubscribeURL` before it is named.
 *
 * Amazon's own page no longer spells the pattern out — it says "ensure the
 * `SigningCertURL` is from a trusted AWS domain" and leaves the rest to the
 * reader — so this is the rule every AWS SDK implements rather than a quotation.
 *
 * ## Correlation, and the three things called a message id
 *
 * | Where | What it is |
 * | --- | --- |
 * | `mail.messageId` | **SES's own id.** Never the store's. |
 * | `mail.commonHeaders.messageId` | **SES's id on an event-publishing payload, the store's on an identity notification.** Two opposite meanings under one name, which is Amazon's documented behaviour and is why nothing here reads it. |
 * | `mail.headers[]` where `name` is `Message-ID` | **The store's, verbatim.** |
 *
 * So the join is against `mail.headers[]`, matched case insensitively, and the
 * send header is read out of the same list.
 *
 * Two things stop that from always working, and both are why the send header is
 * there at all:
 *
 * - `headersTruncated` is true when the original headers were over 10 KB, and
 *   the list is then cut short.
 * - The `Rendering Failure` and `DeliveryDelay` payloads carry no headers at
 *   all — neither of which is acted on here, so it costs nothing yet.
 *
 * And for an **identity notification** rather than an event-publishing
 * notification, headers are off until somebody ticks "Include original email
 * headers" on the identity, once per feedback type. The README says so, because
 * a store that has not ticked it gets bounces it can suppress on the address
 * but cannot tie to a campaign.
 *
 * ## Hard and soft
 *
 * `bounce.bounceType`: `Permanent` is hard, `Transient` is soft, `Undetermined`
 * is soft. Three of the permanent subtypes — `OnAccountSuppressionList`,
 * `OnTenantSuppressionList` and `EmailValidationSuppressed` — are SES refusing
 * to try rather than a mail server refusing to accept, and are still treated as
 * hard: the address is not going to receive mail through this transport either
 * way.
 *
 * `complaintFeedbackType: not-spam` is the one complaint that is not one. It is
 * somebody telling their provider a message was wrongly filed, and suppressing
 * on it would take a person off a list for saying they wanted to stay.
 */
final class SesReports implements DeliveryReports
{
    /** SNS message types. */
    public const TYPE_NOTIFICATION = 'Notification';
    public const TYPE_SUBSCRIBE = 'SubscriptionConfirmation';
    public const TYPE_UNSUBSCRIBE = 'UnsubscribeConfirmation';

    /**
     * The only hosts a certificate or a subscription confirmation is fetched
     * from.
     *
     * `sns.` then a region of letters, digits and hyphens, then one of Amazon's
     * partition suffixes. Anchored at both ends, so
     * `sns.us-east-1.amazonaws.com.evil.example` is not a match — which is the
     * case this pattern exists for.
     */
    public const HOST_PATTERN = '/^sns\.[a-z0-9\-]+\.amazonaws\.com(\.cn)?$/i';

    /** @var array<string, string> Amazon's event names to the contract's */
    public const TYPES = [
        'delivery' => Event::DELIVERED,
        'bounce' => Event::BOUNCED,
        'complaint' => Event::COMPLAINED,
        'open' => Event::OPENED,
        'click' => Event::CLICKED,
    ];

    /** The complaint that means the opposite of a complaint. */
    public const NOT_SPAM = 'not-spam';

    /** @var (callable(string): (string|null)) */
    private $fetch;

    private readonly string $sendHeader;

    /**
     * @param CertificateStore|null $certificates where Amazon's signing
     *        certificates are kept; null makes every signature refuse, which is
     *        what a store with nowhere to write should get
     * @param Http|null $http how a certificate is fetched; null makes every
     *        signature refuse, which is what a store with no cURL should get
     * @param string|null $sendHeader the header the store stamps its send id
     *        into; null is {@see SendHeader::DEFAULT_HEADER}
     */
    public function __construct(
        private readonly ?CertificateStore $certificates = null,
        ?Http $http = null,
        ?string $sendHeader = null,
    ) {
        $this->fetch = $http === null
            ? static fn (): ?string => null
            : static fn (string $url): ?string => $http->get($url);

        $header = trim((string)$sendHeader);
        $this->sendHeader = $header === '' ? SendHeader::DEFAULT_HEADER : $header;
    }

    /**
     * A parser with a plain closure for the fetch, for a test.
     *
     * @param callable(string): (string|null) $fetch
     */
    public static function fetchingWith(?CertificateStore $certificates, callable $fetch, ?string $sendHeader = null): self
    {
        $reports = new self($certificates, null, $sendHeader);
        $reports->fetch = $fetch;

        return $reports;
    }

    public function events(): array
    {
        return array_values(array_unique(array_values(self::TYPES)));
    }

    /**
     * Nothing. SNS signs every message with an Amazon certificate, so there is
     * no key for a merchant to paste anywhere.
     */
    public function verificationKeys(): array
    {
        return [];
    }

    public function sendHeader(): string
    {
        return $this->sendHeader;
    }

    public function verify(WebhookRequest $request, array $config): Verdict
    {
        $body = $request->json();
        if ($body === null || array_is_list($body)) {
            return Verdict::refused('the body was not a JSON object');
        }

        $certUrl = trim((string)($body['SigningCertURL'] ?? $body['SigningCertUrl'] ?? ''));
        if (!self::isAmazonUrl($certUrl)) {
            return Verdict::refused('the signing certificate URL was not an Amazon SNS address');
        }

        $signed = self::stringToSign($body);
        if ($signed === null) {
            return Verdict::refused('the message was missing a field the signature covers');
        }

        $signature = base64_decode((string)($body['Signature'] ?? ''), true);
        if ($signature === false || $signature === '') {
            return Verdict::refused('the signature was not base64');
        }

        $pem = $this->certificates?->get($certUrl, $this->fetch);
        if ($pem === null || trim($pem) === '') {
            return Verdict::refused('the signing certificate could not be fetched');
        }

        $key = @openssl_pkey_get_public($pem);
        if ($key === false) {
            return Verdict::refused('the signing certificate could not be read');
        }

        // SignatureVersion 1 is SHA1withRSA and 2 is SHA256withRSA. Version 1
        // is still what a topic created years ago sends, so both are accepted;
        // anything else is refused rather than guessed at.
        $version = trim((string)($body['SignatureVersion'] ?? ''));
        $algorithm = match ($version) {
            '1' => \OPENSSL_ALGO_SHA1,
            '2' => \OPENSSL_ALGO_SHA256,
            default => null,
        };

        if ($algorithm === null) {
            return Verdict::refused('the signature version was not one this store checks');
        }

        return openssl_verify($signed, $signature, $key, $algorithm) === 1
            ? Verdict::verified()
            : Verdict::refused('the SNS signature did not verify');
    }

    public function parse(WebhookRequest $request): Payload
    {
        $body = $request->json();
        if ($body === null || array_is_list($body)) {
            return Payload::unreadable('the body was not a JSON object');
        }

        $type = trim((string)($body['Type'] ?? ''));

        // A bare SES record with no SNS envelope around it. Not what SNS sends,
        // and exactly what Amazon's own examples page prints and what a replay
        // is handed when somebody copies a record out of a log. Treated as a
        // notification whose Message is the body itself.
        if ($type === '' && (isset($body['eventType']) || isset($body['notificationType']))) {
            $type = self::TYPE_NOTIFICATION;
            $body['Message'] = $body;
        }

        if ($type === self::TYPE_SUBSCRIBE) {
            $url = trim((string)($body['SubscribeURL'] ?? ''));

            return self::isAmazonUrl($url)
                ? Payload::confirm($url)
                : Payload::nothing('an SNS subscription confirmation whose SubscribeURL was not an Amazon address');
        }

        if ($type === self::TYPE_UNSUBSCRIBE) {
            // Never named. See the class note.
            return Payload::nothing('Amazon reported that the SNS subscription was removed');
        }

        if ($type !== self::TYPE_NOTIFICATION) {
            return Payload::nothing(sprintf('an SNS message of type "%s"', $type));
        }

        $record = self::inner($body['Message'] ?? null);
        if ($record === null) {
            return Payload::unreadable('the SNS Message field did not hold an SES record');
        }

        // Event publishing says `eventType`; an identity feedback notification
        // says `notificationType`. Same `mail` object either way.
        $name = strtolower(trim((string)($record['eventType'] ?? $record['notificationType'] ?? '')));
        $mapped = self::TYPES[$name] ?? null;

        if ($mapped === null) {
            return Payload::nothing(sprintf('Amazon reported "%s", which this store does not act on', $name));
        }

        $mail = \is_array($record['mail'] ?? null) ? $record['mail'] : [];
        $headers = $mail['headers'] ?? null;

        $bounce = \is_array($record['bounce'] ?? null) ? $record['bounce'] : [];
        $complaint = \is_array($record['complaint'] ?? null) ? $record['complaint'] : [];

        if ($mapped === Event::COMPLAINED
            && strtolower(trim((string)($complaint['complaintFeedbackType'] ?? ''))) === self::NOT_SPAM) {
            return Payload::nothing('Amazon reported a not-spam feedback report, which is not a complaint');
        }

        $hard = null;
        if ($mapped === Event::BOUNCED) {
            $hard = strtolower(trim((string)($bounce['bounceType'] ?? ''))) === 'permanent';
        }

        $recipients = self::recipients($record, $mapped, $mail);
        $messageId = SendHeader::inList($headers, 'Message-ID');
        $sendId = SendHeader::inList($headers, $this->sendHeader)
            ?? SendHeader::inTags($mail['tags'] ?? null, $this->sendHeader);
        $at = Moment::parse(
            $bounce['timestamp']
            ?? $complaint['timestamp']
            ?? ($record['delivery']['timestamp'] ?? null)
            ?? ($record['open']['timestamp'] ?? null)
            ?? ($record['click']['timestamp'] ?? null)
            ?? ($mail['timestamp'] ?? null)
        ) ?? 0;

        $reason = self::reason($bounce, $complaint, $mapped);
        $providerId = trim((string)($mail['messageId'] ?? ''));

        $events = [];
        foreach ($recipients as $recipient) {
            $events[] = Event::of($mapped, $hard, $recipient, $messageId, $providerId, $at, $reason, $sendId);
        }

        return $events === []
            ? Payload::nothing('the SES record named no recipient')
            : Payload::of($events);
    }

    // ------------------------------------------------------------- internals

    /**
     * Whether a URL is one this plugin will let anything fetch.
     *
     * Public because the caller checks a `SubscribeURL` with the same rule
     * before it confirms one, and two copies of a rule like this is how one of
     * them ends up looser than the other.
     */
    public static function isAmazonUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        $parts = parse_url($url);
        if (!\is_array($parts)) {
            return false;
        }

        if (strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }

        // A URL carrying credentials is a URL whose host is not where a reader
        // thinks it is, and no Amazon address has them.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return preg_match(self::HOST_PATTERN, (string)($parts['host'] ?? '')) === 1;
    }

    /**
     * The exact bytes Amazon signed.
     *
     * `KeyName\nValue\n` for each field, fields in byte order, one trailing
     * newline after the last value and nothing after that. Which fields depends
     * on the message type, and `Subject` is included **only when it is
     * present** — Amazon's own sample script gets this wrong, hardcoding
     * `Subject` and signing the literal string `null` for a message that has
     * none, which then fails to verify.
     *
     * @param array<array-key, mixed> $body
     */
    public static function stringToSign(array $body): ?string
    {
        $type = trim((string)($body['Type'] ?? ''));

        $fields = $type === self::TYPE_NOTIFICATION
            ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
            : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];

        $signed = '';
        foreach ($fields as $field) {
            if (!\array_key_exists($field, $body) || $body[$field] === null) {
                // Only `Subject` is allowed to be absent. Anything else missing
                // is a message that is not what it claims to be.
                if ($field === 'Subject') {
                    continue;
                }

                return null;
            }

            $signed .= $field . "\n" . (string)$body[$field] . "\n";
        }

        return $signed;
    }

    /**
     * The SES record inside the SNS `Message` field.
     *
     * @return array<string, mixed>|null
     */
    private static function inner(mixed $message): ?array
    {
        if (\is_array($message)) {
            // Not what SNS sends, and exactly what a hand-written fixture and a
            // replay hand over. Accepted so a merchant can replay the SES record
            // they copied out of a log rather than the envelope.
            return $message;
        }

        if (!\is_string($message) || trim($message) === '') {
            return null;
        }

        try {
            $decoded = json_decode($message, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
    }

    /**
     * Everybody this record is about.
     *
     * A bounce names `bouncedRecipients`, a complaint names
     * `complainedRecipients`, and everything else names `mail.destination`. One
     * message to one person is what a campaign sends, so the list is normally
     * one long — but SES reports a bounce for every address that bounced, and a
     * store that read only the first would suppress one of them.
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed> $mail
     * @return list<string>
     */
    private static function recipients(array $record, string $type, array $mail): array
    {
        $from = match ($type) {
            Event::BOUNCED => $record['bounce']['bouncedRecipients'] ?? null,
            Event::COMPLAINED => $record['complaint']['complainedRecipients'] ?? null,
            default => null,
        };

        $out = [];

        if (\is_array($from)) {
            foreach ($from as $entry) {
                $address = \is_array($entry) ? trim((string)($entry['emailAddress'] ?? '')) : trim((string)$entry);
                if ($address !== '') {
                    $out[] = $address;
                }
            }
        }

        if ($out !== []) {
            return $out;
        }

        // `delivery.recipients` on a delivery, `mail.destination` on everything
        // else. Both are plain lists of addresses.
        $fallback = $record['delivery']['recipients'] ?? $mail['destination'] ?? null;

        if (\is_array($fallback)) {
            foreach ($fallback as $address) {
                $address = trim((string)$address);
                if ($address !== '') {
                    $out[] = $address;
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $bounce
     * @param array<string, mixed> $complaint
     */
    private static function reason(array $bounce, array $complaint, string $type): ?string
    {
        if ($type === Event::BOUNCED) {
            $subType = trim((string)($bounce['bounceSubType'] ?? ''));
            $first = $bounce['bouncedRecipients'][0] ?? null;
            $diagnostic = \is_array($first) ? trim((string)($first['diagnosticCode'] ?? '')) : '';

            $parts = array_filter([$subType, $diagnostic], static fn (string $part): bool => $part !== '');

            return $parts === [] ? null : implode(': ', $parts);
        }

        if ($type === Event::COMPLAINED) {
            $feedback = trim((string)($complaint['complaintFeedbackType'] ?? ''));

            return $feedback === '' ? 'marked as spam' : 'marked as spam (' . $feedback . ')';
        }

        return null;
    }
}
