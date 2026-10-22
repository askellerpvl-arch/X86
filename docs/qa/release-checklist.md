# Release gate

- [x] Original supplied v1.5 APK signature/package/hash verified independently of CI baseline.
- [ ] Installed-device certificate and higher-version upgrade verified.
- [ ] Unit tests, shell acceptance tests, lint, debug APK pass on exact candidate SHA.
- [ ] Signed release built only by trusted/manual workflow; same existing certificate.
- [ ] `scripts/verify-apk-identity.sh ORIGINAL.apk CANDIDATE.apk` passes.
- [ ] All real Android/staging scenarios in v1.5 regression matrix pass.
- [ ] No P0/P1 issues, including financial/stock/permission/input loss regressions.
- [ ] Existing camera, scanner, file picker, PDF and labels tested on API 26 and current Android.
- [ ] Review completed; feature flags remain OFF until individual device sign-off.
- [ ] Only then increment versionCode and versionName; record APK SHA-256.
- [ ] User reviews and merges PR manually; no auto-merge or production rollout.

Current state: development only. No v1.6–v2.0 release approved.
Rollback uses classic mode or a corrective APK with higher versionCode, not a
silent downgrade. No server/DB change is authorized.
