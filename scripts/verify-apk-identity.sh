#!/usr/bin/env bash
set -euo pipefail
fail() { echo "FAIL: $*" >&2; exit 1; }
[[ $# == 2 ]] || fail 'usage: verify-apk-identity.sh ORIGINAL.apk CANDIDATE.apk'
for apk in "$@"; do [[ -f "$apk" ]] || fail "APK not found: $apk"; done
command -v apksigner >/dev/null || fail 'apksigner is required on PATH'
command -v aapt >/dev/null || fail 'aapt is required on PATH'
identity() {
  local signature badging fingerprint package version
  signature=$(apksigner verify --print-certs "$1") || fail 'APK signature verification failed'
  fingerprint=$(printf '%s\n' "$signature" | sed -n 's/^Signer #[0-9][0-9]* certificate SHA-256 digest: //p' | tr 'A-F' 'a-f' | sort)
  [[ "$fingerprint" =~ ^[0-9a-f]{64}$ ]] || fail 'expected exactly one SHA-256 signer'
  badging=$(aapt dump badging "$1") || fail 'cannot inspect APK'
  package=$(printf '%s\n' "$badging" | sed -n "s/^package: name='\([^']*\)'.*/\1/p")
  version=$(printf '%s\n' "$badging" | sed -n "s/^package: .*versionCode='\([0-9]*\)'.*/\1/p")
  [[ "$package" == kz.x86.stock ]] || fail 'package must be kz.x86.stock'
  [[ "$version" =~ ^[1-9][0-9]*$ ]] || fail 'invalid versionCode'
  printf '%s %s\n' "$fingerprint" "$version"
}
original=$(identity "$1")
candidate=$(identity "$2")
read -r original_cert original_version <<< "$original"
read -r candidate_cert candidate_version <<< "$candidate"
[[ "$original_cert" == "$candidate_cert" ]] || fail 'signing certificate changed'
(( candidate_version > original_version )) || fail 'candidate versionCode must increase'
echo "PASS: kz.x86.stock, same signer, versionCode $original_version -> $candidate_version"
