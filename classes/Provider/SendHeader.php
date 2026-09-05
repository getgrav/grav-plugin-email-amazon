<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailAmazon\Provider;

/**
 * The store's own header, going out and coming back.
 *
 * A store that wants to hear about one particular message puts a header on it
 * and asks the provider to hand it back. The name is answered by
 * {@see SesReports::sendHeader()} so that the store and this plugin cannot
 * disagree about it, and this is the reading end: finding that header again in
 * the two places Amazon might have put it.
 *
 * ## Where Amazon puts it
 *
 * **`mail.headers[]`**, as a `{name, value}` pair, repeating the header exactly
 * as it was written. That is the ordinary path and the one worth relying on.
 *
 * **`mail.tags`**, as `{name: [value]}`, for a store that sends with
 * `X-SES-MESSAGE-TAGS`. This plugin does not set message tags and nothing here
 * asks a store to; it is read because it is the one place a send id survives
 * `headersTruncated`, which is Amazon cutting the header list short once the
 * original headers went over 10 KB.
 *
 * Header names are matched case insensitively, because a header name is case
 * insensitive on the wire and because a payload replayed through a tool that
 * lower-cased everything is still the same payload.
 *
 * ## What comes back is a string
 *
 * Whatever the store stamped, trimmed, and nothing more. This plugin has no
 * idea what a store's send id looks like and should not pretend to: a store
 * that keeps integer row ids turns it into one at its own end, and a store
 * using a UUID keeps a UUID. What is refused is only the two things that are
 * never an id — an empty value and one long enough to be somebody filling a log
 * with it.
 */
final class SendHeader
{
    /**
     * The header this plugin looks for unless it is told another.
     *
     * KahunaCart's newsletter add-on is what stamps it today, and this is the
     * name it has used since it had delivery reports at all. It is a constructor
     * argument on {@see SesReports} rather than a hard-coded string so that a
     * store with its own name for it is a line of configuration rather than a
     * fork.
     */
    public const DEFAULT_HEADER = 'X-KahunaCart-Send';

    /** Longer than this is not an id, it is somebody filling a log. */
    public const MAX_LENGTH = 190;

    private function __construct()
    {
    }

    /**
     * One header's value out of a `{name, value}` list, the form SES uses.
     *
     * @param mixed $headers anything; a non-list answers null
     */
    public static function inList(mixed $headers, string $name): ?string
    {
        if (!\is_array($headers)) {
            return null;
        }

        $wanted = strtolower(trim($name));

        foreach ($headers as $header) {
            if (!\is_array($header)) {
                continue;
            }

            if (strtolower(trim((string)($header['name'] ?? ''))) === $wanted) {
                $value = self::clean($header['value'] ?? null);

                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * One value out of SES message tags, which are `{name: [value]}`.
     *
     * @param mixed $tags anything; a non-map answers null
     */
    public static function inTags(mixed $tags, string $name): ?string
    {
        if (!\is_array($tags)) {
            return null;
        }

        $wanted = strtolower(trim($name));

        foreach ($tags as $tag => $values) {
            if (!\is_string($tag) || strtolower(trim($tag)) !== $wanted) {
                continue;
            }

            $value = self::clean(\is_array($values) ? ($values[0] ?? null) : $values);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private static function clean(mixed $value): ?string
    {
        if (!\is_string($value) && !\is_int($value) && !\is_float($value)) {
            return null;
        }

        $value = trim((string)$value);

        return $value === '' || \strlen($value) > self::MAX_LENGTH ? null : $value;
    }
}
