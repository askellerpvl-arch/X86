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
- The legacy `X86Android.saveBase64` bridge is retained for PDF compatibility. Its
  top-level URL check does **not** authenticate callers in iframes. Origin-checked
  WebMessage migration is unfinished; the new bridge flag is reserved and not exposed.
- Exact `/stock` URL restrictions need real login/download redirect smoke tests.
  If a necessary server route lies outside `/stock`, document it before any policy change.
- Connection status does not assert a sale succeeded. No XHR body interception,
  financial arithmetic, automatic POST retry or invented transaction status exists.
- Pending downloads are activity-scoped; process-death restoration of downloads,
  an authenticated recent-files list and printer-specific behavior remain pending.
- The original supplied APK hash is recorded but that APK/device certificate is
  unavailable here. CI artifact equivalence is not a replacement for that check.

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
