<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailAmazon\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The provider still loads on an Email plugin from before inbound mail.
 *
 * That plugin fires `onEmailProviders` and has the provider contract, but no
 * `Providers\Inbound` namespace at all. A provider class naming a missing
 * interface would be a fatal error on every page of such a site, so this runs
 * in a separate PHP process whose autoloader serves the contract with the
 * inbound types left out, and checks the plugin's choice falls on the plain
 * provider, which loads, registers and answers there. Putting `InboundCapable`
 * back on {@see \Grav\Plugin\EmailAmazon\Provider\SesProvider} makes
 * this fail with a fatal error.
 */
final class OlderEmailPluginTest extends TestCase
{
    public function testTheProviderLoadsWhenTheEmailPluginHasNoInboundTypes(): void
    {
        $root = getenv('EMAIL_PLUGIN_ROOT');
        if (!\is_string($root) || trim($root) === '') {
            $root = \dirname(__DIR__, 3) . '/grav-plugin-email';
        }
        $contract = rtrim($root, '/') . '/classes/Providers';
        $classes = \dirname(__DIR__, 2) . '/classes';

        $script = <<<'PHP'
<?php
[$_, $contract, $classes] = $argv;
spl_autoload_register(static function (string $class) use ($contract, $classes): void {
    foreach (['Grav\\Plugin\\Email\\Providers\\' => $contract, 'Grav\\Plugin\\EmailAmazon\\' => $classes] as $prefix => $dir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = substr($class, strlen($prefix));
        if (str_starts_with($relative, 'Inbound\\')) {
            return; // an Email plugin from before inbound mail
        }
        $file = $dir . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
$class = interface_exists('Grav\\Plugin\\Email\\Providers\\Inbound\\InboundCapable')
    ? Grav\Plugin\EmailAmazon\Provider\SesInboundProvider::class
    : Grav\Plugin\EmailAmazon\Provider\SesProvider::class;
$provider = new $class();
$registry = new Grav\Plugin\Email\Providers\ProviderRegistry();
$registry->add($provider);
echo json_encode([
    'class' => get_class($provider),
    'key' => $registry->byKey('ses')?->key(),
    'label' => $provider->label(),
    'inbound' => interface_exists('Grav\\Plugin\\Email\\Providers\\Inbound\\InboundCapable'),
]);
PHP;

        $file = tempnam(sys_get_temp_dir(), 'ses-old');
        file_put_contents($file, $script);
        try {
            $output = shell_exec(escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg($file) . ' '
                . escapeshellarg($contract) . ' ' . escapeshellarg($classes) . ' 2>&1');
        } finally {
            @unlink($file);
        }

        self::assertSame(
            '{"class":"Grav\\\\Plugin\\\\EmailAmazon\\\\Provider\\\\SesProvider","key":"ses","label":"Amazon SES","inbound":false}',
            trim((string)$output)
        );
    }
}
