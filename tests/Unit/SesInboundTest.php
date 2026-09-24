<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailAmazon\Tests\Unit;

use Grav\Plugin\Email\Providers\Inbound\InboundCapable;
use Grav\Plugin\Email\Providers\Inbound\InboundGateway;
use Grav\Plugin\Email\Providers\Inbound\InboundMessage;
use Grav\Plugin\Email\Providers\Inbound\InboundPayload;
use Grav\Plugin\Email\Providers\Inbound\InboundReference;
use Grav\Plugin\Email\Providers\Inbound\InboundRequest;
use Grav\Plugin\Email\Providers\ProviderRegistry;
use Grav\Plugin\EmailAmazon\Provider\CertificateStore;
use Grav\Plugin\EmailAmazon\Provider\SesInbound;
use Grav\Plugin\EmailAmazon\Provider\SesInboundProvider;
use Grav\Plugin\EmailAmazon\Provider\SesProvider;
use Grav\Plugin\EmailAmazon\Provider\SesReports;
use Grav\Plugin\EmailAmazon\Tests\Support\FakeHttp;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

/**
 * SES email receiving, from fixtures built on Amazon's documented notification.
 *
 * Every SNS envelope here is signed for real with a certificate this test
 * makes, so the receiver is checked end to end: the signature, the
 * subscription confirmation and its host check, both SNS action encodings, the
 * S3 reference and its signed download, and the verdict mapping.
 */
final class SesInboundTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/inbound/ses/';
    private const CERT_URL = 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-inbound.pem';
    private const TOPIC = 'arn:aws:sns:us-east-1:123456789012:helpdesk-inbound';

    /** @var array{0: \OpenSSLAsymmetricKey, 1: string}|null */
    private static ?array $signing = null;

    /** @var list<string> */
    private array $directories = [];

    private int $certificateFetches = 0;

    #[After]
    protected function removeCertificateDirectories(): void
    {
        foreach ($this->directories as $directory) {
            foreach ((array)glob($directory . '/*') as $file) {
                @unlink((string)$file);
            }
            @rmdir($directory);
        }
        $this->directories = [];
    }

    public function testTheProviderIsInboundCapableAndTheGatewayFindsIt(): void
    {
        self::assertNotInstanceOf(InboundCapable::class, new SesProvider(), 'the base class never names the interface');

        $provider = new SesInboundProvider();
        self::assertInstanceOf(SesProvider::class, $provider);
        self::assertInstanceOf(InboundCapable::class, $provider);
        self::assertInstanceOf(SesInbound::class, $provider->inbound());

        $registry = new ProviderRegistry();
        $registry->add($provider);

        self::assertInstanceOf(SesInbound::class, (new InboundGateway(null, $registry))->receiver('ses'));
    }

    public function testItDescribesItself(): void
    {
        $receiver = $this->receiver();

        self::assertSame('ses', $receiver->key());
        self::assertSame('Amazon SES', $receiver->label());
        self::assertSame(['inbound_topic_arn'], $receiver->verificationKeys());
        self::assertGreaterThanOrEqual(256 * 1024, $receiver->maxBytes());
    }

    public function testTheInstructionsCoverDnsTheRuleAndTheSubscription(): void
    {
        $text = $this->receiver()->instructions('https://example.com/_helpdesk/inbound/ses/secret');

        self::assertStringContainsString('https://example.com/_helpdesk/inbound/ses/secret', $text);
        self::assertStringContainsString('inbound-smtp.<region>.amazonaws.com', $text);
        self::assertStringContainsString('150 KB', $text);
        self::assertStringContainsString('S3', $text);
        self::assertStringContainsString('HTTPS', $text);
    }

    // ------------------------------------------------------------ SNS envelope

    public function testASubscriptionConfirmationIsVerifiedAndNamed(): void
    {
        $request = self::request(self::sign(self::load('sns-subscription-confirmation.json')));
        $receiver = $this->receiver();

        $verdict = $receiver->verify($request, []);
        self::assertTrue($verdict->ok, $verdict->reason);
        self::assertStringStartsWith('https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription', (string)$verdict->confirmUrl);

        $result = (new InboundGateway(null, $this->registry()))->receive('ses', $request, []);
        self::assertSame(200, $result->status);
        self::assertSame($verdict->confirmUrl, $result->payload->confirmUrl);
        self::assertSame([], $result->payload->items);

        self::assertSame($verdict->confirmUrl, $receiver->parse($request, [])->confirmUrl);
    }

    public function testASubscribeUrlOffAmazonIsNeverNamed(): void
    {
        $request = self::request(self::sign(self::load('sns-subscription-non-amazon.json')));
        $receiver = $this->receiver();

        $verdict = $receiver->verify($request, []);
        self::assertFalse($verdict->ok);
        self::assertNull($verdict->confirmUrl);

        $payload = $receiver->parse($request, []);
        self::assertNull($payload->confirmUrl);
    }

    public function testAMessageChangedAfterSigningIsRefused(): void
    {
        $envelope = self::sign(self::notification('received-sns-utf8.json'));
        $envelope['Message'] = str_replace('twice', 'three times', $envelope['Message']);

        $request = self::request($envelope);
        self::assertFalse($this->receiver()->verify($request, [])->ok);
        self::assertSame(401, (new InboundGateway(null, $this->registry()))->receive('ses', $request, [])->status);
    }

    public function testACertificateUrlOffAmazonIsRefusedBeforeItIsFetched(): void
    {
        $envelope = self::notification('received-sns-utf8.json');
        $envelope['SigningCertURL'] = 'https://sns.us-east-1.amazonaws.com.evil.example/cert.pem';

        self::assertFalse($this->receiver()->verify(self::request(self::sign($envelope, false)), [])->ok);
        self::assertSame(0, $this->certificateFetches);
    }

    public function testAnotherTopicIsRefusedWhenATopicIsSet(): void
    {
        $request = self::request(self::sign(self::notification('received-sns-utf8.json')));

        $other = $this->receiver()->verify($request, ['inbound_topic_arn' => 'arn:aws:sns:us-east-1:999999999999:someone-else']);
        self::assertFalse($other->ok);
        self::assertSame(0, $this->certificateFetches, 'refused on the topic before any certificate was fetched');

        $mine = $this->receiver()->verify($request, ['inbound_topic_arn' => "arn:aws:sns:eu-west-1:1:x\n" . self::TOPIC]);
        self::assertTrue($mine->ok, $mine->reason);
        self::assertTrue($mine->signed);
    }

    public function testAnUnsubscribeConfirmationIsNothing(): void
    {
        $envelope = self::load('sns-subscription-confirmation.json');
        $envelope['Type'] = 'UnsubscribeConfirmation';

        $payload = $this->receiver()->parse(self::request(self::sign($envelope)), []);
        self::assertNull($payload->confirmUrl);
        self::assertSame([], $payload->items);
        self::assertFalse($payload->unreadable);
    }

    public function testABodyThatIsNotSnsIsRefusedAndUnreadable(): void
    {
        $request = new InboundRequest(headers: ['content-type' => 'text/plain'], body: 'hello');

        self::assertFalse($this->receiver()->verify($request, [])->ok);
        self::assertTrue($this->receiver()->parse($request, [])->unreadable);
    }

    // ------------------------------------------------------------ SNS action

    public function testInlineUtf8ContentIsReadWithTheVerdicts(): void
    {
        $record = self::load('received-sns-utf8.json')['Message'];
        $message = self::only($this->receiver()->parse(self::request(self::sign(self::notification('received-sns-utf8.json'))), []));

        self::assertSame($record['content'], $message->raw);
        self::assertSame('ses', $message->receiver);
        self::assertSame('caf=r7x+yk2qm0a@mail.gmail.com', $message->messageId);
        self::assertSame('hd.1042.aa11@helpdesk.example.com', $message->inReplyTo);
        self::assertSame('Renée Dubois', $message->from->name);
        self::assertSame('Re: [#1042] Café menu printer', $message->subject);
        self::assertSame("The printer prints the café menu twice.\n", str_replace("\r\n", "\n", (string)$message->text));
        self::assertSame(['support+t8f2k@reply.example.com'], $message->envelopeTo, 'the plus address is in the matched RCPT TO');
        self::assertSame('renee@example.net', $message->envelopeFrom);
        self::assertSame('d6iitobk75ur44p8kdnnp7g2n800', $message->providerId);
        self::assertSame('pass', $message->auth['spf']);
        self::assertSame('pass', $message->auth['dkim']);
        self::assertSame('none', $message->auth['dmarc'], 'GRAY is none');
        self::assertSame('pass', $message->auth['spam']);
        self::assertSame('pass', $message->auth['virus']);
        self::assertSame(0.0, $message->spamScore);
    }

    public function testInlineBase64ContentKeepsEveryByte(): void
    {
        $record = self::load('received-sns-base64.json')['Message'];
        $message = self::only($this->receiver()->parse(self::request(self::sign(self::notification('received-sns-base64.json'))), []));

        self::assertSame(base64_decode($record['content'], true), $message->raw);
        self::assertSame("Grüße aus München.\n", str_replace("\r\n", "\n", (string)$message->text));
        self::assertSame('Jürgen Weiß', $message->from->name);
        self::assertSame('München Bestellung', $message->subject);
        self::assertSame(['support@reply.example.com'], $message->envelopeTo);
        self::assertSame('none', $message->auth['spf']);
        self::assertSame('none', $message->auth['dkim']);
    }

    public function testBase64ContentWithNoEncodingFieldIsStillRead(): void
    {
        $envelope = self::load('received-sns-base64.json');
        unset($envelope['Message']['receipt']['action']['encoding']);
        $utf8 = self::load('received-sns-utf8.json');
        unset($utf8['Message']['receipt']['action']['encoding']);

        $latin = self::only($this->receiver()->parse(self::request(self::sign(self::wrap($envelope))), []));
        self::assertSame('München Bestellung', $latin->subject);

        $plain = self::only($this->receiver()->parse(self::request(self::sign(self::wrap($utf8))), []));
        self::assertSame($utf8['Message']['content'], $plain->raw);
    }

    public function testAVirusIsMarkedForTheConsumerToReject(): void
    {
        $message = self::only($this->receiver()->parse(self::request(self::sign(self::notification('received-virus.json'))), []));

        self::assertSame('fail', $message->auth['virus'], 'L3.1 rejects on auth[virus] === fail');
        self::assertSame('fail', $message->auth['spam']);
        self::assertSame(SesInbound::SPAM_FAIL_SCORE, $message->spamScore);
        self::assertSame('fail', $message->auth['dkim']);
        self::assertSame('temperror', $message->auth['spf'], 'PROCESSING_FAILED is temperror');
        self::assertSame('fail', $message->auth['dmarc'], 'SES wins over the message\'s own Authentication-Results');
    }

    public function testAnSnsActionWithNoContentIsUnreadable(): void
    {
        $envelope = self::load('received-sns-utf8.json');
        unset($envelope['Message']['content']);

        self::assertTrue($this->receiver()->parse(self::request(self::sign(self::wrap($envelope))), [])->unreadable);
    }

    public function testANotificationThatIsNotReceivedMailIsNothing(): void
    {
        $envelope = self::load('received-sns-utf8.json');
        $envelope['Message'] = ['notificationType' => 'Bounce'];

        $payload = $this->receiver()->parse(self::request(self::sign(self::wrap($envelope))), []);
        self::assertSame([], $payload->items);
        self::assertFalse($payload->unreadable);
    }

    public function testAnotherActionTypeIsNothing(): void
    {
        $envelope = self::load('received-sns-utf8.json');
        $envelope['Message']['receipt']['action'] = ['type' => 'Lambda', 'topicArn' => self::TOPIC];
        unset($envelope['Message']['content']);

        $payload = $this->receiver()->parse(self::request(self::sign(self::wrap($envelope))), []);
        self::assertSame([], $payload->items);
        self::assertFalse($payload->unreadable);
    }

    // ------------------------------------------------------------ S3 action

    public function testAnS3NotificationIsAReferenceThatSurvivesJson(): void
    {
        $payload = $this->receiver()->parse(self::request(self::sign(self::notification('received-s3.json'))), []);

        self::assertCount(1, $payload->items);
        $ref = $payload->items[0];
        self::assertInstanceOf(InboundReference::class, $ref);
        self::assertSame('ses', $ref->receiver);
        self::assertSame('d6iitobk75ur44p8kdnnp7g2n800', $ref->id);
        self::assertSame('helpdesk-inbound-mail', $ref->meta['bucket']);
        self::assertSame('mail/d6iitobk75ur44p8kdnnp7g2n800', $ref->meta['key']);
        self::assertSame('us-east-1', $ref->meta['region']);
        self::assertSame('pass', $ref->meta['auth']['virus']);
        self::assertSame('Re: [#1042] Café menu printer', $ref->meta['subject']);

        $stored = json_decode((string)json_encode($ref->meta), true);
        self::assertSame($ref->meta['bucket'], $stored['bucket']);
    }

    public function testFetchDownloadsTheObjectSignedForS3(): void
    {
        $ref = $this->s3Reference();
        $meta = json_decode((string)json_encode($ref->meta), true); // as the consumer stored it
        $ref = new InboundReference($ref->receiver, $ref->id, $meta);

        $http = (new FakeHttp())->answer('helpdesk-inbound-mail', 200, (string)file_get_contents(self::FIXTURES . 's3-object.eml'));
        $receiver = new SesInbound($this->reports(), $http, static fn (): int => 1758620000);

        $message = $receiver->fetch($ref, ['access_key' => 'AKIDEXAMPLE', 'secret_key' => 'secret', 'region' => 'eu-west-1']);

        $request = $http->requests[0];
        self::assertSame('GET', $request['method']);
        self::assertSame('https://helpdesk-inbound-mail.s3.us-east-1.amazonaws.com/mail/d6iitobk75ur44p8kdnnp7g2n800', $request['url']);
        self::assertSame(SigV4Empty::HASH, $request['headers']['x-amz-content-sha256']);
        self::assertStringContainsString('Credential=AKIDEXAMPLE/20250923/us-east-1/s3/aws4_request', $request['headers']['Authorization']);
        self::assertStringContainsString('x-amz-content-sha256', $request['headers']['Authorization']);

        self::assertSame('caf=r7x+yk2qm0a@mail.gmail.com', $message->messageId);
        self::assertSame(['support+t8f2k@reply.example.com'], $message->envelopeTo);
        self::assertSame('d6iitobk75ur44p8kdnnp7g2n800', $message->providerId);
        self::assertSame('pass', $message->auth['virus']);
        self::assertSame(0.0, $message->spamScore, 'a score stored as JSON comes back a float');
    }

    public function testADottedBucketGoesPathStyleAndTheRegionCanBeSet(): void
    {
        $ref = $this->s3Reference();
        $ref = new InboundReference('ses', $ref->id, ['bucket' => 'mail.example.com'] + $ref->meta);

        $http = (new FakeHttp())->answer('mail.example.com', 200, (string)file_get_contents(self::FIXTURES . 's3-object.eml'));
        (new SesInbound($this->reports(), $http))->fetch($ref, [
            'access_key' => 'AKIDEXAMPLE', 'secret_key' => 'secret', 'inbound_s3_region' => 'eu-central-1',
        ]);

        self::assertSame('https://s3.eu-central-1.amazonaws.com/mail.example.com/mail/d6iitobk75ur44p8kdnnp7g2n800', $http->requests[0]['url']);
    }

    public function testFetchSaysWhatIsWrong(): void
    {
        $ref = $this->s3Reference();
        $config = ['access_key' => 'AKIDEXAMPLE', 'secret_key' => 'secret'];

        $denied = (new FakeHttp())->answer('helpdesk', 403, '<Error><Code>AccessDenied</Code></Error>');
        try {
            (new SesInbound($this->reports(), $denied))->fetch($ref, $config);
            self::fail('a refusal should throw so the worker retries');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('s3:GetObject', $e->getMessage());
        }

        $encrypted = (new FakeHttp())->answer('helpdesk', 200, "\x00\x01\x02 binary");
        try {
            (new SesInbound($this->reports(), $encrypted))->fetch($ref, $config);
            self::fail('an encrypted object is not a message');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('encrypt', $e->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('access key');
        (new SesInbound($this->reports(), new FakeHttp()))->fetch($ref, []);
    }

    // ------------------------------------------------------------ helpers

    private function s3Reference(): InboundReference
    {
        $payload = $this->receiver()->parse(self::request(self::sign(self::notification('received-s3.json'))), []);
        self::assertInstanceOf(InboundReference::class, $payload->items[0]);

        return $payload->items[0];
    }

    private function receiver(): SesInbound
    {
        return new SesInbound($this->reports());
    }

    private function registry(): ProviderRegistry
    {
        $registry = new ProviderRegistry();
        $registry->add(new class ($this->receiver()) implements \Grav\Plugin\Email\Providers\Provider, InboundCapable {
            public function __construct(private readonly SesInbound $receiver)
            {
            }

            public function inbound(): \Grav\Plugin\Email\Providers\Inbound\InboundReceiver
            {
                return $this->receiver;
            }

            public function engines(): array
            {
                return ['ses-test'];
            }

            public function key(): string
            {
                return 'ses-test';
            }

            public function label(): string
            {
                return 'SES test';
            }

            public function capabilities(): \Grav\Plugin\Email\Providers\Capabilities
            {
                return (new SesProvider())->capabilities();
            }

            public function reports(): ?\Grav\Plugin\Email\Providers\DeliveryReports
            {
                return null;
            }

            public function setup(): ?\Grav\Plugin\Email\Providers\WebhookSetup
            {
                return null;
            }

            public function domain(): \Grav\Plugin\Email\Providers\DomainFacts
            {
                return (new SesProvider())->domain();
            }

            public function instructions(): string
            {
                return '';
            }
        });

        return $registry;
    }

    private function reports(): SesReports
    {
        $directory = sys_get_temp_dir() . '/ses-inbound-' . bin2hex(random_bytes(4));
        $this->directories[] = $directory;
        [, $pem] = self::signing();

        return SesReports::fetchingWith(new CertificateStore($directory), function (string $url) use ($pem): ?string {
            ++$this->certificateFetches;

            return $url === self::CERT_URL ? $pem : null;
        });
    }

    /** @return array<string, mixed> */
    private static function load(string $name): array
    {
        $fixture = json_decode((string)file_get_contents(self::FIXTURES . $name), true, 64, \JSON_THROW_ON_ERROR);
        unset($fixture['_comment']);

        return $fixture;
    }

    /** A fixture's SES record in an SNS Notification envelope, unsigned. */
    private static function notification(string $name): array
    {
        return self::wrap(self::load($name));
    }

    /** @param array<string, mixed> $fixture */
    private static function wrap(array $fixture): array
    {
        return [
            'Type' => 'Notification',
            'MessageId' => '0f0b6c1e-1234-5678-9abc-def012345678',
            'TopicArn' => $fixture['TopicArn'],
            'Subject' => 'Amazon SES Email Receipt Notification',
            'Message' => (string)json_encode($fixture['Message'], \JSON_UNESCAPED_SLASHES),
            'Timestamp' => '2026-09-23T10:12:19.123Z',
            'SignatureVersion' => '1',
        ];
    }

    /** Signed with the test certificate, SignatureVersion 1. */
    private static function sign(array $envelope, bool $setCertUrl = true): array
    {
        [$private] = self::signing();
        if ($setCertUrl) {
            $envelope['SigningCertURL'] = self::CERT_URL;
        }
        $envelope['SignatureVersion'] = '1';
        openssl_sign(SesReports::stringToSign($envelope) ?? '', $signature, $private, \OPENSSL_ALGO_SHA1);
        $envelope['Signature'] = base64_encode($signature);

        return $envelope;
    }

    private static function request(array $envelope): InboundRequest
    {
        return new InboundRequest(
            headers: ['content-type' => 'text/plain; charset=UTF-8', 'x-amz-sns-message-type' => (string)$envelope['Type']],
            body: (string)json_encode($envelope, \JSON_UNESCAPED_SLASHES),
            remoteAddress: '54.240.197.1',
        );
    }

    private static function only(InboundPayload $payload): InboundMessage
    {
        self::assertFalse($payload->unreadable, $payload->note);
        self::assertCount(1, $payload->items);
        self::assertInstanceOf(InboundMessage::class, $payload->items[0]);

        return $payload->items[0];
    }

    /** @return array{0: \OpenSSLAsymmetricKey, 1: string} */
    private static function signing(): array
    {
        if (self::$signing !== null) {
            return self::$signing;
        }

        $key = \function_exists('openssl_csr_new')
            ? @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA])
            : false;
        $csr = $key !== false ? @openssl_csr_new(['commonName' => 'sns.us-east-1.amazonaws.com'], $key, ['digest_alg' => 'sha256']) : false;
        $certificate = $csr !== false ? @openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']) : false;
        $pem = '';
        if ($certificate === false || !openssl_x509_export($certificate, $pem)) {
            self::markTestSkipped('this build of PHP cannot generate an X.509 certificate');
        }

        return self::$signing = [$key, $pem];
    }
}

/** The SHA-256 of no bytes, spelled out so the test does not read it from the class under test. */
final class SigV4Empty
{
    public const HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
}
