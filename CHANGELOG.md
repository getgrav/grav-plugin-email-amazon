# v1.1.2
## 09/08/2026

1. [](#bugfix)
    * **The secret key and the SMTP password are no longer shown in the clear.** Every credential field in this plugin was typed `text`, so an account's sending credentials were rendered as readable text on the settings page and handed to the browser unmasked by the API. They are `password` fields now. The access key id stays readable: it identifies a key rather than being one, and hiding it takes away the one value that tells two keys apart.

# v1.1.1
## 09/05/2026

1. [](#bugfix)
    * **Set up now clears away a subscription whose address has changed.** A store that generated a new secret, or lost its settings, was subscribed to the SNS topic twice: once at the old address, which answers 404, and once at the new one. An SNS subscription's endpoint cannot be edited, so Set up now unsubscribes the old one before subscribing the new. A subscription still waiting to be confirmed is left alone and said plainly, since Amazon will not remove one of those and drops it itself after three days. The access key needs `sns:Unsubscribe` for this; without it the store is still subscribed and the message names the action to add.

# v1.1.0
## 09/05/2026

1. [](#new)
    * Added a provider for the Email plugin's provider contract, so everything this plugin knows about SES now lives here: how Amazon's delivery notifications are verified and read, what a sending domain's DNS has to say, and what each of the three transports does to a custom header on the way out
    * Added one-button setup for delivery reports - the SNS topic, its policy, the subscription, the configuration set and the event destination, all created with the access key that already sends the mail
    * Added `configuration_set`, `sns_topic` and `identity` settings, used only by delivery reports
    * SES's `Reject` is now reported, as the contract's `dropped`. It is Amazon taking the message, deciding it will not put it on the wire — a virus, usually, and its own words for that are "Bad content" — and never handing it to a receiving server. It was previously read and skipped. It is not a bounce, and reporting it as one would have said a receiving server refused an address when nothing of the sort happened, so it gets the word the contract has for exactly this.
    * A `Reject` now says that Amazon refused the message rather than the address, so nothing downstream can read one as a reason to stop mailing somebody. It is always the message: SES has no way of refusing a send because of the recipient, an address it will not deliver to bounces instead, and the account suppression list produces a permanent bounce with the subtype `Suppressed` rather than a `Reject`. A store that suppressed on one would take a subscriber off its list because a virus scanner read an attachment
    * The header a send id travels in is now named by the Email plugin rather than by this one, so the end that writes the header and the end that reads it cannot disagree about it. It is `X-Grav-Send-Id`, or whatever `providers.send_header` in the Email plugin's configuration says. The undocumented `send_header` setting this plugin read is gone, and so is its own copy of the header-reading helpers.
    * Added a test suite under `tests/`, run with `composer install -d tests` and `tests/vendor/bin/phpunit`
1. [](#improved)
    * The default transport is HTTPS rather than API. Both go through the same Amazon API with the same keys; HTTPS sends the whole message so every custom header arrives, `List-Unsubscribe` included, and API sends the parts of one and leaves the headers behind. A site that saved its settings under the old default keeps `api` until it changes the setting
    * When `configuration_set` is filled in, every message is sent with an `X-SES-CONFIGURATION-SET` header naming it, on all three transports, so SES publishes its events without the set having to be the identity's default and without every sender remembering the header. A message that already carries the header keeps its own
    * The `ses` engine name is now accepted alongside `amazon`
    * The Transport setting says which of the three drops custom headers, and which do not

# v1.0.3
## 05/01/2026

1. [](#improved)
    * Added 1.7|2.0 compatibility flags

# v1.0.2
## 03/06/2026

1. [](#bugfix)
   * Fixed Symfony contracts and PSR interface conflicts with Grav 1.8
1. [](#improved)
   * Updated vendor libs

# v1.0.1
## 03/06/2026

1. [](#new)
   * Added support for configurable AWS region for SES

# v1.0.0
## 05/09/2023

1. [](#new)
   * Initial public release

# v1.0.0-rc.3
##  10/12/2022

1. [](#bugfix)
   * default to empty string in config values are null

# v1.0.0-rc.2
##  10/05/2022

1. [](#bugfix)
   * Set `email` plugin dependency to `4.0.0-rc.1`

# v1.0.0-rc.1
##  10/05/2022

1. [](#new)
    * ChangeLog started...
