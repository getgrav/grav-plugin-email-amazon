<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailAmazon\Aws;

/**
 * What came back from one AWS call, in the two forms anything here cares about.
 *
 * `data` is the answer flattened to a map — the JSON object for SES, the useful
 * fields lifted out of the XML for SNS — and `error` is Amazon's own sentence
 * when the call was refused. That sentence is the whole reason this class
 * exists rather than a bare status code: "The security token included in the
 * request is invalid" and "User is not authorized to perform sns:CreateTopic"
 * send a merchant to two completely different places, and both arrive as a 403.
 */
final class AwsAnswer
{
    /**
     * @param array<string, mixed> $data
     * @param string $code Amazon's own error code, e.g. `NotFound`, `AuthorizationError`
     */
    public function __construct(
        public readonly bool $ok,
        public readonly int $status = 0,
        public readonly array $data = [],
        public readonly string $error = '',
        public readonly string $code = '',
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function of(int $status, array $data): self
    {
        return new self(true, $status, $data);
    }

    public static function failed(int $status, string $error, string $code = ''): self
    {
        return new self(false, $status, [], $error, $code);
    }

    /** Whether the call was refused because the key is not allowed to do this. */
    public function denied(): bool
    {
        if ($this->status === 403) {
            return true;
        }

        $code = strtolower($this->code);

        return str_contains($code, 'accessdenied')
            || str_contains($code, 'authorizationerror')
            || str_contains($code, 'notauthorized');
    }

    /** Whether the thing being asked about is simply not there yet. */
    public function missing(): bool
    {
        return $this->status === 404 || str_contains(strtolower($this->code), 'notfound');
    }

    /** One field out of the answer as a string. */
    public function string(string $key): string
    {
        $value = $this->data[$key] ?? '';

        return is_scalar($value) ? trim((string)$value) : '';
    }
}
