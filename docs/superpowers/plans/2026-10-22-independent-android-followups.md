# Independent Android follow-ups after v1.6 protections

Authorization: user requested autonomous execution of the existing roadmap.
Keep package/version at v1.5 baseline until physical-device release gates pass.
These are optional development capabilities, not completed v1.7–v2.0 releases.

1. v1.7 subset: optional torch control for an already running, visible HTML video
   camera track. Detect capability; never create another camera stream, recognize
   a barcode, click a sale button, or alter server scanner callbacks. Show success
   only after the applyConstraints promise resolves. Test unsupported/denied/success
   with offline WebView tracks; physical torch/scanner QA remains mandatory.
2. v1.8 subset: optional Android find-on-page with next/previous/close, distinct from
   server product search. No fake product API, no monetary actions, no gesture that
   changes item quantities. Search stays in memory. Test UI policies and DOM behavior.
3. v1.9 subset: track multiple DownloadManager PDF IDs and queue bounded legacy
   pending storage-permission downloads rather than overwrite earlier requests.
   Test completion order, duplicate/unknown IDs, queue bounds and cleanup. Preserve
   cookie-bearing DownloadManager and existing PDF viewer routes.
4. Record dependency blocks for server-confirmed scan feedback/actions, search,
   role-specific gestures, transaction notifications, cashier v2.0, and update feed.
   No guessed endpoint or production staging substitute.

Verification after each step: JVM tests + lint + debug + instrumentation compile;
API 26/35 WebView fixtures in CI; final signed test candidate via explicit workflow.
Physical transactions, 50×30 printer behavior and original APK upgrade remain open.
