# 1.0.4

## Fixed
- Fixed "Change card" payment failures ("card type does not match initial request") when switching from a saved card to a new one during checkout.
- Fixed a saved card being stored even when the "Save card" checkbox was left unchecked.
- Fixed the surcharge amount compounding on repeated calculations (e.g. increasing slightly on every recalculation instead of staying stable).
- Fixed a saved/vaulted card silently overriding a card the customer had explicitly switched to during checkout.
- Fixed the automatic page refresh not triggering after calculating a surcharge in some cases, requiring a manual refresh to proceed.
- Fixed Google Pay and Apple Pay checkout failing when surcharge was disabled in the plugin configuration.
- Fixed an error when loading the checkout confirmation page for a customer whose saved card no longer exists on the connected account.
