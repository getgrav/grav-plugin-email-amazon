# Email Amazon SES Plugin

The **Email Amazon** Plugin is an extension for [Grav CMS](https://github.com/getgrav/grav). It sends your site's mail through Amazon SES, and it reads Amazon's delivery reports back so the site knows what happened to each message.

## Installation

Installing the Email Amazon plugin can be done in one of three ways: The GPM (Grav Package Manager) installation method lets you quickly install the plugin with a simple terminal command, the manual method lets you do so via a zip file, and the admin method lets you do so via the Admin Plugin.

### GPM Installation (Preferred)

To install the plugin via the [GPM](https://learn.getgrav.org/cli-console/grav-cli-gpm), through your system's terminal (also called the command line), navigate to the root of your Grav-installation, and enter:

    bin/gpm install email-amazon

This will install the Email Amazon plugin into your `/user/plugins`-directory within Grav. Its files can be found under `/your/site/grav/user/plugins/email-amazon`.

### Admin Plugin

If you use the Admin Plugin, you can install the plugin directly by browsing the `Plugins`-menu and clicking on the `Add` button.

## Configuration

Before configuring this plugin, you should copy the `user/plugins/email-amazon/email-amazon.yaml` to `user/config/plugins/email-amazon.yaml` and only edit that copy.

Here is the default configuration and an explanation of available options:

```yaml
enabled: true
transport: https
username:
password:
access_key:
secret_key:
region:
configuration_set:
sns_topic:
identity:
```

Note that if you use the Admin Plugin, a file with your configuration named email-amazon.yaml will be saved in the `user/config/plugins/`-folder once the configuration is saved in the Admin.

The last three are only used by delivery reports, and there is a section about them further down.

## Usage

The **transport** can either be `https` (the default), `api` or `smtp`.  `username` and `password` is used for the `SMTP` option, and `access_key` and `secret_key` is used by `api` and `https`.

One thing worth knowing before you pick: the `api` transport builds Amazon's request out of the parts of a message — sender, recipients, subject, text body, HTML body — and sends that. Everything else is left behind, including every custom header and including `List-Unsubscribe`. That is fine for a contact form and it is not fine for a newsletter, because a bulk sender with no unsubscribe button is what a spam filter thinks a spammer looks like. The `https` transport sends exactly the same message through exactly the same API as raw MIME, so the headers arrive; `smtp` is SMTP, where the headers are the first half of the message. If you send anything in bulk, stay on `https` or pick `smtp`. Before 1.1.0 the default was `api`; a site that saved its settings under that default keeps it until the setting is changed.

When **configuration_set** is filled in, every message goes out with an `X-SES-CONFIGURATION-SET` header naming it, on all three transports, so SES publishes that message's events without the set having to be the sending identity's default.

Once the options are set, all other configuration regarding email should be done in the main `email` plugin.  You just need to set the engine in the `email.yaml` configuration:

```yaml
mailer:
  engine: amazon
```

A default `from:` and `to:` address is also required.

To set a specific region, add to the `email-amazon.yaml` configuration with your desired region:

```yaml
region: us-east-1
```

## Delivery reports

Amazon can tell your site what happened to each message it sent — delivered, bounced, marked as spam, opened, clicked, and rejected before it ever left — and this plugin knows how to read those reports and how to set them up. What you get once it is working is a site that can suppress an address the moment it hard-bounces, show which campaign a complaint came from, and stop sending to a mailbox that no longer exists.

You need an Email plugin new enough to have the provider contract (5.0.9 or later) and something on the site that asks for it — the KahunaCart newsletter add-on is the one that does today. Without either, this plugin sends mail exactly as it always has and none of the rest of this section applies.

### The one button

Whatever asks for it hands this plugin a webhook address and presses a button. Six things then happen with the same access key that sends your mail:

1. An SNS topic is created, or the one already called `grav-email-events` is reused.
2. That topic's policy is changed so SES is allowed to publish to it. This is the step everybody forgets by hand, and when it is missing SES accepts everything else, reports success, and publishes nothing at all.
3. Your webhook address is subscribed to the topic over HTTPS. Amazon posts a confirmation to it within a minute or two and your site answers it; nothing else arrives until it has.
4. An SES configuration set is created, or the one already named is reused.
5. An event destination on that configuration set is pointed at the topic, for the event types that were asked for.
6. If you filled in **Sending identity**, that configuration set becomes the identity's default, so every message from that domain publishes its events with no header to set.

Step six is the only one with an effect outside Grav, and it is worth reading twice: making a configuration set the default for an identity affects **every** message sent from that identity, including mail sent by something other than Grav. Leave the box empty and the button will tell you what is left instead.

### What the access key has to be allowed to do

Set on the key's own user or role under IAM, not in SES:

`sns:CreateTopic`, `sns:GetTopicAttributes`, `sns:SetTopicAttributes`, `sns:Subscribe`, `sns:ListSubscriptionsByTopic`, `ses:GetConfigurationSet`, `ses:CreateConfigurationSet`, `ses:GetConfigurationSetEventDestinations`, `ses:CreateConfigurationSetEventDestination`, `ses:UpdateConfigurationSetEventDestination`.

Naming a sending identity also needs `ses:PutEmailIdentityConfigurationSetAttributes`, and letting a deliverability check read your domain's DKIM records needs `ses:GetEmailIdentity`.

If the key is refused you get Amazon's own sentence back along with which of the six steps it was refused on, because "not authorized" on step two and "not authorized" on step five need two different actions added.

### Doing it by hand

In the SES console, open **Configuration sets** and either create one or open the one you send with. On its **Event destinations** tab, add a destination of type **Amazon SNS**, pick or create a topic, and tick Delivery, Bounce, Complaint and Reject. Then open that topic in the SNS console, create a subscription with protocol **HTTPS** and paste your site's webhook address as the endpoint — Amazon posts a confirmation straight away and your site answers it. Last, either set the configuration set as the default on your verified domain under **Identities**, or send with an `X-SES-CONFIGURATION-SET` header naming it. Without that last step the destination is set up and no event is ever published.

### How the reports are checked

SNS signs every message with an Amazon certificate, so there is no signing key to paste anywhere. The certificate's URL travels inside the message, and this plugin will only ever fetch one from `sns.<region>.amazonaws.com` — anything else is refused before a request is made, because a receiver that fetched whatever URL it was handed would be checking signatures against a certificate the forger supplied. Fetched certificates are cached under `user/data/email-amazon/` for thirty days so that a campaign of forty thousand messages does not fetch forty thousand certificates.

### Tying a bounce back to the message it came from

SES hands your own headers back in `mail.headers` on every event-publishing notification, so a site that stamps a header on the way out gets it back on the way in. Two things to know:

- A **feedback notification** sent straight from a verified identity, rather than through a configuration set, carries headers only once "Include original email headers" is ticked on that identity — once per feedback type, on the identity's page in the SES console.
- Amazon cuts the header list short when the original headers went over 10 KB. Message tags survive that, and this plugin reads a send id out of `mail.tags` as well as out of `mail.headers`.

And the obvious one: on the `api` transport there are no custom headers to hand back at all, because they never left.

## Credits

Thanks to the [Symfony team](https://symfony.com) for making this plugin possible.


