<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Grav;
use Grav\Common\Plugin;
use Grav\Plugin\EmailAmazon\Http\CurlHttp;
use Grav\Plugin\EmailAmazon\Provider\CertificateStore;
use Grav\Plugin\EmailAmazon\Provider\SesProvider;
use Grav\Plugin\EmailAmazon\Transport\ConfigurationSetTransport;
use Grav\Plugin\EmailAmazon\Transport\SesDsn;
use Symfony\Component\Mailer\Transport;
use RocketTheme\Toolbox\Event\Event;

/**
 * Class EmailAmazonPlugin
 * @package Grav\Plugin
 */
class EmailAmazonPlugin extends Plugin
{
    /** Where Amazon's SNS signing certificates are kept, under Grav's own data directory. */
    public const CERTIFICATE_DIRECTORY = 'email-amazon';

    /**
     * @return array
     *
     * The getSubscribedEvents() gives the core a list of events
     *     that the plugin wants to listen to. The key of each
     *     array section is the event that the plugin listens to
     *     and the value (in the form of an array) contains the
     *     callable (or function) as well as the priority. The
     *     higher the number the higher the priority.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onEmailEngines'       => ['onEmailEngines', 0],
            'onEmailTransportDsn'  => ['onEmailTransportDsn', 0],
            'onEmailProviders'     => ['onEmailProviders', 0],
        ];
    }

    /**
     * Composer autoload
     *
     * @return ClassLoader
     */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    public function onEmailEngines(Event $e)
    {
        $engines = $e['engines'];
        $engines->amazon = 'Amazon SES';
    }

    /**
     * Register what this plugin knows about Amazon SES with the Email plugin.
     *
     * How its delivery notifications are verified and read, how the whole SNS
     * chain is set up from the key that already sends the mail, what Amazon
     * needs a sending domain's DNS to say, and what each of the three
     * transports does to a custom header on the way out. All of it used to sit
     * in whichever add-on needed the answer first; it belongs here, because
     * this is the only code that knows what this plugin does to a message.
     *
     * The event does not exist on an Email plugin older than the one that
     * introduced the contract, so an out-of-date site simply never calls this
     * and nothing here is ever loaded.
     *
     * The PHP check is not decoration either. The contract's classes and this
     * plugin's provider use readonly promoted properties, which is PHP 8.1,
     * while this plugin still installs on the 7.3 that Grav 1.7 allows. On such
     * a site the honest answer is that there is no provider here, and answering
     * it before anything is named means the autoloader is never sent at a file
     * this copy of PHP cannot parse.
     */
    public function onEmailProviders(Event $e): void
    {
        if (PHP_VERSION_ID < 80100) {
            return;
        }

        $registry = $e['providers'] ?? null;
        if (!is_object($registry) || !method_exists($registry, 'add')) {
            return;
        }

        $http = new CurlHttp();

        $registry->add(new SesProvider(
            (array)$this->config->get('plugins.email-amazon', []),
            new CertificateStore($this->certificateDirectory()),
            $http,
        ));
    }

    /**
     * Where the SNS signing certificates go.
     *
     * Under Grav's own user data directory, which is writable, outside the web
     * root and backed up with the rest of a site's state. The files are cached
     * public certificates and nothing else — no key material, nothing secret —
     * but a certificate that has to be refetched on every one of forty thousand
     * delivery events is an outbound request storm, which is what this avoids.
     */
    protected function certificateDirectory(): string
    {
        $root = null;

        try {
            $root = Grav::instance()['locator']->findResource('user-data://', true);
        } catch (\Throwable $e) {
            $root = null;
        }

        if (!is_string($root) || $root === '') {
            $root = rtrim(defined('GRAV_ROOT') ? GRAV_ROOT : getcwd(), '/') . '/user/data';
        }

        return rtrim($root, '/') . '/' . self::CERTIFICATE_DIRECTORY;
    }

    /**
     * The transport for the `amazon` engine.
     *
     * The DSN is Symfony's `ses+<transport>://` with this plugin's credentials
     * in it, and the transport it builds is handed back wrapped in
     * {@see ConfigurationSetTransport}, which stamps the configuration set on
     * every message so SES publishes its events. The Email plugin has taken a
     * transport object in place of a DSN string since 4.0.
     *
     * With no transport chosen it is HTTPS: the same API as `api`, sending the
     * whole message rather than its parts, so custom headers arrive — which is
     * the difference between a newsletter with an unsubscribe button and one
     * without.
     */
    public function onEmailTransportDsn(Event $e)
    {
        $engine = $e['engine'];
        if ($engine === 'amazon' || $engine === 'ses') {
            $options = (array)$this->config->get('plugins.email-amazon');
            $dsn = SesDsn::from($options);
            $set = trim((string)($options['configuration_set'] ?? ''));

            $e['dsn'] = $set === ''
                ? $dsn
                : new ConfigurationSetTransport(Transport::fromDsn($dsn), $set);
            $e->stopPropagation();
        }
    }

}
