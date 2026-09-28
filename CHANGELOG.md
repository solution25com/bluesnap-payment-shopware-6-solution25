# Changelog

# 1.3.0 - 28/09/2026

## Changed
- Supported Shopware version narrowed to 6.6 (`~6.6.0`). Shopware 6.7 is supported on the `main-6.7` branch in the 2.x release line.
- Removed the Vite administration build output (`Resources/public/administration/.vite/` and `assets/`), which only applies to Shopware 6.7. This branch ships the Webpack bundle alone.

## Fixed
- Restored the Webpack administration bundle (`Resources/public/administration/js/blue-snap.js`), which was removed in error. Without it the BlueSnap administration UI did not load on Shopware 6.6, leaving the order-detail panel and API-test component unavailable.

# 1.2.0 - 28/09/2026

## Added
- Customers can keep several saved cards instead of one. The full card list is read from the BlueSnap Vaulted Shopper API.
- Saved-card selection at checkout, with the preferred card preselected and the option to pay with a new card.
- Saved-cards screen in the customer account (`/account/bluesnap/saved-cards`) to review, remove and set a preferred card.
- Adding a card from the account area through BlueSnap's hosted payment fields, without placing an order.
- Store API and storefront routes for listing, adding, removing, selecting and preferring a saved card.
- English and German translations for the saved-card screens.
- BlueSnap credit card payment on the order edit screen (`/account/order/edit`), which is where Shopware sends a customer when a payment failed. Stored cards, a new card, 3-D Secure and surcharging all work there; previously the payment method rendered no card form at all and the order could not be paid.

## Changed
- Redesigned the credit card step at checkout. The card choice - a stored card or a new one - is the only state, and Shopware's own order button is the only submit action. The separate "Calculate surcharge and proceed to payment" button, the "Change card" checkbox and the duplicated "Save my card" checkbox are gone.
- Switching cards no longer reloads the page. The cart totals and line items are refreshed in place, which keeps the hosted payment field iframes alive.
- When a surcharge applies to a new card it is now disclosed before the card is charged: the totals update and a confirmation step states the surcharge and the new total.
- The card is handed to BlueSnap once; the same token is reused for the surcharge calculation and the capture, so a card never has to be entered twice.
- `store-api.bluesnap.calculateSurcharge` now prices a stored card as well, given its card key, instead of requiring a hosted-fields token.
- The payment handler settles the payment itself whenever the storefront posts a payload, rather than deciding from configuration alone. Paying an existing order has no cart to capture against, so that is the only correct route there.
- Vaulted transactions now name the selected card (`creditCard.cardType` / `creditCard.cardLastFourDigits`); previously BlueSnap chose a card itself.
- The surcharge is recalculated when the customer switches cards, so it always describes the card being charged.
- 3-D Secure authentication is submitted for the selected card rather than for the first stored card.
- Saving a card at checkout now adds it to the customer's existing BlueSnap vaulted shopper instead of replacing the previously saved card. The card is attached to the shopper before the transaction and then charged as a stored card, because a payment field token can only be spent once.
- Hosted payment field tokens are no longer created with a `shopperId`. A shopper-bound token carries the card already on file, so BlueSnap quoted the surcharge for - and expected - that card instead of the one being typed, and rejected the transaction with "The selected card type does not match initial request".
- The surcharge quote for a stored card now always names that card - the preferred one until the customer picks another. A vaulted shopper holding more than one card previously made BlueSnap answer `16003 Multiple payment methods on shopper, but none selected`, so no surcharge was priced at all and the order was placed without one.
- A quote that priced no surcharge no longer puts a zero line item on the cart - which made the base amount look like it already carried a surcharge, so the next real surcharge was counted twice - but its token is still kept and sent. A BlueSnap account with surcharging enabled rejects a transaction that carries no surcharge token with `24002 Surcharge Token is required`, even for a card it priced no surcharge for, which is what happened on every card BlueSnap does not surcharge.
- The surcharge is no longer disclosed for confirmation when it is zero; the customer was asked to confirm a surcharge of 0.00 before paying.
- Removing a saved card is confirmed on the card itself - the first click arms the button, the second removes - instead of a browser dialog.
- `routes.xml` and `services.xml` are now `routes.yaml` and `services.yaml`; Symfony deprecates the XML format.
- The cart is now the single record of what a card payment charges. The surcharge stays on the cart on the BlueSnap payment routes, so the transaction sends the cart total and the surcharge token the cart carries for the selected card. Previously the amount was assembled from the cart total plus a surcharge the storefront sent - which counted it twice once the cart already carried it - and the token came from the storefront, which could still hold one quoted for a card the customer had switched away from. Paying with a stored card also dropped the surcharge from the amount entirely. Without surcharging the amount is unchanged.
- `bluesnap_vaulted_shopper` gained `preferred_card_type` and `preferred_card_last_four`, plus a unique key on `customer_id`. Pre-existing duplicate rows are removed by the migration, keeping the most recently updated row per customer.

## Fixed
- A surcharge quoted while paying an existing order was authorised but never captured: the order did not carry it, and the capture takes the order total. The order now records the surcharge - once - before anything is authorised, so the authorised, captured and displayed amounts agree.
- The capture never sent a surcharge token. `getSurchargeTokenFromOrder()` reads `customFields.bluesnap_surcharge`, which nothing ever wrote; it is now written when the payment is authorised.
- Choosing "Use a new card" at checkout kept the surcharge calculated for the previously selected saved card, which hid the card entry fields behind the "Change card" checkbox.
- The saved-card list disappeared once a new card was being entered, leaving no way back to a stored card.
- "Save my card" did not save the card when a surcharge applied: the flag was read from three different controls, one of which was reset by the page reload. There is now a single checkbox.
- The card chosen for a completed order stayed in effect for the customer's next checkout.
- With surcharging active, submitting the order before calculating the surcharge created an order that the gateway then rejected with "Surcharge Token is required". The submit is now blocked with a translated message.
- The checkout card form was a `<form>` nested inside Shopware's `changePaymentForm`; the browser dropped the element, which broke the field layout and the show/hide logic.
- The cardholder name fields were marked `required` while sitting inside Shopware's `changePaymentForm`. An empty or hidden required control blocked that form, so customers could not switch payment method at all. The plugin validates both fields itself.
- `BlueSnapRoute::capture()` stored the vaulted shopper twice per transaction, which could create duplicate rows.
- The stored card type was overwritten on every capture, so it described only the most recently used card.
- A saved-card preference pointing at a card removed directly in BlueSnap now heals itself instead of leaving the checkout without a selection.

## Security
- Hardened the payment, refund and webhook flows.
- Improved validation of saved-card payments and surcharge handling.

## Deprecated
- `store-api.bluesnap.updateVaultedShopper` / `frontend.bluesnap.updateVaultedShopper`. The route only ever deleted a card despite its name; use the saved-card remove routes instead. It will be removed in a future major version.


## 1.1.1 - 18/08/2026
### Fixed
- BlueSnap storefront icon override
- Extension Verifier errors

## 1.1.0

### Fixed
- Fixed "Change card" payment failures ("card type does not match initial request") when switching from a saved card to a new one during checkout.
- Fixed a saved card being stored even when the "Save card" checkbox was left unchecked.
- Fixed the surcharge amount compounding on repeated calculations (e.g. increasing slightly on every recalculation instead of staying stable).
- Fixed a saved/vaulted card silently overriding a card the customer had explicitly switched to during checkout.
- Fixed the automatic page refresh not triggering after calculating a surcharge in some cases, requiring a manual refresh to proceed.
- Fixed Google Pay and Apple Pay checkout failing when surcharge was disabled in the plugin configuration.
- Fixed an error when loading the checkout confirmation page for a customer whose saved card no longer exists on the connected account.
