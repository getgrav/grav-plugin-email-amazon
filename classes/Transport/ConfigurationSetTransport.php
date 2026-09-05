<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailAmazon\Transport;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * The SES transport, with the configuration set stamped on every message.
 *
 * SES publishes a message's events — delivered, bounced, complained — only
 * when the message was sent with a configuration set, and it learns which one
 * from an `X-SES-CONFIGURATION-SET` header on the message. Symfony's SES
 * transports read that header and pass it on as the API's own field, so the
 * header is the one way to name a set that works on the API, HTTPS and SMTP
 * transports alike.
 *
 * Asking every sender to remember the header is asking for the one message
 * that matters to be the one that was sent without it. So this wraps whatever
 * transport the DSN built and adds the header itself, to every message that
 * has none, from the plugin's own `configuration_set` setting. A message that
 * already carries one — a sender who wants a different set for one campaign —
 * keeps it. A message with no headers at all, which is what a raw MIME string
 * is, goes through untouched.
 *
 * The name is stamped only when the setting is filled in. The delivery-report
 * setup creates a set under a default name when the setting is empty, but a
 * header naming a set that does not exist makes SES refuse the message, and
 * whether that default set exists yet is not something this code can see.
 */
final class ConfigurationSetTransport implements TransportInterface
{
    public const HEADER = 'X-SES-CONFIGURATION-SET';

    private readonly string $configurationSet;

    public function __construct(
        private readonly TransportInterface $inner,
        string $configurationSet,
    ) {
        $this->configurationSet = trim($configurationSet);
    }

    /** The set every message goes out with, or an empty string when none is stamped. */
    public function configurationSet(): string
    {
        return $this->configurationSet;
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($this->configurationSet !== '' && $message instanceof Message) {
            $headers = $message->getHeaders();
            if (!$headers->has(self::HEADER)) {
                $headers->addTextHeader(self::HEADER, $this->configurationSet);
            }
        }

        return $this->inner->send($message, $envelope);
    }

    public function __toString(): string
    {
        return (string)$this->inner;
    }
}
