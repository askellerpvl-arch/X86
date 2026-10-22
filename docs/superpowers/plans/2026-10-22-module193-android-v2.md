# Android v2 development against the supplied stock module 1.9.3

User authorized continued autonomous implementation using the supplied PHP module.
The archive is a source reference; do not deploy it or change production/main.
All optional UI changes default OFF until actual device/staging acceptance.

1. Freeze the supplied source hashes. Run unchanged PHP sale/payment/tax/return/
   purchase/role/search functions against disposable internal MariaDB with synthetic
   OpenCart tables. Preserve cashier custom-price support introduced in 1.9.3.
2. Run original frontend code/DOM offline with deterministic responses using the
   existing api.php contracts. Assert exact sale payloads, payment choices, failed
   operation/cart behavior and cashier role filters before enhancements.
3. Add a capability-checked Android-injected adapter: existing server search with
   latest-response protection, current-page/list scroll memory, safe Home refresh
   using actual cart/modal/request state, explicit product actions and horizontal
   gestures that only open menus, and a cashier step toolbar forwarding existing
   scanner/cart/payment controls without submitting transactions.
4. Native operation notices only after original API returns ok/result.sale_id or
   purchase_id. Restricted main-frame origin listener; Android permission is optional.
   No notification from a click/HTTP200 alone and no automatic write retry.
5. Execute native/WebView/offline frontend/PHP regression, lint, debug and signed
   builds. Keep baseline version until all mandatory manual release gates pass.
   Produce a test candidate and update the existing draft PR, never auto-merge.

Real site/device/50×30 printer acceptance and an APK-update publishing policy remain
separate unresolved gates. A supplied module archive is not a staging server or a
confirmation that the running database has the exact same version/schema.
