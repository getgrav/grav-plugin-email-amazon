# v1.1.0
## 09/05/2026

1. [](#new)
    * Added a provider for the Email plugin's provider contract, so everything this plugin knows about SES now lives here: how Amazon's delivery notifications are verified and read, what a sending domain's DNS has to say, and what each of the three transports does to a custom header on the way out
    * Added one-button setup for delivery reports - the SNS topic, its policy, the subscription, the configuration set and the event destination, all created with the access key that already sends the mail
    * Added `configuration_set`, `sns_topic` and `identity` settings, used only by delivery reports
    * SES's `Reject` is now reported, as the contract's `dropped`. It is Amazon taking the message, deciding it will not put it on the wire — a virus, usually, and its own words for that are "Bad content" — and never handing it to a receiving server. It was previously read and skipped. It is not a bounce, and reporting it as one would have said a receiving server refused an address when nothing of the sort happened, so it gets the word the contract has for exactly this.
    * The header a send id travels in is now named by the Email plugin rather than by this one, so the end that writes the header and the end that reads it cannot disagree about it. It is `X-Grav-Send-Id`, or whatever `providers.send_header` in the Email plugin's configuration says. The undocumented `send_header` setting this plugin read is gone, and so is its own copy of the header-reading helpers.
    * Added a test suite under `tests/`, run with `composer install -d tests` and `tests/vendor/bin/phpunit`
1. [](#improved)
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
