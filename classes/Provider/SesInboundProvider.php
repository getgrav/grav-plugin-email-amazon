<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailAmazon\Provider;

use Grav\Plugin\Email\Providers\Inbound\InboundCapable;

/**
 * {@see SesProvider}, marked as able to receive mail.
 *
 * Empty on purpose: `inbound()` is already on the parent. This class exists so
 * the interface is named only here, and the plugin only loads it when
 * `interface_exists(InboundCapable::class)` says the Email plugin has inbound
 * mail. On an older Email plugin the plain provider is registered instead and
 * sends exactly as before.
 */
final class SesInboundProvider extends SesProvider implements InboundCapable
{
}
