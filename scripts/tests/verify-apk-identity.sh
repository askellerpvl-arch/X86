#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
FIXTURE=$(mktemp -d)
trap 'rm -rf "$FIXTURE"' EXIT
mkdir -p "$FIXTURE/bin"
touch "$FIXTURE/original.apk" "$FIXTURE/candidate.apk"
cat > "$FIXTURE/bin/apksigner" <<'MOCK'
#!/usr/bin/env bash
[[ "${SIGNATURE_VALID:-yes}" == yes ]] || exit 1
fingerprint=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
if [[ "${*: -1}" == */candidate.apk ]]; then fingerprint=${CANDIDATE_CERT:-$fingerprint}; fi
printf 'Signer #1 certificate SHA-256 digest: %s\n' "$fingerprint"
MOCK
cat > "$FIXTURE/bin/aapt" <<'MOCK'
#!/usr/bin/env bash
package=kz.x86.stock
version=5
if [[ "${*: -1}" == */candidate.apk ]]; then
  package=${CANDIDATE_PACKAGE:-kz.x86.stock}
  version=${CANDIDATE_VERSION:-6}
fi
printf "package: name='%s' versionCode='%s' versionName='test'\n" "$package" "$version"
MOCK
chmod +x "$FIXTURE/bin/"*
export PATH="$FIXTURE/bin:$PATH"
run() { bash "$ROOT/scripts/verify-apk-identity.sh" "$FIXTURE/original.apk" "$FIXTURE/candidate.apk" > "$FIXTURE/result" 2>&1; }
expect_failure() { if run; then echo "FAIL: accepted $1"; exit 1; fi; }
run
grep -q 'PASS' "$FIXTURE/result"
CANDIDATE_PACKAGE=other.package expect_failure 'wrong package'
CANDIDATE_CERT=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb expect_failure 'wrong certificate'
CANDIDATE_VERSION=5 expect_failure 'equal version'
CANDIDATE_VERSION=4 expect_failure 'older version'
SIGNATURE_VALID=no expect_failure 'invalid signature'
rm "$FIXTURE/candidate.apk"
expect_failure 'missing APK'
echo 'PASS: 7 APK identity scenarios'
