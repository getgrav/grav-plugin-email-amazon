<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailAmazon\Http;

/**
 * The outbound requests this plugin makes, behind one seam.
 *
 * There are two kinds and only two. Fetching Amazon's SNS signing certificate,
 * which happens while a delivery webhook is being verified, and the handful of
 * signed AWS API calls behind the delivery-reports button. Both are the sort of
 * thing a test must be able to answer for itself: a suite that reached the
 * network would be a suite that failed on a train, and one of these two runs
 * inside a request a provider is waiting on.
 *
 * Deliberately small. No redirect policy to configure, no streaming, no header
 * juggling, because none of the calls needs any of it and every one of those
 * would be another thing to get wrong in the class that talks to the outside.
 */
interface Http
{
    /**
     * Fetch a URL, or answer null.
     *
     * Null for every kind of failure — a refused connection, a 404, a
     * certificate that did not check out, a body over the cap. Every caller
     * treats null the same way, which is to refuse whatever it was about to do.
     */
    public function get(string $url): ?string;

    /**
     * One request, with the headers and body given, and whatever came back.
     *
     * Never throws. `status` is 0 when the request never reached a server, and
     * `error` then says why in words a person can read.
     *
     * @param array<string, string> $headers
     * @return array{status: int, raw: string, error: string}
     */
    public function send(string $method, string $url, array $headers = [], string $body = ''): array;
}
