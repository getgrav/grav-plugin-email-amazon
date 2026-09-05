<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailAmazon\Tests\Unit;

use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\Email\Providers\WebhookRequest;
use Grav\Plugin\EmailAmazon\Provider\SendHeader;
use Grav\Plugin\EmailAmazon\Provider\SesReports;
use PHPUnit\Framework\TestCase;

/**
 * Amazon's own documented sample payloads, parsed, and checked field by field.
 *
 * Moved from the KahunaCart newsletter add-on's `ParserTest`, where one file
 * covered six providers; this is the SES half of it with the namespace changed
 * and the event words moved to the contract's. The fixtures came with it and
 * are the ones from Amazon's documentation, read on 2026-09-04.
 *
 * That matters more than it sounds. SES has renamed a field before, and a
 * parser written against a payload somebody remembered is a parser that reads
 * null forever without failing anything. A fixture taken from the documentation
 * and a test that reads it is the only thing that turns a rename from a store
 * that quietly stops recording bounces into a red bar.
 *
 * ## Where the fixtures came from, and which are not verbatim
 *
 * The event records are copied exactly. Amazon publishes no combined
 * SNS-plus-record sample anywhere, so `sns-bounce.json` is the documented SNS
 * envelope with the documented SES record encoded into its `Message` field, and
 * `notification-bounce-assembled.json` is the same idea for an identity
 * notification.
 */
final class SesReportsParseTest extends TestCase
{
    /**
     * Every documented sample, and the event it has to become.
     *
     * Written out longhand rather than generated, because a table built from
     * the same constants the parser reads would agree with the code however
     * wrong both were.
     *
     * @return iterable<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function samples(): iterable
    {
        // Amazon's own sample, as an SES record rather than inside an SNS
        // envelope — the parser takes either, which is what lets a replay read
        // a record somebody copied out of a log.
        yield 'bounce' => ['bounce', [
            'type' => Event::BOUNCED,
            'hard' => true,
            'email' => 'recipient@example.com',
            'provider_id' => 'EXAMPLE7c191be45-e9aedb9a-02f9-4d12-a87d-dd0099a07f8a-000000',
        ]];

        yield 'complaint' => ['complaint', [
            'type' => Event::COMPLAINED,
            'hard' => null,
            'email' => 'recipient@example.com',
        ]];

        yield 'delivery' => ['delivery', [
            'type' => Event::DELIVERED,
            'hard' => null,
            'email' => 'recipient@example.com',
        ]];

        yield 'open' => ['open', ['type' => Event::OPENED]];
        yield 'click' => ['click', ['type' => Event::CLICKED]];

        // The same bounce inside the SNS envelope it really arrives in, which
        // is two JSON decodes rather than one.
        yield 'bounce inside an SNS envelope' => ['sns-bounce', [
            'type' => Event::BOUNCED,
            'hard' => true,
        ]];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('samples')]
    public function testASampleBecomesTheEventItDescribes(string $fixture, array $expected): void
    {
        $payload = (new SesReports())->parse(self::request($fixture));

        self::assertFalse($payload->unreadable, $fixture);
        self::assertNotSame([], $payload->events, $fixture . ': nothing came out of it');

        $event = $payload->events[0]->toArray();

        foreach ($expected as $field => $value) {
            self::assertSame($value, $event[$field], "{$fixture}: {$field}");
        }
    }

    /**
     * Every sample carries a moment that was actually read.
     *
     * A date format nobody parsed reads as zero and is then quietly stamped
     * with the caller's clock, which is a bug that only shows up as a chart
     * with everything in the wrong place a month later.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('samples')]
    public function testEverySampleCarriesAMomentThatWasActuallyRead(string $fixture, array $expected): void
    {
        $event = (new SesReports())->parse(self::request($fixture))->events[0];

        self::assertGreaterThan(
            946684800,
            $event->at,
            "{$fixture}: the timestamp was not read, so the caller's clock would stand in for it"
        );
    }

    /**
     * An event type this does not act on is a note, not a refusal.
     *
     * SES sends more than the five that are read here — `Send`, `Reject`,
     * `Rendering Failure`, `DeliveryDelay` — and a merchant who ticked every
     * box in the console should get a quiet log line rather than a refusal that
     * Amazon then retries.
     */
    public function testAnEventTypeThisDoesNotActOnIsSkippedRatherThanRefused(): void
    {
        foreach (['send', 'reject', 'rendering-failure', 'delivery-delay'] as $fixture) {
            $payload = (new SesReports())->parse(self::request($fixture));

            self::assertTrue($payload->isEmpty(), $fixture . ' should be skipped');
            self::assertFalse($payload->unreadable, $fixture . ' is a perfectly good payload');
            self::assertStringContainsString('does not act on', $payload->note, $fixture);
        }
    }

    /** A body that is not JSON at all is a note and no events, never an exception. */
    public function testAnUnreadableBodyIsANoteRatherThanAnException(): void
    {
        foreach (['this is not json', '', '[1,2,3]', '{"Type":"Notification","Message":"not json either"}'] as $body) {
            $payload = (new SesReports())->parse(new WebhookRequest('POST', '', [], [], $body));

            self::assertTrue($payload->isEmpty(), $body);
            self::assertNotSame('', $payload->note, $body);
        }
    }

    /**
     * A subscription confirmation names its URL for the caller to fetch, and
     * only when that URL is Amazon's.
     */
    public function testASubscriptionConfirmationNamesItsUrl(): void
    {
        $payload = (new SesReports())->parse(self::request('sns-subscription-confirmation'));

        self::assertNotNull($payload->confirmUrl);
        self::assertStringStartsWith('https://sns.', (string)$payload->confirmUrl);

        $body = json_decode(self::body('sns-subscription-confirmation'), true);
        $body['SubscribeURL'] = 'https://sns.us-east-1.amazonaws.com.evil.example/?Action=ConfirmSubscription';

        $elsewhere = (new SesReports())->parse(new WebhookRequest('POST', '', [], [], (string)json_encode($body)));

        self::assertNull($elsewhere->confirmUrl);
        self::assertStringContainsString('not an Amazon address', $elsewhere->note);
    }

    /**
     * An unsubscribe confirmation is noted and nothing else.
     *
     * It carries a `SubscribeURL` too, and fetching that would be this plugin
     * resubscribing a store to a topic somebody had just removed it from.
     */
    public function testAnUnsubscribeConfirmationIsNeverConfirmed(): void
    {
        $payload = (new SesReports())->parse(self::request('sns-unsubscribe-confirmation'));

        self::assertNull($payload->confirmUrl);
        self::assertTrue($payload->isEmpty());
        self::assertStringContainsString('removed', $payload->note);
    }

    /**
     * Amazon reports one event for every address that bounced, and a store that
     * read only the first would suppress one of them.
     */
    public function testOneEventPerBouncedRecipient(): void
    {
        $record = (string)json_encode([
            'eventType' => 'Bounce',
            'mail' => ['timestamp' => '2026-09-04T10:00:00.000Z', 'messageId' => 'ses-1', 'destination' => []],
            'bounce' => [
                'bounceType' => 'Permanent',
                'bounceSubType' => 'General',
                'timestamp' => '2026-09-04T10:01:00.000Z',
                'bouncedRecipients' => [
                    ['emailAddress' => 'one@example.com'],
                    ['emailAddress' => 'two@example.com'],
                ],
            ],
        ]);

        $payload = (new SesReports())->parse(self::envelope($record));

        self::assertCount(2, $payload->events);
        self::assertSame('one@example.com', $payload->events[0]->email);
        self::assertSame('two@example.com', $payload->events[1]->email);
        self::assertTrue($payload->events[0]->isHardBounce());
    }

    /** `Transient` and `Undetermined` are not permanent failures. */
    public function testOnlyAPermanentBounceIsHard(): void
    {
        foreach (['Permanent' => true, 'Transient' => false, 'Undetermined' => false] as $bounceType => $hard) {
            $record = (string)json_encode([
                'eventType' => 'Bounce',
                'mail' => ['timestamp' => '2026-09-04T10:00:00.000Z', 'destination' => ['a@example.com']],
                'bounce' => [
                    'bounceType' => $bounceType,
                    'timestamp' => '2026-09-04T10:01:00.000Z',
                    'bouncedRecipients' => [['emailAddress' => 'a@example.com']],
                ],
            ]);

            self::assertSame($hard, (new SesReports())->parse(self::envelope($record))->events[0]->hard, $bounceType);
        }
    }

    /**
     * `not-spam` is somebody telling their provider a message was wrongly
     * filed. Suppressing on it would take a person off a list for saying they
     * wanted to stay.
     */
    public function testANotSpamFeedbackReportIsNotAComplaint(): void
    {
        $record = (string)json_encode([
            'eventType' => 'Complaint',
            'mail' => ['timestamp' => '2026-09-04T10:00:00.000Z', 'messageId' => 'ses-1', 'destination' => ['a@example.com']],
            'complaint' => [
                'timestamp' => '2026-09-04T10:01:00.000Z',
                'complaintFeedbackType' => 'not-spam',
                'complainedRecipients' => [['emailAddress' => 'a@example.com']],
            ],
        ]);

        $payload = (new SesReports())->parse(self::envelope($record));

        self::assertTrue($payload->isEmpty());
        self::assertStringContainsString('not-spam', $payload->note);
    }

    /**
     * The send id and the message id come out of `mail.headers[]`, not out of
     * `mail.messageId` and not out of `commonHeaders`.
     *
     * `mail.messageId` is Amazon's own id, and `commonHeaders.messageId` means
     * Amazon's id on an event-publishing payload and the store's on an identity
     * notification — two opposite things under one name, which is why nothing
     * reads it.
     */
    public function testTheStoresHeadersComeOutOfTheHeaderList(): void
    {
        $record = (string)json_encode([
            'eventType' => 'Delivery',
            'mail' => [
                'timestamp' => '2026-09-04T10:00:00.000Z',
                'messageId' => 'amazons-own-id',
                'destination' => ['a@example.com'],
                'commonHeaders' => ['messageId' => 'amazons-own-id'],
                'headers' => [
                    ['name' => 'Message-ID', 'value' => '<nl-3-41-abcd@example.com>'],
                    ['name' => 'x-kahunacart-send', 'value' => '41'],
                ],
            ],
            'delivery' => ['timestamp' => '2026-09-04T10:00:05.000Z', 'recipients' => ['a@example.com']],
        ]);

        $event = (new SesReports())->parse(self::envelope($record))->events[0];

        self::assertSame('nl-3-41-abcd@example.com', $event->messageId, 'the brackets come off');
        self::assertSame('amazons-own-id', $event->providerId);
        self::assertSame('41', $event->sendId, 'matched case insensitively, as a header name is');
    }

    /**
     * Message tags are the second place a send id can arrive, and the only one
     * that survives `headersTruncated`.
     */
    public function testTheSendIdIsAlsoReadFromMessageTags(): void
    {
        $record = (string)json_encode([
            'eventType' => 'Delivery',
            'mail' => [
                'timestamp' => '2026-09-04T10:00:00.000Z',
                'destination' => ['a@example.com'],
                'headersTruncated' => true,
                'tags' => ['X-KahunaCart-Send' => ['77']],
            ],
            'delivery' => ['timestamp' => '2026-09-04T10:00:05.000Z', 'recipients' => ['a@example.com']],
        ]);

        self::assertSame('77', (new SesReports())->parse(self::envelope($record))->events[0]->sendId);
    }

    /** A store that names its own header gets its own header read back. */
    public function testAStoreCanNameItsOwnSendHeader(): void
    {
        $record = (string)json_encode([
            'eventType' => 'Delivery',
            'mail' => [
                'timestamp' => '2026-09-04T10:00:00.000Z',
                'destination' => ['a@example.com'],
                'headers' => [['name' => 'X-Shop-Send', 'value' => 'abc-123']],
            ],
            'delivery' => ['timestamp' => '2026-09-04T10:00:05.000Z', 'recipients' => ['a@example.com']],
        ]);

        $reports = new SesReports(null, null, 'X-Shop-Send');

        self::assertSame('X-Shop-Send', $reports->sendHeader());
        self::assertSame('abc-123', $reports->parse(self::envelope($record))->events[0]->sendId);
        self::assertSame(SendHeader::DEFAULT_HEADER, (new SesReports())->sendHeader());
    }

    /** An address arriving as `Name <addr>` is the same person as `addr`. */
    public function testAnAddressWithADisplayNameIsNormalised(): void
    {
        $record = (string)json_encode([
            'eventType' => 'Delivery',
            'mail' => ['timestamp' => '2026-09-04T10:00:00.000Z', 'destination' => ['Jane Smith <Jane@Example.COM>']],
            'delivery' => ['timestamp' => '2026-09-04T10:00:05.000Z'],
        ]);

        self::assertSame('jane@example.com', (new SesReports())->parse(self::envelope($record))->events[0]->email);
    }

    /** A bounce's reason is Amazon's subtype and the receiving server's own words. */
    public function testABouncesReasonIsAmazonsSubtypeAndTheDiagnosticCode(): void
    {
        $event = (new SesReports())->parse(self::request('bounce'))->events[0];

        self::assertSame('General: smtp; 550 5.1.1 user unknown', $event->reason);
    }

    /** The five event types Amazon can report, and nothing invented beside them. */
    public function testItSaysWhichEventsAmazonCanReport(): void
    {
        self::assertSame(
            [Event::DELIVERED, Event::BOUNCED, Event::COMPLAINED, Event::OPENED, Event::CLICKED],
            (new SesReports())->events(),
        );

        foreach ((new SesReports())->events() as $event) {
            self::assertContains($event, Event::TYPES, $event);
        }
    }

    // ------------------------------------------------------------- internals

    private static function request(string $fixture): WebhookRequest
    {
        return new WebhookRequest('POST', '', [], ['content-type' => 'application/json'], self::body($fixture));
    }

    /** One SES record wrapped in the SNS envelope it really arrives in. */
    private static function envelope(string $record): WebhookRequest
    {
        $body = (string)json_encode(['Type' => 'Notification', 'Message' => $record]);

        return new WebhookRequest('POST', '', [], ['content-type' => 'application/json'], $body);
    }

    public static function body(string $fixture): string
    {
        $path = \dirname(__DIR__) . "/fixtures/webhooks/ses/{$fixture}.json";
        self::assertFileExists($path, "there is no documented sample at {$path}");

        return (string)file_get_contents($path);
    }
}
