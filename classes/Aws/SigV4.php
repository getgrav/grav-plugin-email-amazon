<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailAmazon\Aws;

/**
 * Amazon's Signature Version 4, in about eighty lines of arithmetic.
 *
 * ## Why this is written out rather than borrowed
 *
 * The plugin already ships `async-aws/core` — it comes in with Symfony's SES
 * bridge and is what actually sends the mail — and that package contains a
 * perfectly good `SignerV4`. It is not used here, and the reason is worth
 * writing down rather than rediscovering: every class the signing path would
 * need from it (`Request`, `RequestContext`, `Stream\StringStream`, the signer
 * itself) is marked `@internal` in its own docblock. Building a plugin's
 * webhook setup on another package's internals means a patch release of a
 * transitive dependency can break a button. Signature Version 4 is a fixed,
 * published algorithm that has not changed since 2012 and is four HMACs and a
 * string; borrowing internals to avoid writing it is the more expensive of the
 * two.
 *
 * No new dependency either way, which is the constraint that matters: this
 * plugin's `vendor/` holds only what Symfony's bridge needs, and it stays that
 * way.
 *
 * ## What it signs
 *
 * Two shapes of request, both plain HTTPS:
 *
 * - **SNS**, which is the old query protocol: a form-encoded body against `/`
 *   with `Action` and `Version` in it, service name `sns`.
 * - **SES v2**, which is REST with JSON bodies against paths like
 *   `/v2/email/configuration-sets/grav`, service name `ses`.
 *
 * Neither needs the streaming or chunked variants, so the payload is always
 * hashed whole.
 *
 * ## The parts people get wrong
 *
 * - **Header values are trimmed and folded**, and the header list is sorted by
 *   the lower-cased name. `Host` must be in it; `X-Amz-Date` must be in it.
 * - **The canonical path is the encoded path**, and it has to be the same
 *   bytes that go on the wire. So the caller hands over path segments and
 *   {@see path()} encodes them once, here, for both uses. A path built one way
 *   for the signature and another for the request is the failure that reads as
 *   "the request signature we calculated does not match".
 * - **The query string is sorted by name** after encoding, not before.
 * - **An empty body still hashes**, to the SHA-256 of the empty string, which
 *   is why there is no special case for it.
 */
final class SigV4
{
    public const ALGORITHM = 'AWS4-HMAC-SHA256';

    /** The SHA-256 of no bytes at all, which is what an empty body hashes to. */
    public const EMPTY_PAYLOAD = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private function __construct()
    {
    }

    /**
     * The headers to send, with `Authorization` and `X-Amz-Date` added.
     *
     * @param string                $method  GET, POST, PUT or DELETE
     * @param string                $host    the API host, e.g. `sns.us-east-1.amazonaws.com`
     * @param string                $path    the path, already encoded by {@see path()}
     * @param array<string, string> $query   query parameters, unencoded
     * @param array<string, string> $headers whatever the caller is already sending
     * @param string                $body    the raw body, byte for byte
     * @param int|null              $now     the clock, for a test that wants a fixed signature
     * @return array<string, string>
     */
    public static function sign(
        string $method,
        string $host,
        string $path,
        array $query,
        array $headers,
        string $body,
        string $service,
        string $region,
        string $accessKey,
        string $secretKey,
        string $sessionToken = '',
        ?int $now = null,
    ): array {
        $now ??= time();
        $stamp = gmdate('Ymd\THis\Z', $now);
        $date = gmdate('Ymd', $now);

        $headers['Host'] = $host;
        $headers['X-Amz-Date'] = $stamp;

        if (trim($sessionToken) !== '') {
            // A temporary credential's token is part of the signed headers, not
            // an extra sent alongside them. A key from an assumed role fails
            // with a signature error rather than an auth error when this is
            // left out, which sends people looking in the wrong place.
            $headers['X-Amz-Security-Token'] = trim($sessionToken);
        }

        $canonicalHeaders = [];
        foreach ($headers as $name => $value) {
            $canonicalHeaders[strtolower(trim($name))] = self::fold($value);
        }
        ksort($canonicalHeaders);

        $signedHeaders = implode(';', array_keys($canonicalHeaders));

        $canonicalHeaderBlock = '';
        foreach ($canonicalHeaders as $name => $value) {
            $canonicalHeaderBlock .= $name . ':' . $value . "\n";
        }

        $canonicalRequest = strtoupper($method) . "\n"
            . ($path === '' ? '/' : $path) . "\n"
            . self::query($query) . "\n"
            . $canonicalHeaderBlock . "\n"
            . $signedHeaders . "\n"
            . ($body === '' ? self::EMPTY_PAYLOAD : hash('sha256', $body));

        $scope = $date . '/' . $region . '/' . $service . '/aws4_request';

        $stringToSign = self::ALGORITHM . "\n"
            . $stamp . "\n"
            . $scope . "\n"
            . hash('sha256', $canonicalRequest);

        $signature = hash_hmac('sha256', $stringToSign, self::signingKey($secretKey, $date, $region, $service));

        $headers['Authorization'] = self::ALGORITHM
            . ' Credential=' . $accessKey . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;

        return $headers;
    }

    /**
     * A path built from segments, encoded once, for the signature and the wire.
     *
     * @param list<string> $segments e.g. `['v2', 'email', 'configuration-sets', 'my set']`
     */
    public static function path(array $segments): string
    {
        $encoded = [];
        foreach ($segments as $segment) {
            $segment = (string)$segment;
            if ($segment === '') {
                continue;
            }

            $encoded[] = rawurlencode($segment);
        }

        return '/' . implode('/', $encoded);
    }

    /**
     * The canonical query string: encoded, then sorted by name.
     *
     * @param array<string, string> $query
     */
    public static function query(array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            $pairs[rawurlencode((string)$name)] = rawurlencode((string)$value);
        }
        ksort($pairs);

        $out = [];
        foreach ($pairs as $name => $value) {
            $out[] = $name . '=' . $value;
        }

        return implode('&', $out);
    }

    /** The four chained HMACs that turn a secret into a key for one day, one region and one service. */
    private static function signingKey(string $secretKey, string $date, string $region, string $service): string
    {
        $key = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
        $key = hash_hmac('sha256', $region, $key, true);
        $key = hash_hmac('sha256', $service, $key, true);

        return hash_hmac('sha256', 'aws4_request', $key, true);
    }

    /** A header value with its edges trimmed and its runs of spaces collapsed, which is what AWS signs. */
    private static function fold(string $value): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $value));
    }
}
