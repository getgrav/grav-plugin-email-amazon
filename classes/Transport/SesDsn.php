<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailAmazon\Transport;

use Grav\Plugin\EmailAmazon\Provider\SesProvider;

/**
 * The Symfony DSN this plugin's settings become.
 *
 * `ses+<transport>://<key>:<secret>@default?region=<region>`, with the SMTP
 * transport taking the username and password pair and the other two the
 * access keys. Grav-free, so the one piece of the transport that is pure
 * arithmetic on the config can be read and tested without a booted site.
 */
final class SesDsn
{
    private function __construct()
    {
    }

    /** @param array<string, mixed> $options the `plugins.email-amazon` block */
    public static function from(array $options): string
    {
        $transport = (new SesProvider($options))->transport();
        $dsn = "ses+{$transport}://";
        if ($transport === SesProvider::TRANSPORT_SMTP) {
            $dsn .= urlencode((string)($options['username'] ?? '')) . ':' . urlencode((string)($options['password'] ?? ''));
        } else {
            $dsn .= urlencode((string)($options['access_key'] ?? '')) . ':' . urlencode((string)($options['secret_key'] ?? ''));
        }
        $dsn .= '@default';

        $region = trim((string)($options['region'] ?? ''));
        if ($region !== '') {
            $dsn .= '?region=' . urlencode($region);
        }

        return $dsn;
    }
}
