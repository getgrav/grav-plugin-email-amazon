<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailAmazon\Tests\Support;

use Grav\Plugin\EmailAmazon\Http\Http;

/**
 * An {@see Http} that answers from a script and remembers what it was asked.
 *
 * The setup chain is six calls to two AWS APIs, and the useful assertions are
 * about the sequence rather than about any one request — that the topic policy
 * is set before the subscription, that pressing the button twice does not add a
 * second subscription, that a refusal on step two stops before step three. So
 * this keeps every request in order and matches answers on a needle in the URL
 * and body rather than on an exact request, which keeps a test about the chain
 * from being a test about a signature.
 */
final class FakeHttp implements Http
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    public array $requests = [];

    /** @var list<array{needle: string, status: int, body: string}> */
    private array $answers = [];

    private ?string $networkError = null;

    /** @var array<string, string> */
    private array $certificates = [];

    /**
     * Answer this status and body to the next request whose method, URL and
     * body together contain the needle.
     *
     * @param array<string, mixed>|string $body a JSON body, or XML written out
     */
    public function answer(string $needle, int $status, array|string $body = []): self
    {
        $this->answers[] = [
            'needle' => $needle,
            'status' => $status,
            'body' => \is_string($body) ? $body : (string)json_encode($body),
        ];

        return $this;
    }

    /** Every request from now on fails to leave the building. */
    public function unreachable(string $why = 'Could not resolve host'): self
    {
        $this->networkError = $why;

        return $this;
    }

    /** What {@see get()} answers for this URL. */
    public function serve(string $url, string $body): self
    {
        $this->certificates[$url] = $body;

        return $this;
    }

    public function get(string $url): ?string
    {
        $this->requests[] = ['method' => 'GET', 'url' => $url, 'headers' => [], 'body' => ''];

        return $this->certificates[$url] ?? null;
    }

    public function send(string $method, string $url, array $headers = [], string $body = ''): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        if ($this->networkError !== null) {
            return ['status' => 0, 'raw' => '', 'error' => $this->networkError];
        }

        $haystack = $method . ' ' . $url . ' ' . $body;

        foreach ($this->answers as $index => $answer) {
            if (str_contains($haystack, $answer['needle'])) {
                unset($this->answers[$index]);
                $this->answers = array_values($this->answers);

                return ['status' => $answer['status'], 'raw' => $answer['body'], 'error' => ''];
            }
        }

        return ['status' => 500, 'raw' => '', 'error' => 'nothing in this test answers ' . $haystack];
    }

    /** Every request as `METHOD url`, for asserting on the sequence. */
    public function trace(): array
    {
        return array_map(
            static fn (array $request): string => $request['method'] . ' ' . $request['url'],
            $this->requests,
        );
    }

    /** Whether any request's body contained this. */
    public function sent(string $needle): bool
    {
        foreach ($this->requests as $request) {
            if (str_contains($request['body'], $needle)) {
                return true;
            }
        }

        return false;
    }
}
