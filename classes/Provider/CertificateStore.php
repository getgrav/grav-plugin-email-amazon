<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailAmazon\Provider;

/**
 * Amazon's signing certificates, kept on disk so every event does not fetch one.
 *
 * Moved from the KahunaCart newsletter add-on, where this used to live under
 * `classes/Providers/`, unchanged apart from its namespace and where the files
 * are written.
 *
 * SNS signs every message with a certificate whose URL travels in the message,
 * and Amazon rotates that certificate. A busy campaign produces one delivery
 * event per recipient, and fetching a two-kilobyte certificate forty thousand
 * times over an afternoon would turn a webhook receiver into an outbound
 * request storm — and would put a network round trip in front of every event a
 * store records.
 *
 * So the file is kept, named by the SHA-256 of the URL it came from. That
 * naming is doing real work: **the file name can never be influenced by the
 * URL's own characters**, so a crafted `SigningCertURL` cannot write outside
 * the directory or overwrite something else. The host rule in
 * {@see SesReports} refuses anything that is not an Amazon SNS host long before
 * this class sees a URL, and this is the second lock on the same door.
 *
 * Nothing here decides whether a certificate is *trustworthy*. It stores bytes
 * that were fetched from an approved host over verified HTTPS and hands them
 * back. Whether they parse as a certificate, and whether a signature checks out
 * against them, is {@see SesReports}'s question.
 *
 * ## Expiry
 *
 * Thirty days, which is well inside Amazon's rotation and long enough that a
 * store sending weekly fetches one certificate a month. A cached file that is
 * older is refetched rather than deleted, so a fetch that fails on a day Amazon
 * is unreachable still has yesterday's certificate to fall back to — a
 * certificate does not stop being the one that signed a message because a
 * calendar rolled over.
 */
final class CertificateStore
{
    /** How long a cached certificate is used without being refetched. */
    public const FRESH_FOR = 2592000;

    /** Nothing bigger than this is a certificate. */
    public const MAX_BYTES = 65536;

    /** @var (callable(): int) */
    private $clock;

    /** @param (callable(): int)|null $clock */
    public function __construct(
        private readonly string $directory,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * The certificate for this URL, fetching it when there is no fresh copy.
     *
     * @param callable(string): (string|null) $fetch how to get one, which is
     *        {@see \Grav\Plugin\EmailAmazon\Http\Http::get()} in production and
     *        a closure in a test
     */
    public function get(string $url, callable $fetch): ?string
    {
        $path = $this->pathFor($url);
        $cached = $this->read($path);

        if ($cached !== null && $cached['age'] <= self::FRESH_FOR) {
            return $cached['pem'];
        }

        $fetched = $fetch($url);

        if (\is_string($fetched) && trim($fetched) !== '' && \strlen($fetched) <= self::MAX_BYTES) {
            $this->write($path, $fetched);

            return $fetched;
        }

        // The fetch failed and there is a stale copy. Amazon rotates
        // certificates; it does not un-sign messages with the old one. A stale
        // certificate that verifies is a verified message.
        return $cached['pem'] ?? null;
    }

    /** Where this URL's certificate is kept. Never derived from its characters. */
    public function pathFor(string $url): string
    {
        return rtrim($this->directory, '/') . '/sns-' . hash('sha256', $url) . '.pem';
    }

    // ------------------------------------------------------------- internals

    /** @return array{pem: string, age: int}|null */
    private function read(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $pem = @file_get_contents($path);
        if ($pem === false || trim($pem) === '') {
            return null;
        }

        $written = @filemtime($path);

        return ['pem' => $pem, 'age' => $written === false ? \PHP_INT_MAX : max(0, ($this->clock)() - $written)];
    }

    private function write(string $path, string $pem): void
    {
        $directory = \dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            return;
        }

        // Written to a neighbour and renamed, so a second worker reading the
        // file while this one writes it never sees half a certificate. Rename
        // is atomic within a filesystem on every platform this runs on.
        $temporary = $path . '.' . bin2hex(random_bytes(4));

        if (@file_put_contents($temporary, $pem) === false) {
            return;
        }

        @chmod($temporary, 0640);

        if (!@rename($temporary, $path)) {
            @unlink($temporary);
        }
    }
}
