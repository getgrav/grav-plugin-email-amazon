<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailAmazon\Tests\Unit;

use Grav\Plugin\Email\Providers\WebhookRequest;
use Grav\Plugin\EmailAmazon\Provider\CertificateStore;
use Grav\Plugin\EmailAmazon\Provider\SesReports;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\After;

/**
 * A forged SNS message is refused, and the forged cases are the point.
 *
 * Moved from the KahunaCart newsletter add-on's `SignatureTest`, the SNS half.
 * Every signature here is computed for real, against a certificate this test
 * generates, rather than pasted from a documentation sample. A test built on a
 * fixed signature can only ever check that one string equals another; a test
 * that signs and then verifies checks that the thing being signed is the thing
 * Amazon signs, which is where this goes wrong if it goes wrong at all.
 *
 * A verifier that accepted everything would pass a test that only fed it
 * genuine payloads, and a store whose verification was broken would then be
 * acting on anything anybody posted at a URL they had guessed.
 */
final class SesReportsVerifyTest extends TestCase
{
    /** @var list<string> directories to take away afterwards */
    private array $directories = [];

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

    /** A genuine notification verifies, and the same signature over a changed message does not. */
    public function testAForgedSnsMessageIsRefused(): void
    {
        $signing = self::certificate();
        if ($signing === null) {
            self::markTestSkipped('this build of PHP cannot generate an X.509 certificate');
        }

        [$private, $pem] = $signing;

        $certUrl = 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-abc123.pem';
        $envelope = self::signed($private, $certUrl, (string)json_encode(['eventType' => 'Delivery']));

        $reports = $this->reports($certUrl, $pem);

        $verdict = $reports->verify(self::request((string)json_encode($envelope)), []);
        self::assertTrue($verdict->ok, $verdict->reason);
        self::assertTrue($verdict->signed, 'SES signs, so a pass here is a checked signature');

        // The forged one: the same signature over a different message, which is
        // exactly what somebody who captured one notification would try.
        $forged = $envelope;
        $forged['Message'] = (string)json_encode(['eventType' => 'Bounce']);
        $refused = $reports->verify(self::request((string)json_encode($forged)), []);
        self::assertFalse($refused->ok);
        self::assertStringContainsString('did not verify', $refused->reason);
    }

    /**
     * A certificate URL on a host somebody else controls is refused before
     * anything is fetched.
     *
     * This is the whole security of the certificate fetch. A receiver that
     * fetched whatever URL it was handed would be checking signatures against a
     * certificate the attacker supplied, which is a check that always passes.
     */
    public function testACertificateUrlSomewhereElseIsRefusedBeforeItIsFetched(): void
    {
        $signing = self::certificate();
        if ($signing === null) {
            self::markTestSkipped('this build of PHP cannot generate an X.509 certificate');
        }

        [$private, $pem] = $signing;

        $certUrl = 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-abc123.pem';
        $envelope = self::signed($private, $certUrl, (string)json_encode(['eventType' => 'Delivery']));
        $envelope['SigningCertURL'] = 'https://sns.us-east-1.amazonaws.com.evil.example/cert.pem';

        $fetched = [];
        $reports = SesReports::fetchingWith(
            $this->store(),
            static function (string $url) use (&$fetched, $pem, $certUrl): ?string {
                $fetched[] = $url;

                return $url === $certUrl ? $pem : null;
            },
        );

        $verdict = $reports->verify(self::request((string)json_encode($envelope)), []);

        self::assertFalse($verdict->ok);
        self::assertStringContainsString('not an Amazon SNS address', $verdict->reason);
        self::assertSame([], $fetched, 'nothing may be fetched from a host that failed the rule');
    }

    /** A signature version nobody has heard of is refused rather than guessed at. */
    public function testAnUnknownSignatureVersionIsRefused(): void
    {
        $signing = self::certificate();
        if ($signing === null) {
            self::markTestSkipped('this build of PHP cannot generate an X.509 certificate');
        }

        [$private, $pem] = $signing;

        $certUrl = 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-abc123.pem';
        $envelope = self::signed($private, $certUrl, (string)json_encode(['eventType' => 'Delivery']));
        $envelope['SignatureVersion'] = '9';

        $verdict = $this->reports($certUrl, $pem)->verify(self::request((string)json_encode($envelope)), []);

        self::assertFalse($verdict->ok);
        self::assertStringContainsString('signature version', $verdict->reason);
    }

    /** Both signature versions Amazon has ever sent are accepted; version 1 is still in the wild. */
    public function testBothSignatureVersionsAreAccepted(): void
    {
        $signing = self::certificate();
        if ($signing === null) {
            self::markTestSkipped('this build of PHP cannot generate an X.509 certificate');
        }

        [$private, $pem] = $signing;
        $certUrl = 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-abc123.pem';

        foreach (['1' => \OPENSSL_ALGO_SHA1, '2' => \OPENSSL_ALGO_SHA256] as $version => $algorithm) {
            $envelope = self::signed($private, $certUrl, (string)json_encode(['eventType' => 'Delivery']), (string)$version, $algorithm);
            $verdict = $this->reports($certUrl, $pem)->verify(self::request((string)json_encode($envelope)), []);

            self::assertTrue($verdict->ok, 'version ' . $version . ': ' . $verdict->reason);
        }
    }

    /**
     * No certificate at all is a refusal, not a pass.
     *
     * This is the "missing key" case for a provider whose key is a certificate
     * it fetches: a store with no cURL, no writable directory or no route out
     * refuses every message rather than accepting one it could not check.
     */
    public function testWithNoWayToFetchACertificateEverythingIsRefused(): void
    {
        $signing = self::certificate();
        if ($signing === null) {
            self::markTestSkipped('this build of PHP cannot generate an X.509 certificate');
        }

        [$private] = $signing;

        $certUrl = 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-abc123.pem';
        $envelope = self::signed($private, $certUrl, (string)json_encode(['eventType' => 'Delivery']));
        $request = self::request((string)json_encode($envelope));

        // No certificate store at all.
        $verdict = (new SesReports())->verify($request, []);
        self::assertFalse($verdict->ok);
        self::assertStringContainsString('could not be fetched', $verdict->reason);

        // A store, and a fetch that answers nothing.
        $verdict = SesReports::fetchingWith($this->store(), static fn (): ?string => null)->verify($request, []);
        self::assertFalse($verdict->ok);
        self::assertStringContainsString('could not be fetched', $verdict->reason);

        // A store, and a fetch that answers something that is not a certificate.
        $verdict = SesReports::fetchingWith($this->store(), static fn (): ?string => 'hello')->verify($request, []);
        self::assertFalse($verdict->ok);
        self::assertStringContainsString('could not be read', $verdict->reason);
    }

    /** A body that is not a JSON object is refused before anything else happens. */
    public function testABodyThatIsNotAJsonObjectIsRefused(): void
    {
        foreach (['', 'not json', '[1,2,3]'] as $body) {
            $verdict = (new SesReports())->verify(self::request($body), []);

            self::assertFalse($verdict->ok, $body);
            self::assertStringContainsString('JSON object', $verdict->reason);
        }
    }

    /**
     * The host rule, in full.
     *
     * The whole security of the certificate fetch and of the subscription
     * confirmation is this one function, so it is worth a table rather than a
     * couple of examples.
     */
    public function testOnlyAmazonSnsAddressesAreEverFetched(): void
    {
        foreach ([
            'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-a.pem',
            'https://sns.eu-west-2.amazonaws.com/x.pem',
            'https://sns.cn-north-1.amazonaws.com.cn/x.pem',
            'https://SNS.US-EAST-1.AMAZONAWS.COM/x.pem',
        ] as $url) {
            self::assertTrue(SesReports::isAmazonUrl($url), $url);
        }

        foreach ([
            '',
            'http://sns.us-east-1.amazonaws.com/x.pem',
            'https://sns.us-east-1.amazonaws.com.evil.example/x.pem',
            'https://evil.example/sns.us-east-1.amazonaws.com/x.pem',
            'https://sns.us-east-1.amazonaws.com.evil.example',
            'https://amazonaws.com/x.pem',
            'https://user:pass@sns.us-east-1.amazonaws.com/x.pem',
            'file:///etc/passwd',
            '//sns.us-east-1.amazonaws.com/x.pem',
        ] as $url) {
            self::assertFalse(SesReports::isAmazonUrl($url), $url);
        }
    }

    /**
     * `Subject` is signed only when it is there.
     *
     * Amazon's own sample script gets this wrong — it hardcodes the field and
     * signs the literal string `null` for a message with no subject, which then
     * fails to verify against every real message.
     */
    public function testTheStringToSignOmitsAnAbsentSubject(): void
    {
        $withSubject = SesReports::stringToSign([
            'Type' => 'Notification',
            'MessageId' => 'm',
            'Subject' => 'hello',
            'Message' => 'body',
            'Timestamp' => 't',
            'TopicArn' => 'arn',
        ]);

        self::assertSame(
            "Message\nbody\nMessageId\nm\nSubject\nhello\nTimestamp\nt\nTopicArn\narn\nType\nNotification\n",
            $withSubject,
        );

        $without = SesReports::stringToSign([
            'Type' => 'Notification',
            'MessageId' => 'm',
            'Message' => 'body',
            'Timestamp' => 't',
            'TopicArn' => 'arn',
        ]);

        self::assertSame("Message\nbody\nMessageId\nm\nTimestamp\nt\nTopicArn\narn\nType\nNotification\n", $without);
        self::assertStringNotContainsString('Subject', (string)$without);

        // A field the signature covers that is missing is a message that is not
        // what it claims to be, and there is nothing to verify.
        self::assertNull(SesReports::stringToSign(['Type' => 'Notification', 'MessageId' => 'm']));
    }

    /** A subscription confirmation signs a different set of fields. */
    public function testASubscriptionConfirmationSignsItsTokenAndSubscribeUrl(): void
    {
        $signed = SesReports::stringToSign([
            'Type' => 'SubscriptionConfirmation',
            'MessageId' => 'm',
            'Token' => 'tok',
            'TopicArn' => 'arn',
            'Message' => 'please confirm',
            'SubscribeURL' => 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription',
            'Timestamp' => 't',
        ]);

        self::assertIsString($signed);
        self::assertStringContainsString("SubscribeURL\nhttps://sns.us-east-1.amazonaws.com", $signed);
        self::assertStringContainsString("Token\ntok\n", $signed);
    }

    /** SNS signs with its own certificate, so there is no key for anybody to paste. */
    public function testThereIsNoVerificationKeyToConfigure(): void
    {
        self::assertSame([], (new SesReports())->verificationKeys());
    }

    /** A certificate that was fetched once is not fetched again. */
    public function testACertificateIsFetchedOnceAndThenReadFromDisk(): void
    {
        $signing = self::certificate();
        if ($signing === null) {
            self::markTestSkipped('this build of PHP cannot generate an X.509 certificate');
        }

        [$private, $pem] = $signing;

        $certUrl = 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-abc123.pem';
        $envelope = self::signed($private, $certUrl, (string)json_encode(['eventType' => 'Delivery']));
        $request = self::request((string)json_encode($envelope));

        $fetches = 0;
        $store = $this->store();
        $fetch = static function (string $url) use (&$fetches, $pem): ?string {
            $fetches++;

            return $pem;
        };

        self::assertTrue(SesReports::fetchingWith($store, $fetch)->verify($request, [])->ok);
        self::assertTrue(SesReports::fetchingWith($store, $fetch)->verify($request, [])->ok);

        self::assertSame(1, $fetches, 'the second message reads the certificate off disk');
    }

    // ------------------------------------------------------------- internals

    private function reports(string $certUrl, string $pem): SesReports
    {
        return SesReports::fetchingWith(
            $this->store(),
            static fn (string $url): ?string => $url === $certUrl ? $pem : null,
        );
    }

    private function store(): CertificateStore
    {
        $directory = sys_get_temp_dir() . '/email-amazon-certs-' . bin2hex(random_bytes(4));
        $this->directories[] = $directory;

        return new CertificateStore($directory);
    }

    /**
     * A genuine SNS envelope, signed with a certificate this test made.
     *
     * @return array<string, string>
     */
    private static function signed(
        \OpenSSLAsymmetricKey $private,
        string $certUrl,
        string $message,
        string $version = '1',
        int $algorithm = \OPENSSL_ALGO_SHA1,
    ): array {
        $envelope = [
            'Type' => 'Notification',
            'MessageId' => 'de6d1b58-1234-1234-1234-abcdefabcdef',
            'TopicArn' => 'arn:aws:sns:us-east-1:123456789012:ses-events',
            'Message' => $message,
            'Timestamp' => '2026-09-04T10:00:00.000Z',
            'SignatureVersion' => $version,
            'SigningCertURL' => $certUrl,
        ];

        $signature = '';
        openssl_sign(SesReports::stringToSign($envelope) ?? '', $signature, $private, $algorithm);
        $envelope['Signature'] = base64_encode($signature);

        return $envelope;
    }

    private static function request(string $body): WebhookRequest
    {
        return new WebhookRequest('POST', '', [], ['content-type' => 'application/json'], $body, '203.0.113.7');
    }

    /**
     * A self-signed X.509 certificate and its private key, standing in for
     * Amazon's.
     *
     * @return array{0: \OpenSSLAsymmetricKey, 1: string}|null the key and the PEM
     */
    private static function certificate(): ?array
    {
        if (!\function_exists('openssl_csr_new')) {
            return null;
        }

        $key = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            return null;
        }

        $csr = @openssl_csr_new(['commonName' => 'sns.us-east-1.amazonaws.com'], $key, ['digest_alg' => 'sha256']);
        if ($csr === false) {
            return null;
        }

        $certificate = @openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        if ($certificate === false) {
            return null;
        }

        $pem = '';
        if (!openssl_x509_export($certificate, $pem)) {
            return null;
        }

        return [$key, $pem];
    }
}
