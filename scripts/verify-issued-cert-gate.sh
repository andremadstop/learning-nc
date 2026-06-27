#!/usr/bin/env bash
#
# verify-issued-cert-gate.sh — Phase 155 phase-close INDEPENDENT-verify gate (ADR-001 follow-up #2).
#
# 155-03 proved the independent Python Ed25519 verifier (scripts/verify-credential.py) against a
# THROWAWAY key. THIS gate proves the promise on a REAL DB/API-issued credential: a credential minted
# by the live issuer must verify under a third-party verifier that shares NONE of the app's PHP/sodium
# code — using only the public key `x` published in did.json.
#
# FAIL-NOT-SKIP: if the independent verifier is unavailable (no python3 / no `cryptography`), this gate
# HARD-FAILS. A skip would falsely read as a pass for the headline "anyone can verify" claim.
#
# Steps:
#   (a) obtain a REAL issued credential JWT from the live devcloud (GET /api/certificates, authenticated)
#   (b) resolve the signer's public key: read the JWT header `kid`, fetch did.json, pull the matching
#       verificationMethod.publicKeyJwk.x
#   (c) run python3 scripts/verify-credential.py <jwt> <x>
#   (d) HARD-FAIL if python3 OR cryptography is absent (NO skip)
#   (e) assert exit 0 on the valid JWT AND non-zero on a tampered copy (one payload byte flipped)
#
# Credential acquisition (pick one):
#   CERT_JWT=<compact-jwt>                         # explicit — simplest for the post-review run
#   ADMIN_COOKIE=<cookie-header> ADMIN_TOKEN=<requesttoken>   # authenticated GET /api/certificates
#
# ⚠ Requires a LIVE issuer (155-01 migration applied + `occ learning:cert:init-issuer` + a real pass).
#   Until that provisioning happens (gated behind a multi-AI review — HARD PROD BOUNDARY), this gate has
#   no real credential to verify and is DEFERRED. It is intentionally strict, not skip-on-missing.
#
# Exit: 0 = PASS (real credential independently verified, tampered copy rejected); non-zero = FAIL.
#
set -uo pipefail

BASE_URL="${BASE_URL:-https://devcloud.andrestiebitz.de}"
REMOTE_HOST="${REMOTE_HOST:-relais}"
CONTAINER_NAME="${CONTAINER_NAME:-devcloud-app}"
CONTAINER_USER="${CONTAINER_USER:-www-data}"
BRUTEFORCE_RESET_IP="${BRUTEFORCE_RESET_IP:-172.21.0.1}"
VERIFY_PY="${VERIFY_PY:-$(cd "$(dirname "$0")" && pwd)/verify-credential.py}"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

die()  { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
b64u_pad() { local s="$1" m=$(( ${#1} % 4 )); [[ $m -eq 2 ]] && s="${s}=="; [[ $m -eq 3 ]] && s="${s}="; printf '%s' "$s"; }

# ── (d) HARD guard: the independent verifier MUST be available — fail, never skip ──────────────
command -v python3 >/dev/null 2>&1 || die "no python3 — independent verifier unavailable (install python3 + cryptography)"
python3 -c "import cryptography" 2>/dev/null || die "python3 'cryptography' module missing — independent verifier unavailable (apt-get install python3-cryptography)"
[[ -f "$VERIFY_PY" ]] || die "verify-credential.py not found at $VERIFY_PY"

# ── bruteforce reset before any authenticated API call (courtesy; needs ssh to the host) ───────
if command -v ssh >/dev/null 2>&1; then
    ssh "$REMOTE_HOST" "docker exec -u $CONTAINER_USER $CONTAINER_NAME php occ security:bruteforce:reset $BRUTEFORCE_RESET_IP" >/dev/null 2>&1 || true
fi

# ── (a) obtain a REAL issued credential JWT ────────────────────────────────────────────────────
cert_jwt="${CERT_JWT:-}"
if [[ -z "$cert_jwt" ]]; then
    [[ -n "${ADMIN_COOKIE:-}" && -n "${ADMIN_TOKEN:-}" ]] || \
        die "no credential source — set CERT_JWT=<jwt> OR ADMIN_COOKIE + ADMIN_TOKEN for an authenticated GET /api/certificates"
    body="$TMP_DIR/certs.json"
    curl -sS -H "Accept: application/json" -H "OCS-APIREQUEST: true" \
        --cookie "$ADMIN_COOKIE" -H "requesttoken: $ADMIN_TOKEN" \
        "${BASE_URL}/apps/learning/api/certificates" -o "$body" || die "GET /api/certificates failed"
    cert_jwt="$(jq -r 'if type=="array" then .[0] else ((.certificates // .data // [])[0]) end | (.credential_json // .credentialJson // empty)' "$body" 2>/dev/null)"
fi
[[ -n "$cert_jwt" && "$cert_jwt" != "null" ]] || \
    die "no issued credential available — provision the issuer + trigger a qualifying pass first (post-review live gate)"

# split the compact JWT
IFS='.' read -r seg_h seg_p seg_s <<<"$cert_jwt"
[[ -n "$seg_h" && -n "$seg_p" && -n "$seg_s" ]] || die "credential is not a 3-segment compact JWT"

# ── (b) resolve the signer's public key `x` from did.json via the header kid ───────────────────
kid="$(b64u_pad "$seg_h" | tr '_-' '/+' | base64 -d 2>/dev/null | jq -r '.kid // empty')"
[[ -n "$kid" ]] || die "JWT header has no kid"

did="$TMP_DIR/did.json"
curl -sS "${BASE_URL}/apps/learning/did.json" -o "$did" || die "could not fetch did.json"
jq -e '.verificationMethod | type=="array" and length>0' "$did" >/dev/null 2>&1 || \
    die "did.json has no verificationMethod (issuer unprovisioned) — deferred to post-review live gate"

pub_x="$(jq -r --arg k "$kid" '.verificationMethod[] | select(.id==$k) | .publicKeyJwk.x // empty' "$did")"
[[ -n "$pub_x" ]] || die "kid '$kid' has no matching verificationMethod.publicKeyJwk.x in did.json"

# ── (c) independent verification of the VALID credential ───────────────────────────────────────
if python3 "$VERIFY_PY" "$cert_jwt" "$pub_x"; then
    printf '  valid credential: independently verified (kid=%s)\n' "$kid"
else
    die "independent verifier REJECTED a valid issued credential (kid=$kid)"
fi

# ── (e) tamper detection: flip one payload byte → must be rejected (non-zero) ───────────────────
# Flip the first payload char to a different base64url char so the signature no longer matches.
first="${seg_p:0:1}"; rest="${seg_p:1}"
if [[ "$first" == "A" ]]; then flip="B"; else flip="A"; fi
tampered_jwt="${seg_h}.${flip}${rest}.${seg_s}"
if python3 "$VERIFY_PY" "$tampered_jwt" "$pub_x" >/dev/null 2>&1; then
    die "independent verifier ACCEPTED a tampered credential — tamper detection broken"
else
    printf '  tampered credential: correctly rejected\n'
fi

printf 'PASS: real issued credential independently verified (Python Ed25519); tampered copy rejected\n'
exit 0
