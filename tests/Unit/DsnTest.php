<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailAmazon\Tests\Unit;

use Grav\Plugin\EmailAmazon\Transport\SesDsn;
use PHPUnit\Framework\TestCase;

/** The Symfony DSN this plugin's settings become. */
final class DsnTest extends TestCase
{
    public function testNothingChosenIsHttpsWithTheAccessKeys(): void
    {
        self::assertSame(
            'ses+https://AKIA:s%2Fecret@default?region=eu-west-1',
            SesDsn::from(['access_key' => 'AKIA', 'secret_key' => 's/ecret', 'region' => 'eu-west-1'])
        );
    }

    public function testApiKeepsItsKeysAndSmtpUsesThePair(): void
    {
        self::assertSame(
            'ses+api://AKIA:secret@default',
            SesDsn::from(['transport' => 'api', 'access_key' => 'AKIA', 'secret_key' => 'secret', 'region' => ''])
        );
        self::assertSame(
            'ses+smtp://user:pass@default?region=us-east-1',
            SesDsn::from(['transport' => 'smtp', 'username' => 'user', 'password' => 'pass', 'access_key' => 'AKIA', 'region' => 'us-east-1'])
        );
    }
}
