<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailAmazon\Tests\Unit;

use Grav\Plugin\EmailAmazon\Transport\ConfigurationSetTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * The configuration set reaches every message as the header SES reads it from.
 *
 * Symfony's SES transports turn `X-SES-CONFIGURATION-SET` into the API's own
 * field, so the header on the message is the whole of what this plugin has to
 * get right; these tests read it back off the message the inner transport was
 * handed.
 */
final class ConfigurationSetTransportTest extends TestCase
{
    public function testTheSetIsStampedOnAMessageThatHasNone(): void
    {
        $inner = $this->inner();
        $transport = new ConfigurationSetTransport($inner, ' grav-email ');

        $transport->send($this->email());

        self::assertSame('grav-email', $transport->configurationSet(), 'trimmed');
        self::assertSame('grav-email', $inner->sent[0]->getHeaders()->get(ConfigurationSetTransport::HEADER)->getBodyAsString());
    }

    public function testAMessageThatNamesItsOwnSetKeepsIt(): void
    {
        $inner = $this->inner();
        $email = $this->email();
        $email->getHeaders()->addTextHeader(ConfigurationSetTransport::HEADER, 'one-campaign');

        (new ConfigurationSetTransport($inner, 'grav-email'))->send($email);

        self::assertSame('one-campaign', $inner->sent[0]->getHeaders()->get(ConfigurationSetTransport::HEADER)->getBodyAsString());
        self::assertCount(1, iterator_to_array($inner->sent[0]->getHeaders()->all(ConfigurationSetTransport::HEADER), false), 'not stamped twice');
    }

    public function testAnEmptySettingStampsNothing(): void
    {
        $inner = $this->inner();

        (new ConfigurationSetTransport($inner, '  '))->send($this->email());

        self::assertFalse($inner->sent[0]->getHeaders()->has(ConfigurationSetTransport::HEADER));
    }

    /** A raw MIME string has no header object to add to, and goes through as it came. */
    public function testARawMessageGoesThroughUntouched(): void
    {
        $inner = $this->inner();
        $raw = new RawMessage("Subject: hi\r\n\r\nhello");

        (new ConfigurationSetTransport($inner, 'grav-email'))->send($raw);

        self::assertSame($raw, $inner->sent[0]);
    }

    public function testTheEnvelopeAndTheNameAreTheInnerTransports(): void
    {
        $inner = $this->inner();
        $envelope = new Envelope(new Address('shop@example.com'), [new Address('somebody@example.com')]);

        $transport = new ConfigurationSetTransport($inner, 'grav-email');
        $transport->send($this->email(), $envelope);

        self::assertSame($envelope, $inner->envelopes[0]);
        self::assertSame('ses+https://default', (string)$transport);
    }

    private function email(): Email
    {
        return (new Email())
            ->from('shop@example.com')
            ->to('somebody@example.com')
            ->subject('Your order')
            ->text('Thanks.');
    }

    /** @return TransportInterface&object{sent: list<RawMessage>, envelopes: list<?Envelope>} */
    private function inner(): TransportInterface
    {
        return new class implements TransportInterface {
            /** @var list<RawMessage> */
            public array $sent = [];
            /** @var list<?Envelope> */
            public array $envelopes = [];

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $this->sent[] = $message;
                $this->envelopes[] = $envelope;

                return null;
            }

            public function __toString(): string
            {
                return 'ses+https://default';
            }
        };
    }
}
