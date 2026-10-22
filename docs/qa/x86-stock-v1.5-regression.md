# v1.5 compatibility contract

Baseline: main `647e9e5f7ee1392224d1dd288ffbb99efd748515`, package
`kz.x86.stock`, versionCode 5, versionName 1.5.0, minSdk 26, targetSdk 35.
GitHub Actions baseline run: 39852407418. Never overwrite this reference.

Signer SHA-256 (verified against previous GitHub APK in this session):
`be82abd27b1e50b9537046d4993c3440cc14a41d971bf120c5e96d2c0c46956f`.

The plan reports user-supplied original APK SHA-256
`5b6b4bda4d9acba2e7320febaff8e16ccfc4ecc8877453ad87ac6994909fbe14`.
That original APK is NOT available here. Do not claim it equals the CI artifact.
See `baseline-identity.txt` for hashes of locally available GitHub artifacts.

## Manual gate — ALL pending (not run)

Run on API 26 and a current physical Android, with both administrator and cashier
accounts on staging/test data. Never create production transactions for testing.

| Flow | Required evidence |
| --- | --- |
| Install/update | Original and candidate package/signer; cookies survive upgrade |
| Login/logout | Session persistence, background, process recreation, redirects |
| Navigation | Every tab, product modal, Back, keyboard, list/filter position |
| Scanner | Permission allow/deny/cancel, barcode, camera after resume, existing fallback |
| Sales | Cash/Gold/Red/installment; compare all displayed totals/commissions/taxes to server |
| Returns/purchases | Quantities, stock, partner prices, role restrictions unchanged |
| Forms | Text/select/file picker, unfinished checkout, reload blocked without losing input |
| Documents | Authenticated URL/blob/data PDF, 50×30 label, multiple files, viewer/printing |
| Connection | Offline load, timeout during staging write, verify server state; never auto retry |
| Feature flags | Defaults OFF; classic mode never reloads/discards unfinished form |
| Security | External URLs, cross-origin camera/iframe denial; legacy bridge residual risk |

No APK build or unit test proves server-side business correctness. Any duplicate
operation, incorrect amount/role, lost cart/input blocks release.
