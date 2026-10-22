# Android modernization: development status

This branch is a test candidate, **not a released v1.6 or v2.0**. Package remains
`kz.x86.stock`, versionCode 5 / versionName 1.5.0 until the release gate passes.
No production server/database or main branch is modified. Draft PR #2 is not merged.

## Implemented

- Phase 0: baseline hashes, original-vs-CI distinction, 7 APK identity acceptance
  scenarios; explicit device/staging matrix and release checklist.
- v1.6: URL/camera policies, video-only grants and pending permission cancellation;
  constrained PDF/binary payloads and safe filenames; safe refresh decision,
  optional connectivity badge, conservative dialog close, persistent classic mode.
- v1.7 subset: optional torch for an existing visible camera video track; capability
  detection and promise-confirmed status. It never starts a second camera or submits
  a code/sale. The original server scanner remains the recognition flow.
- v1.8 subset: optional find-on-current-page, with next/previous and close.
  This is explicitly not product search across the server's stock database.
- v1.9 subset: multiple tracked DownloadManager PDF IDs, bounded storage-permission
  queue and collision-safe legacy filenames. Existing viewer/PDF/label generation
  routes remain; all calculations and business actions stay on the server.
- CI: unsigned PR verification, lint/debug, offline WebView fixtures on API 26/35;
  signing secrets only in trusted main/manual signing job after all automated jobs.

## Verification evidence

Local JDK 17 / Gradle 8.9 / Android SDK 35:

- 17 JUnit tests passed (origin/camera, payloads, refresh, connection, flags,
  package/version, multiple downloads and duplicate PDF preservation).
- 7 shell identity-checker scenarios passed.
- 3 Node scenarios execute the actual production Home/Back JavaScript and passed.
- `:app:lintDebug :app:assembleDebug :app:assembleDebugAndroidTest` passed.
- Android WebView fixture suite is configured in CI. Running it is mandatory;
  compiling it is not treated as execution. Local environment has no `/dev/kvm`.
- Exact CI runs, signed candidate availability, signer and APK hashes are recorded
  in the PR validation summary; do not infer success from this document alone.

Review caught and fixed a Java locale expression accidentally introduced in
embedded JS, and duplicate-name truncation in legacy downloads. Both now have
regression tests. Existing lint warnings are retained; lint errors are not suppressed.

## Deliberate safety limits

- UI experiment flags default OFF and classic mode overrides them. Camera/source
  checks and payload limits always apply. Settings do not reload the page.
- Refresh cannot currently establish a safe server-owned home/cart/dirty state.
  It therefore refuses unknown state, including in classic mode. Full pull-to-refresh
  needs the actual page contract and device tests; do not re-enable unconditional reload.
- An optional origin-checked WebMessage download channel is now implemented.
  Its native object is restricted to https://x86.kz, rejects non-main-frame calls,
  validates versioned envelopes and acknowledges receipt only. An adapter preserves
  the existing saveBase64/blob PDF flow. It is OFF by default and selected changes
  apply after app restart. Unsupported WebView engines visibly use compatibility mode.
  The legacy compatibility bridge still cannot authenticate iframe callers; actual
  site/PDF smoke tests remain a release gate before making the new channel default.
- Exact `/stock` URL restrictions need real login/download redirect smoke tests.
  If a necessary server route lies outside `/stock`, document it before any policy change.
- Connection status does not assert a sale succeeded. No XHR body interception,
  financial arithmetic, automatic POST retry or invented transaction status exists.
- Pending downloads are activity-scoped; process-death restoration of downloads,
  an authenticated recent-files list and printer-specific behavior remain pending.
- The original APK is now supplied: SHA-256 matches the plan and its verified
  certificate matches the signed development candidate. The installed device
  certificate and higher-version upgrade still require physical-device verification.

## Remaining dependencies through v2.0

| Version | Remaining work / dependency |
| --- | --- |
| v1.6 | Real Android/API26 + current device regression; original APK upgrade; complete origin-safe bridge; verified refresh state; review and manual release gate |
| v1.7 | Confirmed scan result contract, bulk scans, sound/vibration, role-specific actions and fallback; camera/torch QA on actual hardware |
| v1.8 | PHP source and staging for indexed search, result models, role-aware long-press/swipes, menu reordering and stable filter/scroll state |
| v1.9 | Real printer 50×30 tests, native print pipeline/recent-file privacy, server-confirmed notifications, trusted update manifest/feed and install UX |
| v2.0 | Actual PHP routes/contracts, sandbox reconciliation for every payment/refund/purchase/commission, cashier route and roles; no Android reimplementation of accounting |

No server API is fabricated. Server-dependent tasks are blocked, while the independent
Android work above is available for review. All physical-device/staging checklist
items remain pending. No production test transactions were performed.

## Supplied module 1.9.3 integration (2026-10-22)

The user supplied `x86stock-update-1.9.3.ocmod.zip`, SHA-256
`40a66a91e2f7d9c18c8312dd56e5131f16444392ceda2af63ccfc8fa00d4fe2e`.
Reference PHP and frontend files are frozen by hashes under `tests/server/reference/`.
No module deployment, production configuration or database mutation was performed.

Implemented as default-OFF Android experiments: latest-response server search,
list scroll restoration, real module/cart/request-aware refresh and Back protection,
product action menus and horizontal gestures, cashier step controls, scan confirmation
and optional haptic feedback, and native notices after confirmed sale/purchase IDs.
Original financial handlers, custom cashier prices and request contracts are preserved.
No automatic sale, write retry or printing is introduced by gestures/scanning.

Offline regression executes unchanged PHP with synthetic OpenCart tables in an
internal Docker network (20 scenarios), and original module JavaScript/DOM with
fake API responses (22 scenarios). These cover logic, not live HTTP authentication,
production schema, payment terminal behaviour, camera recognition or printer output.
The Android version remains 1.5.0/code 5 pending mandatory manual release gates.
Batch scanning, reliable update distribution and full v2 acceptance remain open.
A staging URL, physical Android upgrade/auth/scan/POS/PDF/50x30 label acceptance and
all-role business regression are still required. Do not describe this as ready v2.0.
