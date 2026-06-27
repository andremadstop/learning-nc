#!/usr/bin/env bash
#
# cross-db-migration-check.sh — Phase 155 cross-DB go/no-go gate (CERT-04 portability).
#
# Proves that migration Version009100 (learning_cert_keys + learning_certificates) applies cleanly
# on BOTH supported engines, so a credential issuer works on any Nextcloud instance regardless of DB:
#
#   * MariaDB 11.4 (utf8mb4)  — the risky engine (utf8mb4 = 4 bytes/char ⇒ index key-length blowups,
#                               64-char index-name limit). Exercised HERE against an EPHEMERAL,
#                               throwaway `mariadb:11.4` container (docker run / rm -f). Fully isolated,
#                               touches NO live data.
#   * PostgreSQL 16           — the live devcloud engine. The migration applies there via `occ upgrade`,
#                               which is GATED behind a multi-AI review (live schema change). This script
#                               therefore does NOT mutate live PG16; the PG assertion is intentionally a
#                               DEFERRED no-op (see POST-REVIEW block) until the review approves provisioning.
#
# The MariaDB DDL below mirrors Version009100Date20260627000000::changeSchema VERBATIM, with the oc_
# prefix that Nextcloud auto-prepends (we apply raw SQL, so we prepend it ourselves):
#   - VARCHAR lengths: key_id(64), public_key_b64u(128), status(16), verification_id(36), user_id(64), key_id(64)
#   - secret_key_enc + credential_json are TEXT and are NOT indexed (utf8mb4 key-length safety)
#   - index names verbatim, all <= 27 chars (well under MariaDB's 64-char limit)
#
# Exit codes: 0 = GO (MariaDB applied clean, PG deferred); non-zero = NO-GO (any DDL/index failure).
#
# Usage: ./scripts/cross-db-migration-check.sh
#
set -uo pipefail

MARIADB_IMAGE="mariadb:11.4"
CONTAINER="learn-crossdb-check-$$"
ROOT_PW="crossdbcheck"
DB_NAME="learn_crossdb"
FAIL=0

red()   { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
note()  { printf '  %s\n' "$*"; }

teardown() {
    docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
}
trap teardown EXIT

# ── DDL: Version009100 mirrored for MariaDB utf8mb4 (oc_ prefix applied manually) ──────────────
read -r -d '' DDL <<'SQL'
CREATE TABLE oc_learning_cert_keys (
    id BIGINT NOT NULL AUTO_INCREMENT,
    key_id VARCHAR(64) NOT NULL,
    public_key_b64u VARCHAR(128) NOT NULL,
    secret_key_enc TEXT NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'active',
    created_at BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE INDEX learn_certkey_kid_uniq (key_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE oc_learning_certificates (
    id BIGINT NOT NULL AUTO_INCREMENT,
    verification_id VARCHAR(36) NOT NULL,
    user_id VARCHAR(64) NOT NULL,
    course_id BIGINT NOT NULL,
    key_id VARCHAR(64) NOT NULL,
    credential_json TEXT NOT NULL,
    revoked TINYINT(1) NOT NULL DEFAULT 0,
    issued_at BIGINT NOT NULL DEFAULT 0,
    expires_at BIGINT NULL,
    PRIMARY KEY (id),
    UNIQUE INDEX learn_cert_vid_uniq (verification_id),
    INDEX learn_cert_user_crs_idx (user_id, course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL

myx() { docker exec -i "$CONTAINER" mariadb -uroot -p"$ROOT_PW" "$@"; }

echo "=== Cross-DB migration go/no-go (Version009100) ==="

# ── 0. docker present ─────────────────────────────────────────────────────────────────────────
if ! command -v docker >/dev/null 2>&1; then
    red "NO-GO: docker not available — cannot run the ephemeral MariaDB check"
    exit 1
fi

# ── 1. spin ephemeral MariaDB 11.4 (utf8mb4) ──────────────────────────────────────────────────
echo "→ Starting ephemeral $MARIADB_IMAGE (utf8mb4_general_ci)…"
if ! docker run -d --name "$CONTAINER" \
        -e MARIADB_ROOT_PASSWORD="$ROOT_PW" \
        -e MARIADB_DATABASE="$DB_NAME" \
        "$MARIADB_IMAGE" \
        --character-set-server=utf8mb4 --collation-server=utf8mb4_general_ci >/dev/null 2>&1; then
    red "NO-GO: could not start the MariaDB container"
    exit 1
fi

echo "→ Waiting for MariaDB to accept authenticated connections…"
# NOTE: `mariadb-admin ping` reports "alive" against MariaDB's passwordless temporary init server
# (before the root password is applied), so it races the two-phase init. Instead, loop on a REAL
# authenticated query against the target DB — that only succeeds once the final server (root password
# set + MARIADB_DATABASE created) is up.
ready=0
for _ in $(seq 1 60); do
    if docker exec -i "$CONTAINER" mariadb -uroot -p"$ROOT_PW" -e "SELECT 1;" "$DB_NAME" >/dev/null 2>&1; then
        ready=1; break
    fi
    sleep 2
done
if [[ "$ready" -ne 1 ]]; then
    red "NO-GO: MariaDB did not become ready in time"
    docker logs "$CONTAINER" 2>&1 | tail -20
    exit 1
fi
green "  MariaDB ready"

# ── 2. apply the Version009100 DDL ────────────────────────────────────────────────────────────
echo "→ Applying Version009100 schema (raw DDL, oc_ prefix)…"
DDL_ERR="$(printf '%s\n' "$DDL" | myx "$DB_NAME" 2>&1 >/dev/null)"
if [[ -n "$DDL_ERR" ]]; then
    red "NO-GO: DDL failed:"
    note "$DDL_ERR"
    # The classic utf8mb4 failure mode — surface it explicitly.
    if printf '%s' "$DDL_ERR" | grep -qi "Specified key was too long"; then
        red "  → utf8mb4 index key-length blowup (the exact thing this gate guards against)"
    fi
    exit 1
fi
green "  Both CREATE TABLE succeeded, no 'key too long' error"

# ── 3. assert tables exist ────────────────────────────────────────────────────────────────────
echo "→ Asserting tables + indexes…"
for tbl in oc_learning_cert_keys oc_learning_certificates; do
    cnt="$(myx -N -B "$DB_NAME" -e \
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME' AND table_name='$tbl';" 2>/dev/null)"
    if [[ "$cnt" == "1" ]]; then
        green "  table $tbl created"
    else
        red "  table $tbl MISSING"; FAIL=1
    fi
done

# ── 4. assert each index created + name within MariaDB's 64-char limit ─────────────────────────
assert_index() {
    local tbl="$1" idx="$2"
    local present
    present="$(myx -N -B "$DB_NAME" -e \
        "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema='$DB_NAME' AND table_name='$tbl' AND index_name='$idx';" 2>/dev/null)"
    if [[ "$present" -ge 1 ]]; then
        if [[ "${#idx}" -le 64 ]]; then
            green "  index $idx on $tbl created (name ${#idx} chars <= 64)"
        else
            red "  index $idx name too long (${#idx} > 64)"; FAIL=1
        fi
    else
        red "  index $idx MISSING on $tbl"; FAIL=1
    fi
}
assert_index oc_learning_cert_keys     learn_certkey_kid_uniq
assert_index oc_learning_certificates  learn_cert_vid_uniq
assert_index oc_learning_certificates  learn_cert_user_crs_idx

# ── 5. PostgreSQL 16 — DEFERRED (live schema change gated behind multi-AI review) ──────────────
echo "→ PostgreSQL 16 (live devcloud engine):"
note "DEFERRED — applying Version009100 to live PG16 means \`occ upgrade\` on production, which is"
note "gated behind a multi-AI review (HARD PROD BOUNDARY). This gate does NOT mutate live PG16."
note "POST-REVIEW the PG side is verified live with:"
note "  ssh relais 'docker exec -u www-data devcloud-app php occ db:show-table learning_cert_keys'"
note "  ssh relais 'docker exec -u www-data devcloud-app php occ db:show-table learning_certificates'"
note "(NC maps Types::* per-platform; PG16 has no utf8mb4 key-length limit, so MariaDB is the gating engine.)"

# ── verdict ───────────────────────────────────────────────────────────────────────────────────
echo
if [[ "$FAIL" -eq 0 ]]; then
    green "GO: Version009100 applies clean on MariaDB 11.4 utf8mb4 (PG16 verification deferred to post-review live gate)"
    exit 0
else
    red "NO-GO: one or more MariaDB assertions failed"
    exit 1
fi
