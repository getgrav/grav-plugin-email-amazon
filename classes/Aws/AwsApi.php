<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailAmazon\Aws;

use Grav\Plugin\EmailAmazon\Http\CurlHttp;
use Grav\Plugin\EmailAmazon\Http\Http;

/**
 * The two AWS APIs this plugin calls, signed with the key the merchant already
 * pasted in.
 *
 * Both are behind the delivery-reports button and nothing else — no method here
 * runs while a settings screen is being drawn, and none of them runs while mail
 * is being sent. Sending is Symfony's bridge's job and this class has nothing
 * to do with it.
 *
 * ## Two protocols, because Amazon has two
 *
 * **SNS** is the old query protocol: everything is a form-encoded POST to `/`
 * with `Action` and `Version` in the body, and the answer is XML. It has not
 * changed since 2010 and is not going to.
 *
 * **SES v2** is REST with JSON bodies and resources in the path. The v1 API is
 * still there and still documented, and this deliberately uses v2 throughout,
 * because v1's event destinations and v2's are the same objects under two names
 * and mixing them is how a store ends up with two configuration sets.
 *
 * ## Failures are answers
 *
 * Nothing here throws. A refused key, a region that does not exist, a network
 * with no route out and a malformed answer are all an {@see AwsAnswer} with
 * `ok` false and Amazon's own words in `error`, because every one of them ends
 * up as a sentence on a button and the sentence is the useful part.
 */
final class AwsApi
{
    /** The SNS query API's version, which is the date it was frozen. */
    public const SNS_VERSION = '2010-03-31';

    /** A region is letters, digits and hyphens, and nothing else ever. */
    public const REGION_PATTERN = '/^[a-z0-9-]{1,32}$/i';

    private readonly Http $http;

    /** @var (callable(): int) */
    private $clock;

    /** @param (callable(): int)|null $clock */
    public function __construct(
        private readonly string $region,
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $sessionToken = '',
        ?Http $http = null,
        ?callable $clock = null,
    ) {
        $this->http = $http ?? new CurlHttp();
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** Whether there is enough here to make a call at all. */
    public function ready(): bool
    {
        return trim($this->accessKey) !== ''
            && trim($this->secretKey) !== ''
            && preg_match(self::REGION_PATTERN, trim($this->region)) === 1;
    }

    /** The region these calls are made in, tidied. */
    public function region(): string
    {
        return strtolower(trim($this->region));
    }

    /**
     * One SNS query-protocol action.
     *
     * @param array<string, string> $params everything but `Action` and `Version`
     */
    public function sns(string $action, array $params = []): AwsAnswer
    {
        if (!$this->ready()) {
            return AwsAnswer::failed(0, 'The AWS access key, secret key and region have to be filled in first.');
        }

        $host = 'sns.' . $this->region() . '.amazonaws.com';
        $body = SigV4::query(['Action' => $action, 'Version' => self::SNS_VERSION] + $params);

        $headers = SigV4::sign(
            'POST',
            $host,
            '/',
            [],
            ['Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8'],
            $body,
            'sns',
            $this->region(),
            trim($this->accessKey),
            trim($this->secretKey),
            $this->sessionToken,
            ($this->clock)(),
        );

        $answer = $this->http->send('POST', 'https://' . $host . '/', $headers, $body);

        return $this->readXml($action, $answer);
    }

    /**
     * One SES v2 REST call.
     *
     * @param list<string>              $segments path segments after the host, unencoded
     * @param array<string, mixed>|null $body     a JSON body, or null for none
     */
    public function ses(string $method, array $segments, ?array $body = null): AwsAnswer
    {
        if (!$this->ready()) {
            return AwsAnswer::failed(0, 'The AWS access key, secret key and region have to be filled in first.');
        }

        $host = 'email.' . $this->region() . '.amazonaws.com';
        $path = SigV4::path($segments);

        $json = '';
        if ($body !== null) {
            $encoded = json_encode($body, \JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                return AwsAnswer::failed(0, 'The request could not be encoded, which is a bug rather than a setting.');
            }

            $json = $encoded;
        }

        $headers = SigV4::sign(
            $method,
            $host,
            $path,
            [],
            $json === '' ? ['Accept' => 'application/json'] : ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            $json,
            'ses',
            $this->region(),
            trim($this->accessKey),
            trim($this->secretKey),
            $this->sessionToken,
            ($this->clock)(),
        );

        $answer = $this->http->send($method, 'https://' . $host . $path, $headers, $json);

        return $this->readJson($answer);
    }

    // ------------------------------------------------------------- internals

    /**
     * @param array{status: int, raw: string, error: string} $answer
     */
    private function readJson(array $answer): AwsAnswer
    {
        if ($answer['status'] === 0) {
            return AwsAnswer::failed(0, self::network($answer['error']));
        }

        $data = [];
        if (trim($answer['raw']) !== '') {
            try {
                $decoded = json_decode($answer['raw'], true, 32, \JSON_THROW_ON_ERROR);
                $data = \is_array($decoded) ? $decoded : [];
            } catch (\JsonException) {
                $data = [];
            }
        }

        if ($answer['status'] >= 200 && $answer['status'] < 300) {
            return AwsAnswer::of($answer['status'], $data);
        }

        $message = trim((string)($data['message'] ?? $data['Message'] ?? ''));
        $code = trim((string)($data['__type'] ?? $data['code'] ?? ''));

        // `__type` arrives as `com.amazonaws.ses#NotFoundException` about half
        // the time and as `NotFoundException` the rest, and only the tail is
        // ever useful.
        if (str_contains($code, '#')) {
            $code = substr($code, strrpos($code, '#') + 1);
        }

        return AwsAnswer::failed(
            $answer['status'],
            $message !== '' ? $message : sprintf('Amazon answered %d and said nothing else.', $answer['status']),
            $code,
        );
    }

    /**
     * @param array{status: int, raw: string, error: string} $answer
     */
    private function readXml(string $action, array $answer): AwsAnswer
    {
        if ($answer['status'] === 0) {
            return AwsAnswer::failed(0, self::network($answer['error']));
        }

        $xml = self::parse($answer['raw']);

        // Every SNS answer declares a default namespace, and SimpleXML will not
        // find a single child by name until it is told which one. Carrying it
        // through every read is the difference between an answer and silence.
        $namespace = $xml === null ? '' : (string)($xml->getDocNamespaces(false)[''] ?? '');

        if ($answer['status'] < 200 || $answer['status'] >= 300) {
            $error = $xml === null ? null : self::child($xml, 'Error', $namespace);
            $code = $error === null ? '' : trim((string)(self::child($error, 'Code', $namespace) ?? ''));
            $message = $error === null ? '' : trim((string)(self::child($error, 'Message', $namespace) ?? ''));

            return AwsAnswer::failed(
                $answer['status'],
                $message !== '' ? $message : sprintf('Amazon answered %d and said nothing else.', $answer['status']),
                $code,
            );
        }

        if ($xml === null) {
            return AwsAnswer::failed($answer['status'], 'Amazon answered something that was not XML.');
        }

        // The query protocol wraps every answer in `<XxxResponse><XxxResult>`,
        // and only the result is worth anything to a caller.
        $result = self::child($xml, $action . 'Result', $namespace);

        return AwsAnswer::of($answer['status'], $result === null ? [] : self::flatten($result, $namespace));
    }

    /** One named child, whichever namespace the document happens to declare. */
    private static function child(\SimpleXMLElement $element, string $name, string $namespace): ?\SimpleXMLElement
    {
        $children = $namespace === '' ? $element->children() : $element->children($namespace);
        $child = $children->{$name} ?? null;

        return $child instanceof \SimpleXMLElement && $child->getName() !== '' ? $child : null;
    }

    /** Parse XML with the network and external entities off, and answer null rather than warn. */
    private static function parse(string $raw): ?\SimpleXMLElement
    {
        if (trim($raw) === '' || !\function_exists('simplexml_load_string')) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($raw, \SimpleXMLElement::class, \LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $xml === false ? null : $xml;
    }

    /**
     * One level of an SNS result as a map or a list.
     *
     * The query protocol has two collection shapes and this reads both without
     * knowing which action it is looking at: a list is an element whose children
     * all have the same tag — `<member>` for a list of subscriptions, `<entry>`
     * for the key-and-value pairs of a topic's attributes — and everything else
     * is a map of tag to value.
     *
     * Enough for the four actions this plugin calls and no more.
     *
     * @return array<array-key, mixed>
     */
    private static function flatten(\SimpleXMLElement $element, string $namespace): array
    {
        $children = $namespace === '' ? $element->children() : $element->children($namespace);

        $names = [];
        foreach ($children as $name => $_) {
            $names[(string)$name] = true;
        }

        // `<member>` for a list of subscriptions, `<entry>` for the key-and-value
        // pairs of a topic's attributes. Both are lists even when there is one
        // of them, which is the case a count would get wrong.
        if ($names === ['member' => true] || $names === ['entry' => true]) {
            $list = [];
            foreach ($children as $child) {
                $list[] = self::hasChildren($child, $namespace)
                    ? self::flatten($child, $namespace)
                    : trim((string)$child);
            }

            return $list;
        }

        $out = [];
        foreach ($children as $name => $child) {
            $out[(string)$name] = self::hasChildren($child, $namespace)
                ? self::flatten($child, $namespace)
                : trim((string)$child);
        }

        return $out;
    }

    private static function hasChildren(\SimpleXMLElement $element, string $namespace): bool
    {
        $children = $namespace === '' ? $element->children() : $element->children($namespace);

        foreach ($children as $_) {
            return true;
        }

        return false;
    }

    private static function network(string $error): string
    {
        return $error === ''
            ? 'The request to Amazon did not get through.'
            : 'The request to Amazon did not get through: ' . $error;
    }
}
