<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailAmazon\Tests\Unit;

use Grav\Plugin\EmailAmazon\Aws\SigV4;
use PHPUnit\Framework\TestCase;

/**
 * Amazon publishes a signature test suite, and the first case in it is the one
 * worth pinning.
 *
 * `get-vanilla` is a GET of `/` with no query, no body and one header, signed
 * for a fictional service in `us-east-1` with the example key AWS uses
 * throughout its own documentation. Every SDK on earth verifies against it, and
 * matching it byte for byte is the difference between "this signer looks right"
 * and "this signer is right".
 *
 * The rest of the tests here are the four things a hand-written signer gets
 * wrong: the query sorted after encoding rather than before, a path encoded
 * once so the signature and the wire agree, a session token that has to be
 * signed rather than sent alongside, and an empty body that still hashes.
 */
final class SigV4Test extends TestCase
{
    /** AWS's own example key, from its documentation. It signs nothing real. */
    private const ACCESS_KEY = 'AKIDEXAMPLE';
    private const SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

    public function testItMatchesAmazonsPublishedGetVanillaVector(): void
    {
        $headers = SigV4::sign(
            'GET',
            'example.amazonaws.com',
            '/',
            [],
            [],
            '',
            'service',
            'us-east-1',
            self::ACCESS_KEY,
            self::SECRET_KEY,
            '',
            (int)strtotime('2015-08-30T12:36:00Z'),
        );

        self::assertSame('20150830T123600Z', $headers['X-Amz-Date']);
        self::assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, '
            . 'SignedHeaders=host;x-amz-date, '
            . 'Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
            $headers['Authorization'],
        );
    }

    /**
     * The canonical query is sorted by the **encoded** name, and every part is
     * RFC 3986 encoded rather than form encoded.
     *
     * A space that arrives as `+` instead of `%20` is a signature that does not
     * match, and it only ever bites on the one topic name with a space in it.
     */
    public function testTheQueryIsEncodedThenSorted(): void
    {
        self::assertSame(
            'Action=CreateTopic&Name=grav%20email&Version=2010-03-31',
            SigV4::query(['Name' => 'grav email', 'Version' => '2010-03-31', 'Action' => 'CreateTopic']),
        );

        self::assertSame('', SigV4::query([]));
    }

    /**
     * A path is built from segments and encoded once, here, so that the bytes
     * signed and the bytes sent cannot drift apart.
     */
    public function testThePathIsEncodedOncePerSegment(): void
    {
        self::assertSame(
            '/v2/email/configuration-sets/grav%20email/event-destinations',
            SigV4::path(['v2', 'email', 'configuration-sets', 'grav email', 'event-destinations']),
        );

        // A slash inside a segment is part of the segment, not a separator, and
        // encoding it is what stops a crafted name walking up the path.
        self::assertSame('/v2/email/identities/a%2Fb', SigV4::path(['v2', 'email', 'identities', 'a/b']));

        self::assertSame('/', SigV4::path([]));
    }

    /**
     * A temporary credential's token is signed, not sent beside the signature.
     *
     * Leaving it out gives a signature error rather than an auth error, which
     * sends people looking at their clock instead of at their role.
     */
    public function testASessionTokenIsPartOfWhatIsSigned(): void
    {
        $headers = SigV4::sign(
            'POST',
            'sns.us-east-1.amazonaws.com',
            '/',
            [],
            [],
            'Action=CreateTopic',
            'sns',
            'us-east-1',
            self::ACCESS_KEY,
            self::SECRET_KEY,
            'a-session-token',
            (int)strtotime('2015-08-30T12:36:00Z'),
        );

        self::assertSame('a-session-token', $headers['X-Amz-Security-Token']);
        self::assertStringContainsString('x-amz-security-token', $headers['Authorization']);

        $without = SigV4::sign(
            'POST',
            'sns.us-east-1.amazonaws.com',
            '/',
            [],
            [],
            'Action=CreateTopic',
            'sns',
            'us-east-1',
            self::ACCESS_KEY,
            self::SECRET_KEY,
            '',
            (int)strtotime('2015-08-30T12:36:00Z'),
        );

        self::assertArrayNotHasKey('X-Amz-Security-Token', $without);
        self::assertNotSame($without['Authorization'], $headers['Authorization']);
    }

    /** An empty body hashes to the SHA-256 of nothing, which is why there is no special case for it. */
    public function testAnEmptyBodyStillHashes(): void
    {
        self::assertSame(hash('sha256', ''), SigV4::EMPTY_PAYLOAD);
    }

    /** A body that differs by one byte is a different signature, which is the whole point. */
    public function testTheBodyIsSigned(): void
    {
        $sign = static fn (string $body): string => SigV4::sign(
            'POST',
            'sns.us-east-1.amazonaws.com',
            '/',
            [],
            [],
            $body,
            'sns',
            'us-east-1',
            self::ACCESS_KEY,
            self::SECRET_KEY,
            '',
            (int)strtotime('2015-08-30T12:36:00Z'),
        )['Authorization'];

        self::assertNotSame($sign('Action=CreateTopic'), $sign('Action=DeleteTopic'));
    }

    /**
     * Header values are folded and the list is sorted by the lower-cased name,
     * so the same request signed with the headers in another order signs the
     * same.
     */
    public function testHeaderOrderAndSpacingDoNotChangeTheSignature(): void
    {
        $one = SigV4::sign(
            'POST',
            'email.us-east-1.amazonaws.com',
            '/v2/email/configuration-sets',
            [],
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            '{}',
            'ses',
            'us-east-1',
            self::ACCESS_KEY,
            self::SECRET_KEY,
            '',
            (int)strtotime('2015-08-30T12:36:00Z'),
        );

        $two = SigV4::sign(
            'POST',
            'email.us-east-1.amazonaws.com',
            '/v2/email/configuration-sets',
            [],
            ['accept' => '  application/json  ', 'CONTENT-TYPE' => 'application/json'],
            '{}',
            'ses',
            'us-east-1',
            self::ACCESS_KEY,
            self::SECRET_KEY,
            '',
            (int)strtotime('2015-08-30T12:36:00Z'),
        );

        self::assertSame($one['Authorization'], $two['Authorization']);
    }
}
