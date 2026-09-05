<?php

declare(strict_types=1);

/**
 * The plugin ships its own vendor directory, and that directory holds what
 * Symfony's Amazon bridge needs and nothing else. Installing PHPUnit into it
 * would put development packages into the released plugin, so the suite keeps
 * its own composer.json and its own vendor directory here under tests/ instead.
 * Run `composer install -d tests` once, then `phpunit` from the repository root.
 *
 * The provider classes implement interfaces that live in the Email plugin, so
 * the suite needs that plugin's `classes/` on the path as well. Where it is
 * comes from the `EMAIL_PLUGIN_ROOT` environment variable, and falls back to a
 * sibling checkout — which is what a plugins directory looks like on a real
 * site and what a workspace usually looks like too. A worktree is the case that
 * needs the variable:
 *
 *     EMAIL_PLUGIN_ROOT=~/Projects/grav/grav-plugin-email vendor/bin/phpunit
 */
$autoload = __DIR__ . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "The test dependencies are not installed. Run: composer install -d tests\n");
    exit(1);
}

require $autoload;

$emailRoot = getenv('EMAIL_PLUGIN_ROOT');
if (!is_string($emailRoot) || trim($emailRoot) === '') {
    $emailRoot = \dirname(__DIR__, 2) . '/grav-plugin-email';
}

$emailRoot = rtrim((string)$emailRoot, '/');

if (!is_dir($emailRoot . '/classes/Providers')) {
    fwrite(STDERR, sprintf(
        "The Email plugin's provider contract was not found at %s.\n"
        . "Set EMAIL_PLUGIN_ROOT to a checkout of grav-plugin-email whose develop has classes/Providers.\n",
        $emailRoot
    ));
    exit(1);
}

spl_autoload_register(static function (string $class) use ($emailRoot): void {
    $prefix = 'Grav\\Plugin\\Email\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = $emailRoot . '/classes/' . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';

    if (is_file($path)) {
        require $path;
    }
});
