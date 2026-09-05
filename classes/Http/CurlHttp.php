<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailAmazon\Http;

/**
 * {@see Http} over cURL, with every switch that matters set explicitly.
 *
 * Moved from the KahunaCart newsletter add-on, where these calls used to live,
 * and widened from "get and post JSON" to "one request" because a signed AWS
 * request needs its own headers and its own method. The settings are not
 * defaults and are not decoration:
 *
 * - **HTTPS only.** `CURLPROTO_HTTPS` on both the request and its redirects.
 *   One of the two things this class fetches is a certificate that a signature
 *   is checked against, and fetching that over plain HTTP would make the
 *   signature check theatre.
 * - **Peer and host verification on.** Off by default in nobody's build, and
 *   worth stating anyway: this is the class where turning it off would be quiet
 *   and catastrophic.
 * - **Two redirects.** Enough for a provider that moved an endpoint, not enough
 *   to be walked around a network. Amazon's certificate URL does not redirect
 *   at all, and neither SNS nor SES redirects an API call.
 * - **A response cap.** A certificate is two kilobytes and an API answer is a
 *   few more. Ten megabytes of anything is somebody pointing this at a file
 *   server, and the write callback stops reading rather than filling memory.
 * - **Short timeouts.** The certificate fetch runs inside a request Amazon is
 *   waiting on, and a provider that waits too long retries — so the failure
 *   mode of a slow certificate host has to be a quick refusal rather than a
 *   queue of duplicate events.
 */
final class CurlHttp implements Http
{
    /** Seconds to connect. */
    public const CONNECT_TIMEOUT = 5;

    /** Seconds for the whole call. */
    public const TIMEOUT = 10;

    /** Bytes of response body kept before the transfer is abandoned. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    public function get(string $url): ?string
    {
        $answer = $this->send('GET', $url);

        return $answer['status'] >= 200 && $answer['status'] < 300 && $answer['raw'] !== ''
            ? $answer['raw']
            : null;
    }

    public function send(string $method, string $url, array $headers = [], string $body = ''): array
    {
        if (!\function_exists('curl_init')) {
            return ['status' => 0, 'raw' => '', 'error' => 'this installation has no cURL'];
        }

        if (!str_starts_with(strtolower(trim($url)), 'https://')) {
            return ['status' => 0, 'raw' => '', 'error' => 'only https addresses are fetched'];
        }

        $handle = curl_init($url);
        if ($handle === false) {
            return ['status' => 0, 'raw' => '', 'error' => 'the request could not be started'];
        }

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $received = '';

        curl_setopt_array($handle, [
            \CURLOPT_CUSTOMREQUEST => strtoupper($method),
            \CURLOPT_RETURNTRANSFER => false,
            \CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            \CURLOPT_TIMEOUT => self::TIMEOUT,
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_MAXREDIRS => 2,
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_SSL_VERIFYHOST => 2,
            \CURLOPT_PROTOCOLS => \CURLPROTO_HTTPS,
            \CURLOPT_REDIR_PROTOCOLS => \CURLPROTO_HTTPS,
            \CURLOPT_HTTPHEADER => $lines,
            \CURLOPT_WRITEFUNCTION => static function ($_, string $chunk) use (&$received): int {
                $received .= $chunk;

                // Returning fewer bytes than were handed over is how cURL is
                // told to stop, which is the point: a body over the cap is
                // abandoned rather than assembled and then thrown away.
                return \strlen($received) > self::MAX_BYTES ? 0 : \strlen($chunk);
            },
        ]);

        if ($body !== '') {
            curl_setopt($handle, \CURLOPT_POSTFIELDS, $body);
        }

        $ok = curl_exec($handle);
        $status = (int)curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        $error = $ok === false ? (string)curl_error($handle) : '';
        curl_close($handle);

        return ['status' => $status, 'raw' => $received, 'error' => $error];
    }
}
