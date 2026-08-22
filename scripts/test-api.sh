#!/usr/bin/env bash
set -uo pipefail

BASE_URL="${BASE_URL:-https://devcloud.andrestiebitz.de}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-}"
SECOND_USER="${SECOND_USER:-}"
SECOND_PASS="${SECOND_PASS:-}"
TRANSPORT="${TRANSPORT:-local}"
REMOTE_HOST="${REMOTE_HOST:-learning-dev}"
CONTAINER_NAME="${CONTAINER_NAME:-learning-app}"
CONTAINER_USER="${CONTAINER_USER:-www-data}"
BRUTEFORCE_RESET_IPS="${BRUTEFORCE_RESET_IPS:-127.0.0.1 172.18.0.1}"

PASS=0
FAIL=0
SKIP=0
TMP_DIR="$(mktemp -d)"
LAST_BODY="$TMP_DIR/last-body.out"
LAST_STATUS="000"

POOL_ID=""
QUESTION_ID=""
COURSE_ID=""
INJECTION_POOL_ID=""
LONG_TEXT_QUESTION_ID=""
CORRECT_ANSWER_ID=""
LEITNER_ITEM_ID=""
DUEL_CODE=""
CAMPAIGN_ID=""
SOURCE_IP=""

ADMIN_COOKIE_HEADER=""
ADMIN_REQUEST_TOKEN=""
SECOND_COOKIE_HEADER=""
SECOND_REQUEST_TOKEN=""

cleanup() {
    if [[ -n "$QUESTION_ID" ]]; then
        request_silent DELETE admin "/apps/learning/api/questions/${QUESTION_ID}" || true
    fi

    if [[ -n "$LONG_TEXT_QUESTION_ID" ]]; then
        request_silent DELETE admin "/apps/learning/api/questions/${LONG_TEXT_QUESTION_ID}" || true
    fi

    if [[ -n "$INJECTION_POOL_ID" ]]; then
        request_silent DELETE admin "/apps/learning/api/pools/${INJECTION_POOL_ID}" || true
    fi

    if [[ -n "$COURSE_ID" ]]; then
        request_silent DELETE admin "/apps/learning/api/courses/${COURSE_ID}" || true
    fi

    if [[ -n "$POOL_ID" ]]; then
        request_silent DELETE admin "/apps/learning/api/pools/${POOL_ID}" || true
    fi

    rm -rf "$TMP_DIR"
}

trap cleanup EXIT

body_snippet() {
    if [[ -s "$LAST_BODY" ]]; then
        head -c 240 "$LAST_BODY" | tr '\n' ' '
    else
        printf '<empty>'
    fi
}

pass() {
    local label="$1"
    printf 'PASS %s\n' "$label"
    PASS=$((PASS + 1))
}

fail() {
    local label="$1"
    local detail="${2:-}"
    printf 'FAIL %s' "$label"
    if [[ -n "$detail" ]]; then
        printf ' (%s)' "$detail"
    fi
    printf '\n'
    FAIL=$((FAIL + 1))
}

skip() {
    local label="$1"
    local detail="${2:-}"
    printf 'SKIP %s' "$label"
    if [[ -n "$detail" ]]; then
        printf ' (%s)' "$detail"
    fi
    printf '\n'
    SKIP=$((SKIP + 1))
}

json_to_form_pairs() {
    local json="$1"

    jq -j '
        def form_key($path):
            ($path[0] | tostring) + ($path[1:] | map("[" + tostring + "]") | join(""));

        paths(scalars) as $path
        | form_key($path)
        + "="
        + (
            getpath($path)
            | if . == null then
                ""
              elif type == "boolean" then
                (if . then "1" else "0" end)
              else
                tostring
              end
          )
        + "\u0000"
    ' <<<"$json"
}

run_remote() {
    local remote_cmd=("$@")
    local quoted=""

    printf -v quoted '%q ' "${remote_cmd[@]}"
    ssh "$REMOTE_HOST" "$quoted"
}

run_curl() {
    if [[ "$TRANSPORT" == "remote-container" ]]; then
        run_remote docker exec -u "$CONTAINER_USER" "$CONTAINER_NAME" "$@"
        return
    fi

    "$@"
}

base_host() {
    printf '%s\n' "$BASE_URL" | sed -E 's#^[a-zA-Z]+://([^/:]+).*$#\1#'
}

detect_source_ip() {
    local host host_ip

    host="$(base_host)"
    host_ip="$(getent ahostsv4 "$host" 2>/dev/null | awk 'NR == 1 { print $1 }')"
    if [[ -z "$host_ip" ]]; then
        return
    fi

    SOURCE_IP="$(ip route get "$host_ip" 2>/dev/null | awk '{for (i = 1; i <= NF; i++) if ($i == "src") { print $(i + 1); exit }}')"
}

reset_bruteforce() {
    local ip
    local -a reset_ips=()

    if [[ "$TRANSPORT" != "remote-container" ]]; then
        return
    fi

    if [[ -n "$SOURCE_IP" ]]; then
        reset_ips+=("$SOURCE_IP")
    fi

    for ip in $BRUTEFORCE_RESET_IPS; do
        reset_ips+=("$ip")
    done

    for ip in $(printf '%s\n' "${reset_ips[@]}" | awk 'NF && !seen[$0]++'); do
        run_remote docker exec -u "$CONTAINER_USER" "$CONTAINER_NAME" \
            php occ security:bruteforce:reset "$ip" >/dev/null 2>&1 || true
    done
}

cookie_for_session() {
    local var_name="${1^^}_COOKIE_HEADER"
    printf '%s' "${!var_name-}"
}

token_for_session() {
    local var_name="${1^^}_REQUEST_TOKEN"
    printf '%s' "${!var_name-}"
}

login_session() {
    local session_name="$1"
    local user="$2"
    local password="$3"
    local payload cookie_header request_token error_detail
    local cookie_var="${session_name^^}_COOKIE_HEADER"
    local token_var="${session_name^^}_REQUEST_TOKEN"

    if [[ -z "$user" || -z "$password" ]]; then
        fail "Session login for ${session_name}" "missing credentials"
        return 1
    fi

    if ! payload="$(LOGIN_BASE_URL="$BASE_URL" LOGIN_USER="$user" LOGIN_PASS="$password" node <<'NODE' 2>&1
const { chromium } = require('./app/node_modules/playwright');

async function loginWithCredentials(page, user, password) {
  await page.goto(process.env.LOGIN_BASE_URL + '/login', { waitUntil: 'domcontentloaded', timeout: 45000 });
  await page.locator('input[name="user"]').fill(user);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('button[type="submit"]').click({ noWaitAfter: true });
}

(async() => {
  const baseUrl = process.env.LOGIN_BASE_URL;
  const loginUser = process.env.LOGIN_USER;
  const loginPass = process.env.LOGIN_PASS;
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();

  await loginWithCredentials(page, loginUser, loginPass);

  const deadline = Date.now() + 45000;
  let reenteredPassword = false;

  while (Date.now() < deadline) {
    const url = page.url();
    if (url.includes('/apps/learning/')) {
      await page.waitForLoadState('domcontentloaded').catch(() => {});
      await page.waitForTimeout(1500);

      const requesttoken = await page.evaluate(() => {
        return document.head?.dataset?.requesttoken
          || document.documentElement?.dataset?.requesttoken
          || window.OC?.requestToken
          || null;
      }).catch(() => null);

      const cookies = await page.context().cookies(baseUrl);
      const cookieHeader = cookies.map(({ name, value }) => `${name}=${value}`).join('; ');

      console.log(JSON.stringify({
        ok: true,
        url,
        cookieHeader,
        requesttoken,
      }));
      await browser.close();
      return;
    }

    if (!reenteredPassword && url.includes('direct=1')) {
      reenteredPassword = true;
      await page.locator('input[name="password"]').fill(loginPass);
      await page.locator('button[type="submit"]').click({ noWaitAfter: true });
    }

    await page.waitForTimeout(500);
  }

  const text = await page.locator('body').textContent().catch(() => '');
  console.log(JSON.stringify({
    ok: false,
    url: page.url(),
    text: String(text).replace(/\s+/g, ' ').slice(0, 400),
  }));
  await browser.close();
  process.exit(1);
})().catch((error) => {
  console.log(JSON.stringify({ ok: false, error: error.message }));
  process.exit(1);
});
NODE
)"; then
        error_detail="$(jq -r '.error // .text // .url // empty' <<<"$payload" 2>/dev/null)"
        fail "Session login for ${session_name}" "${error_detail:-login failed}"
        return 1
    fi

    cookie_header="$(jq -r '.cookieHeader // empty' <<<"$payload" 2>/dev/null)"
    request_token="$(jq -r '.requesttoken // empty' <<<"$payload" 2>/dev/null)"

    if [[ -z "$cookie_header" || -z "$request_token" ]]; then
        error_detail="$(jq -r '.error // .text // .url // empty' <<<"$payload" 2>/dev/null)"
        fail "Session login for ${session_name}" "${error_detail:-missing cookie header or request token}"
        return 1
    fi

    printf -v "$cookie_var" '%s' "$cookie_header"
    printf -v "$token_var" '%s' "$request_token"
    pass "Session login for ${session_name}"
}

request() {
    local method="$1"
    local session_name="${2:-}"
    local path="$3"
    local data="${4:-}"
    local response
    local marker="__CODEX_STATUS__:"
    local cookie_header request_token

    : >"$LAST_BODY"

    local curl_args=(
        curl -sS
        -H "Accept: application/json"
        -H "OCS-APIREQUEST: true"
        -X "$method"
    )

    if [[ -n "$session_name" ]]; then
        cookie_header="$(cookie_for_session "$session_name")"
        request_token="$(token_for_session "$session_name")"

        if [[ -n "$cookie_header" ]]; then
            curl_args+=(--cookie "$cookie_header")
        fi
        if [[ -n "$request_token" ]]; then
            curl_args+=(-H "requesttoken: $request_token")
        fi
    fi

    if [[ -n "$data" ]]; then
        while IFS= read -r -d '' pair; do
            curl_args+=(--data-urlencode "$pair")
        done < <(json_to_form_pairs "$data")
    fi

    curl_args+=("${BASE_URL}${path}")
    curl_args+=(-w "${marker}%{http_code}")

    if ! response="$(run_curl "${curl_args[@]}")"; then
        LAST_STATUS="000"
        printf '{"error":"curl_failed"}' >"$LAST_BODY"
        return
    fi

    LAST_STATUS="${response##*${marker}}"
    printf '%s' "${response%${marker}*}" >"$LAST_BODY"
}

request_stream() {
    local session_name="$1"
    local path="$2"
    local max_time="${3:-5}"
    local cookie_header request_token
    local headers_file="$TMP_DIR/stream-headers.out"
    local curl_exit=0

    : >"$LAST_BODY"
    : >"$headers_file"

    local curl_args=(
        curl -sS
        --no-buffer
        --max-time "$max_time"
        -D "$headers_file"
        -o "$LAST_BODY"
        -H "Accept: text/event-stream"
        -H "OCS-APIREQUEST: true"
    )

    cookie_header="$(cookie_for_session "$session_name")"
    request_token="$(token_for_session "$session_name")"

    if [[ -n "$cookie_header" ]]; then
        curl_args+=(--cookie "$cookie_header")
    fi
    if [[ -n "$request_token" ]]; then
        curl_args+=(-H "requesttoken: $request_token")
    fi

    curl_args+=("${BASE_URL}${path}")

    run_curl "${curl_args[@]}"
    curl_exit=$?

    LAST_STATUS="$(awk '$1 ~ /^HTTP/ { code = $2 } END { print code ? code : "000" }' "$headers_file")"

    if [[ "$curl_exit" -ne 0 && "$curl_exit" -ne 28 ]]; then
        LAST_STATUS="000"
        printf 'stream_failed' >"$LAST_BODY"
        return
    fi
}

request_silent() {
    local method="$1"
    local session_name="${2:-}"
    local path="$3"
    local data="${4:-}"
    local cookie_header request_token

    local curl_args=(
        curl -sS
        -H "Accept: application/json"
        -H "OCS-APIREQUEST: true"
        -X "$method"
    )

    if [[ -n "$session_name" ]]; then
        cookie_header="$(cookie_for_session "$session_name")"
        request_token="$(token_for_session "$session_name")"

        if [[ -n "$cookie_header" ]]; then
            curl_args+=(--cookie "$cookie_header")
        fi
        if [[ -n "$request_token" ]]; then
            curl_args+=(-H "requesttoken: $request_token")
        fi
    fi

    if [[ -n "$data" ]]; then
        while IFS= read -r -d '' pair; do
            curl_args+=(--data-urlencode "$pair")
        done < <(json_to_form_pairs "$data")
    fi

    curl_args+=("${BASE_URL}${path}")
    run_curl "${curl_args[@]}" >/dev/null 2>&1
}

assert_status() {
    local label="$1"
    local expected="$2"

    if [[ "$LAST_STATUS" == "$expected" ]]; then
        pass "$label"
    else
        fail "$label" "expected ${expected}, got ${LAST_STATUS}, body: $(body_snippet)"
    fi
}

assert_status_in() {
    local label="$1"
    shift
    local expected=("$@")
    local candidate

    for candidate in "${expected[@]}"; do
        if [[ "$LAST_STATUS" == "$candidate" ]]; then
            pass "$label"
            return
        fi
    done

    fail "$label" "expected one of ${expected[*]}, got ${LAST_STATUS}, body: $(body_snippet)"
}

assert_not_status() {
    local label="$1"
    local forbidden="$2"

    if [[ "$LAST_STATUS" != "$forbidden" ]]; then
        pass "$label"
    else
        fail "$label" "unexpected ${forbidden}, body: $(body_snippet)"
    fi
}

assert_json() {
    local label="$1"
    shift
    local jq_expr="${@: -1}"
    local jq_args=("${@:1:$#-1}")

    if jq -e "${jq_args[@]}" "$jq_expr" "$LAST_BODY" >/dev/null 2>&1; then
        pass "$label"
    else
        fail "$label" "jq assertion failed: ${jq_expr}, body: $(body_snippet)"
    fi
}

assert_contains() {
    local label="$1"
    local pattern="$2"

    if rg -q --fixed-strings "$pattern" "$LAST_BODY"; then
        pass "$label"
    else
        fail "$label" "missing pattern: ${pattern}, body: $(body_snippet)"
    fi
}

detect_source_ip
reset_bruteforce

if [[ -z "$ADMIN_PASS" ]]; then
    fail "Admin credentials are available" "set ADMIN_PASS"
    printf '\nResults: %d passed, %d failed, %d skipped\n' "$PASS" "$FAIL" "$SKIP"
    exit 1
fi

login_session admin "$ADMIN_USER" "$ADMIN_PASS" || {
    printf '\nResults: %d passed, %d failed, %d skipped\n' "$PASS" "$FAIL" "$SKIP"
    exit 1
}

if [[ -n "$SECOND_USER" && -n "$SECOND_PASS" ]]; then
    if ! login_session second "$SECOND_USER" "$SECOND_PASS"; then
        skip "Second user permission checks" "login failed for ${SECOND_USER}"
        SECOND_USER=""
        SECOND_PASS=""
    fi
else
    skip "Second user permission checks" "SECOND_USER or SECOND_PASS not configured"
fi

suffix="$(date +%s)"

request GET "" "/apps/learning/api/pools"
assert_status "Unauthenticated pools request is rejected" "401"

pool_name="Codex API Pool ${suffix}"
pool_body="$(jq -nc --arg name "$pool_name" --arg description "Codex integration test pool" '{name:$name,description:$description}')"
request POST admin "/apps/learning/api/pools" "$pool_body"
assert_status "Admin can create pool" "201"
assert_json "Created pool response contains id" '.id | numbers'
POOL_ID="$(jq -r '.id // empty' "$LAST_BODY")"

request GET admin "/apps/learning/api/pools"
assert_status "Admin can list pools" "200"
assert_json "Pools response contains own array" '.own | arrays'

question_body="$(jq -nc \
    --argjson poolId "$POOL_ID" \
    --arg text "Which answer is correct?" \
    --arg explanation "" \
    --arg difficulty "easy" \
    '{poolId:$poolId,text:$text,explanation:$explanation,difficulty:$difficulty,questionType:"single",answers:[{text:"Correct",is_correct:true,position:1},{text:"Wrong",is_correct:false,position:2}]}
')"
request POST admin "/apps/learning/api/questions" "$question_body"
assert_status "Admin can create question" "201"
assert_json "Question response includes answers" '.answers | length == 2'
QUESTION_ID="$(jq -r '.id // empty' "$LAST_BODY")"
CORRECT_ANSWER_ID="$(jq -r '.answers[] | select(.is_correct == true) | .id' "$LAST_BODY")"

request GET admin "/apps/learning/api/pools/${POOL_ID}/questions"
assert_status "Questions list for pool works" "200"
assert_json "Created question is visible in pool listing" --argjson questionId "$QUESTION_ID" 'map(select(.id == $questionId)) | length == 1'

course_body="$(jq -nc --arg title "Codex API Course ${suffix}" --arg description "Codex integration test course" '{title:$title,description:$description}')"
request POST admin "/apps/learning/api/courses" "$course_body"
assert_status "Admin can create course" "201"
assert_json "Course creation response contains id" '.id | numbers'
COURSE_ID="$(jq -r '.id // empty' "$LAST_BODY")"

request PATCH admin "/apps/learning/api/courses/${COURSE_ID}/exam-date" "$(jq -nc '{examDate:"2030-12-31"}')"
assert_status "Course exam date can be updated" "200"
assert_json "Exam date response reflects the saved value" '.exam_date == "2030-12-31"'

request GET admin "/apps/learning/api/story/campaigns"
assert_status "Campaign discovery works" "200"
assert_json "Campaign listing returns an array" 'type == "array"'
CAMPAIGN_ID="$(jq -r '.[0].campaign_id // empty' "$LAST_BODY")"

if [[ -n "$CAMPAIGN_ID" ]]; then
    request PATCH admin "/apps/learning/api/courses/${COURSE_ID}/campaign-selection" "$(jq -nc --arg campaignId "$CAMPAIGN_ID" '{campaignIds:[$campaignId]}')"
    assert_status "Course campaign selection can be updated" "200"
    assert_json "Campaign selection echoes the chosen campaign" --arg campaignId "$CAMPAIGN_ID" '.allowed_campaigns == [$campaignId]'
else
    skip "Course campaign selection can be updated" "no campaigns available"
fi

request POST admin "/apps/learning/api/leitner/initialize" "$(jq -nc --argjson poolId "$POOL_ID" '{poolId:$poolId}')"
assert_status "Leitner initialize works for the created pool" "200"
assert_json "Leitner initialize returns initialized count" '.initialized | numbers'

request GET admin "/apps/learning/api/leitner/due?poolId=${POOL_ID}&limit=5"
assert_status "Leitner due queue works" "200"
assert_json "Leitner due queue returns at least one item" 'length > 0'
LEITNER_ITEM_ID="$(jq -r '.[0].id // empty' "$LAST_BODY")"

request POST admin "/apps/learning/api/leitner/answer" "$(jq -nc --argjson itemId "$LEITNER_ITEM_ID" --argjson answerId "$CORRECT_ANSWER_ID" '{itemId:$itemId,answerId:$answerId,preview:true}')"
assert_status "Leitner preview answer works" "200"
assert_json "Leitner preview marks the review as awaiting rating" '.preview == true and .awaiting_rating == true'

request POST admin "/apps/learning/api/leitner/answer" "$(jq -nc --argjson itemId "$LEITNER_ITEM_ID" --argjson answerId "$CORRECT_ANSWER_ID" '{itemId:$itemId,answerId:$answerId,rating:3}')"
assert_status "Leitner FSRS answer works" "200"
assert_json "Leitner FSRS response includes rating" '.rating == 3'
assert_json "Leitner FSRS response includes stability" '.stability > 0'
assert_json "Leitner FSRS response includes difficulty" '.difficulty > 0'
assert_json "Leitner FSRS response includes retrievability" '.retrievability >= 0'

request POST admin "/apps/learning/api/duels" "$(jq -nc --argjson poolId "$POOL_ID" '{poolId:$poolId,numQuestions:5}')"
assert_status "Duel creation works" "201"
assert_json "Duel creation returns a code" '.code | strings | length > 0'
DUEL_CODE="$(jq -r '.code // empty' "$LAST_BODY")"

request_stream admin "/apps/learning/api/sse/duel/${DUEL_CODE}" 5
assert_status "Duel SSE endpoint is reachable" "200"
assert_contains "Duel SSE emits a state event" "event: state"
assert_contains "Duel SSE payload includes the duel code" "\"code\":\"${DUEL_CODE}\""

if [[ -n "$SECOND_USER" ]]; then
    request GET second "/apps/learning/api/pools/${POOL_ID}"
    assert_status_in "Second user cannot read foreign pool" "403" "404"

    request GET second "/apps/learning/api/courses/${COURSE_ID}"
    assert_status_in "Second user cannot read foreign course" "403" "404"
fi

sql_name="Codex'; DROP TABLE learning_pools; -- ${suffix}"
request POST admin "/apps/learning/api/pools" "$(jq -nc --arg name "$sql_name" '{name:$name,description:"sql injection probe"}')"
assert_not_status "SQL injection-style pool name does not crash the API" "500"
if [[ "$LAST_STATUS" == "201" ]]; then
    INJECTION_POOL_ID="$(jq -r '.id // empty' "$LAST_BODY")"
fi

long_text="$(head -c 10000 </dev/zero | tr '\0' 'L')"
long_text_body="$(jq -nc \
    --argjson poolId "$POOL_ID" \
    --arg text "$long_text" \
    '{poolId:$poolId,text:$text,explanation:"",difficulty:"easy",questionType:"single",answers:[{text:"A",is_correct:true,position:1},{text:"B",is_correct:false,position:2}]}
')"
request POST admin "/apps/learning/api/questions" "$long_text_body"
assert_not_status "Extremely long question text does not trigger a server error" "500"
if [[ "$LAST_STATUS" == "201" ]]; then
    LONG_TEXT_QUESTION_ID="$(jq -r '.id // empty' "$LAST_BODY")"
fi

# ── Training flow ─────────────────────────────────────────────────
request POST admin "/apps/learning/api/training/start" "$(jq -nc --argjson poolId "$POOL_ID" '{poolId:$poolId,limit:5}')"
assert_status_in "Training start works" "200" "201"
SESSION_ID="$(jq -r '.sessionId // .session_id // empty' "$LAST_BODY")"

if [[ -n "$SESSION_ID" ]]; then
    request GET admin "/apps/learning/api/training/session/${SESSION_ID}"
    assert_status "Training session status works" "200"

    request POST admin "/apps/learning/api/training/answer" "$(jq -nc --argjson sessionId "$SESSION_ID" --argjson questionId "$QUESTION_ID" --argjson answerId "$CORRECT_ANSWER_ID" '{sessionId:$sessionId,questionId:$questionId,answerId:$answerId}')"
    assert_status "Training answer works" "200"

    request POST admin "/apps/learning/api/training/abort" "$(jq -nc --argjson sessionId "$SESSION_ID" '{sessionId:$sessionId}')"
    assert_status_in "Training abort works" "200" "204"
else
    skip "Training session status" "no session ID returned"
    skip "Training answer" "no session ID"
    skip "Training abort" "no session ID"
fi

# ── Course enrollment + progress ──────────────────────────────────
request POST admin "/apps/learning/api/courses/${COURSE_ID}/pools" "$(jq -nc --argjson poolId "$POOL_ID" '{poolId:$poolId}')"
assert_status_in "Add pool to course works" "200" "201"

request POST admin "/apps/learning/api/courses/${COURSE_ID}/enroll"
assert_status_in "Course self-enrollment works" "200" "201" "409"

# ── Phase 154: Cert Config + Pass Status ──────────────────────────
# (placed after the pool is attached to the course so certRequiredPoolIds validation has a valid pool)

# Instructor: enable cert config with valid payload
request PATCH admin "/apps/learning/api/courses/${COURSE_ID}/cert-config" "$(jq -nc '{certEnabled:true,certPassPercent:80}')"
assert_status "Instructor can enable cert config" "200"
assert_json "certConfig response has certEnabled" '.certEnabled == true'

# Instructor: certPassPercent out of range → 400
request PATCH admin "/apps/learning/api/courses/${COURSE_ID}/cert-config" "$(jq -nc '{certPassPercent:0}')"
assert_status "certPassPercent=0 rejected with 400" "400"

request PATCH admin "/apps/learning/api/courses/${COURSE_ID}/cert-config" "$(jq -nc '{certPassPercent:101}')"
assert_status "certPassPercent=101 rejected with 400" "400"

# Instructor: certValidityDays negative → 400
request PATCH admin "/apps/learning/api/courses/${COURSE_ID}/cert-config" "$(jq -nc '{certValidityDays:-1}')"
assert_status "certValidityDays=-1 rejected with 400" "400"

# Instructor: foreign pool ID in certRequiredPoolIds → 400
request PATCH admin "/apps/learning/api/courses/${COURSE_ID}/cert-config" "$(jq -nc '{certRequiredPoolIds:[99999]}')"
assert_status "Foreign pool ID in certRequiredPoolIds rejected with 400" "400"

# Instructor: pool belonging to this course accepted
request PATCH admin "/apps/learning/api/courses/${COURSE_ID}/cert-config" "$(jq -nc --argjson poolId "$POOL_ID" '{certRequiredPoolIds:[$poolId]}')"
assert_status "Own pool ID accepted in certRequiredPoolIds" "200"

# Non-instructor: cert-config write → 403
if [[ -n "${SECOND_USER}" ]]; then
    request PATCH second "/apps/learning/api/courses/${COURSE_ID}/cert-config" "$(jq -nc '{certEnabled:false}')"
    assert_status "Non-instructor cert-config rejected with 403" "403"
fi

# ── Compliance report (Phase 156 — REPORT-01..04) ─────────────────────────────
# Owner-scoped, DSGVO-safe report. The course may have no issued certs yet (rows: []);
# the HARD no-leak gates below still hold (no recipient-id key, no '@' email token in
# either the JSON table or the CSV). bruteforce-reset 172.21.0.1 is a precondition (above).
# This block runs only with credentials — the script already exits earlier when ADMIN_PASS
# is unset, so the credentialed cert-report run is the deferred Gate 2 (documented in SUMMARY).
request GET admin "/apps/learning/api/courses/${COURSE_ID}/cert-report"
assert_status "Instructor can read cert-report (JSON)" "200"
assert_json "cert-report response has rows array" '.rows | arrays'
# HARD no-leak: no recipient-id key anywhere in the JSON body.
assert_json "cert-report JSON exposes no recipient-id key" '[.. | objects | keys[]] | index("user_id") | not'
if rg -q '@' "$LAST_BODY"; then
    fail "cert-report JSON body has no @ email token" "found @ in body: $(body_snippet)"
else
    pass "cert-report JSON body has no @ email token"
fi

# IDOR: a non-owner instructor must be refused (403), never a row.
if [[ -n "${SECOND_USER}" ]]; then
    request GET second "/apps/learning/api/courses/${COURSE_ID}/cert-report"
    assert_status "Non-owner blocked from cert-report with 403" "403"
fi

# CSV export: same filtered set, injection-safe, display-name only, text/csv content-type.
cert_csv_headers="$TMP_DIR/cert-report-csv-headers.out"
: >"$cert_csv_headers"
run_curl curl -sS \
    --cookie "$(cookie_for_session admin)" \
    -H "requesttoken: $(token_for_session admin)" \
    -H "OCS-APIREQUEST: true" \
    -D "$cert_csv_headers" \
    -o "$LAST_BODY" \
    "${BASE_URL}/apps/learning/api/courses/${COURSE_ID}/cert-report/export/csv" >/dev/null 2>&1 || true
if rg -qi '^content-type:[[:space:]]*text/csv' "$cert_csv_headers"; then
    pass "cert-report CSV has text/csv content-type"
else
    fail "cert-report CSV has text/csv content-type" "headers: $(tr '\n' ' ' <"$cert_csv_headers" | head -c 200)"
fi
assert_contains "cert-report CSV has the Verifizierungs-ID header" "Verifizierungs-ID"
if rg -q '@|user_id' "$LAST_BODY"; then
    fail "cert-report CSV body has no @ email token and no recipient-id column" "body: $(body_snippet)"
else
    pass "cert-report CSV body has no @ email token and no recipient-id column"
fi

# ── Group compliance report — team-lead RBAC-02 (Phase 163-05) ────────────────
# IDOR gate must reject before reading any data source.
# (a) absent groupId (empty string) → fails closed; service throws before any DB read
request GET admin "/apps/learning/api/courses/${COURSE_ID}/group-report"
assert_status "group-report without groupId fails closed (403)" "403"

# (b) non-authorized group → 403 even for admin (no oversight row ⇒ ForbiddenException)
request GET admin "/apps/learning/api/courses/${COURSE_ID}/group-report?groupId=no-such-group-idor-test"
assert_status "group-report for unauthorized group returns 403 (IDOR gate)" "403"

# (c) second user also blocked
if [[ -n "${SECOND_USER}" ]]; then
    request GET second "/apps/learning/api/courses/${COURSE_ID}/group-report?groupId=no-such-group-idor-test"
    assert_status "Non-team-lead blocked from group-report (403)" "403"
fi

# (d) myTeamLeadScopes: always 200 for authenticated user; returns empty scopes when no oversight rows
request GET admin "/apps/learning/api/my-team-lead-scopes"
assert_status "myTeamLeadScopes returns 200 for authenticated user" "200"
assert_json "myTeamLeadScopes response has scopes array" '.scopes | arrays'

# ── Compliance reminder — RBAC-04 (Phase 163-06) ─────────────────────────────
# The remind POST is a separate IDOR surface from the group-report GET.
# It independently re-validates lead role + target membership before dispatching.
# (a) absent groupId → service fails closed (ForbiddenException before any DB read)
request POST admin "/apps/learning/api/courses/${COURSE_ID}/group-report/remind" \
    "$(jq -nc '{groupId:"",targetUserId:"someuser"}')"
assert_status "remind without groupId fails closed (403)" "403"

# (b) non-authorized group → 403 even for admin (no oversight row ⇒ ForbiddenException)
request POST admin "/apps/learning/api/courses/${COURSE_ID}/group-report/remind" \
    "$(jq -nc '{groupId:"no-such-group-idor-test",targetUserId:"someuser"}')"
assert_status "remind for unauthorized group returns 403 (IDOR gate)" "403"

# (c) second user also blocked
if [[ -n "${SECOND_USER}" ]]; then
    request POST second "/apps/learning/api/courses/${COURSE_ID}/group-report/remind" \
        "$(jq -nc '{groupId:"no-such-group-idor-test",targetUserId:"someuser"}')"
    assert_status "Non-team-lead blocked from remind (403)" "403"
fi
# NOTE: own-group-member → 200 requires a live oversight row provisioned for the test user.
# Deferred to manual Gate 2 run once RoleService::getTeamLeadGroups returns a real entry.

# Course owner: pass status → 200
request GET admin "/apps/learning/api/courses/${COURSE_ID}/pass-status"
assert_status "Course owner can read pass status" "200"
assert_json "Pass status response has applicable boolean" '.applicable | type == "boolean"'
assert_json "Pass status response has passed boolean" '.passed | type == "boolean"'

# IDOR guard: non-enrolled user → 403
if [[ -n "${SECOND_USER}" ]]; then
    request GET second "/apps/learning/api/courses/${COURSE_ID}/pass-status"
    assert_status "Non-enrolled user blocked from pass-status with 403" "403"
fi

request GET admin "/apps/learning/api/courses/${COURSE_ID}/my-progress"
assert_status "Course my-progress works" "200"

request GET admin "/apps/learning/api/courses/${COURSE_ID}/leaderboard"
assert_status "Course leaderboard works" "200"

request GET admin "/apps/learning/api/courses/${COURSE_ID}/progress"
assert_status "Course progress (instructor) works" "200"

request GET admin "/apps/learning/api/courses/${COURSE_ID}/dashboard"
assert_status "Course dashboard works" "200"

request GET admin "/apps/learning/api/courses/${COURSE_ID}/chapter-heatmap"
assert_status "Course chapter heatmap works" "200"

request GET admin "/apps/learning/api/courses/${COURSE_ID}/weak-questions"
assert_status "Course weak questions works" "200"

# ── Leitner extended ──────────────────────────────────────────────
request GET admin "/apps/learning/api/leitner/queue?poolId=${POOL_ID}"
assert_status "Leitner queue works" "200"

request GET admin "/apps/learning/api/leitner/queue/count?poolId=${POOL_ID}"
assert_status "Leitner queue count works" "200"

request GET admin "/apps/learning/api/leitner/stats?poolId=${POOL_ID}"
assert_status "Leitner stats works" "200"

request GET admin "/apps/learning/api/streak"
assert_status "Streak endpoint works" "200"

request GET admin "/apps/learning/api/badges"
assert_status "Badges endpoint works" "200"

request GET admin "/apps/learning/api/badges/progress"
assert_status "Badge progress endpoint works" "200"

# ── VirtuProf ─────────────────────────────────────────────────────
request GET admin "/apps/learning/api/virtuprof/state"
assert_status "VirtuProf state works" "200"
assert_json "VirtuProf state contains enabled field" 'has("enabled")'
assert_json "VirtuProf state contains skin field" 'has("skin")'

request PUT admin "/apps/learning/api/virtuprof/preferences" '{"skin":"prof_lern_classic"}'
assert_status "VirtuProf skin preference can be saved" "200"
assert_json "VirtuProf skin save response echoes prof_lern_classic" '.skin == "prof_lern_classic"'

request GET admin "/apps/learning/api/virtuprof/state"
assert_status "VirtuProf skin preference round-trip GET works" "200"
assert_json "VirtuProf skin preference round-trip persists prof_lern_classic" '.skin == "prof_lern_classic"'

request_silent PUT admin "/apps/learning/api/virtuprof/preferences" '{"skin":"nova"}'

request GET admin "/apps/learning/api/virtu-prof/chat-history"
assert_status "VirtuProf chat history works" "200"

# ── Telos ─────────────────────────────────────────────────────────
request GET admin "/apps/learning/api/profile/telos/status"
assert_status "Telos status works" "200"

request GET admin "/apps/learning/api/profile/telos/consent"
assert_status "Telos consent status works" "200"

request GET admin "/apps/learning/api/profile/telos"
assert_status "Telos get works" "200"

# ── User State ────────────────────────────────────────────────────
request GET admin "/apps/learning/api/v1/user/state"
assert_status "User state works" "200"
assert_json "User state includes xp" 'has("xp")'

request GET admin "/apps/learning/api/v1/missions"
assert_status "Missions endpoint works" "200"

request GET admin "/apps/learning/api/v1/daily-challenge"
assert_status "Daily challenge endpoint works" "200"

# ── Feed ──────────────────────────────────────────────────────────
request GET admin "/apps/learning/api/feed"
assert_status "Global feed works" "200"

request GET admin "/apps/learning/api/courses/${COURSE_ID}/feed"
assert_status "Course feed works" "200"

# ── Settings ──────────────────────────────────────────────────────
request GET admin "/apps/learning/api/settings/admin"
assert_status "Admin settings works" "200"

request GET admin "/apps/learning/api/settings/personal"
assert_status "Personal settings works" "200"

request GET admin "/apps/learning/api/settings/tools"
assert_status "Tools settings works" "200"

# ── Profile ───────────────────────────────────────────────────────
request GET admin "/apps/learning/api/profile"
assert_status "Profile endpoint works" "200"

request GET admin "/apps/learning/api/profile/weakest"
assert_status "Weakest topics endpoint works" "200"

request GET admin "/apps/learning/api/profile/history"
assert_status "Learn history endpoint works" "200"

# Was assert_status_in 200/500 until 2026-08-22, which reported a hard SQL error (c.name on
# learning_courses, which has title) as "works" from 2026-04-09 on. 500 is not an outcome.
request GET admin "/apps/learning/api/profile/skill-map"
assert_status "Skill map endpoint works" "200"
assert_json "Skill map returns courses and pools" '(.courses | type == "array") and (.pools | type == "array")'
# Checks the repaired query end to end, not just the response shape: the pool created above was
# attached to the course above, so it must come back carrying that course id and its real title.
# A "type == array" assertion passed happily while this query was returning nothing at all.
assert_json "Skill map maps the pool to its course" \
    "[.pools[] | select(.pool_id == ${POOL_ID}) | .course_id] == [${COURSE_ID}]"
assert_json "Skill map reports the course title" \
    "[.courses[] | select(.id == ${COURSE_ID}) | .name | length > 0] == [true]"

# NEGATIVE test for the course-access condition in enrichPoolsWithCourseData: a second user who
# can reach the pool through a share, but is NOT a member of the course it was added to, must see
# the pool WITHOUT any course label. Before that condition existed, they got the course id and
# title. The positive assertions above cannot catch a filter that lets everything through.
if [[ -n "$SECOND_USER" ]]; then
    request POST admin "/apps/learning/api/pools/${POOL_ID}/shares" \
        "$(jq -nc --arg u "$SECOND_USER" '{sharedWith:$u, permission:"read"}')"
    assert_status_in "Pool shared with second user for the access test" "200" "201" "409"

    request GET second "/apps/learning/api/profile/skill-map"
    assert_status "Skill map works for the second user" "200"
    assert_json "Second user sees the shared pool" \
        "[.pools[] | select(.pool_id == ${POOL_ID})] | length == 1"
    assert_json "Second user gets NO course label for it (not a member)" \
        "[.pools[] | select(.pool_id == ${POOL_ID}) | .course_id] == [null]"
    assert_json "Second user sees no course entry for that course" \
        "[.courses[] | select(.id == ${COURSE_ID})] | length == 0"

    request DELETE admin "/apps/learning/api/pools/${POOL_ID}/shares/${SECOND_USER}"
    assert_status_in "Share cleaned up" "200" "204" "404"
else
    skip "Skill map course-access negative test" "SECOND_USER not configured"
fi

# ── Sharing ───────────────────────────────────────────────────────
request GET admin "/apps/learning/api/shared"
assert_status "Shared with me endpoint works" "200"

request GET admin "/apps/learning/api/pools/${POOL_ID}/shares"
assert_status "Pool shares list works" "200"

# ── Import/Export ─────────────────────────────────────────────────
request GET admin "/apps/learning/api/pools/${POOL_ID}/export/json"
assert_status "Pool JSON export works" "200"

request GET admin "/apps/learning/api/pools/${POOL_ID}/export/csv"
assert_status "Pool CSV export works" "200"

request GET admin "/apps/learning/api/export/my-data"
assert_status "My data export works" "200"

# ── AI ────────────────────────────────────────────────────────────
# NOT assert_status_in 200/500: available() always answers 200 with {available:bool},
# also when no API key is configured. Tolerating 500 here hid Codeberg #2 (the router
# resolved 'ai#available' to AiController while the file was named AIController.php)
# for the whole life of the endpoint — five months, 23 tagged releases.
request GET admin "/apps/learning/api/ai/available"
assert_status "AI available endpoint works" "200"
assert_json "AI available reports a boolean flag" '.available | type == "boolean"'

# ── Starter Pools ────────────────────────────────────────────────
request GET admin "/apps/learning/api/starter-pools"
assert_status "Starter pools list works" "200"

# ── Story/Campaigns ──────────────────────────────────────────────
request GET admin "/apps/learning/api/story/progress"
assert_status "Story progress works" "200"

# ── Gameshow ──────────────────────────────────────────────────────
request GET admin "/apps/learning/api/gameshow/history"
assert_status "Gameshow history works" "200"

# ── Support Tickets ──────────────────────────────────────────────
request GET admin "/apps/learning/api/support-tickets"
assert_status "My support tickets works" "200"

request GET admin "/apps/learning/api/settings/admin/support-tickets"
assert_status "Admin support tickets list works" "200"

# ── Role ──────────────────────────────────────────────────────────
request GET admin "/apps/learning/api/role"
assert_status "Role endpoint works" "200"

# ── Instructor Dashboard ─────────────────────────────────────────
request GET admin "/apps/learning/api/instructor/dashboard"
assert_status "Instructor dashboard works" "200"

# ── Question search ──────────────────────────────────────────────
request GET admin "/apps/learning/api/questions/search?poolId=${POOL_ID}&q=correct&limit=5"
assert_status_in "Question search works" "200" "400"

# ── Certificates / did:web (Phase 155 — ADR follow-up #3: kid ↔ did.json) ─────────
# Asserts against a LIVE issuer. These SKIP (not fail) until the 155-01 migration is
# applied + an issuer key exists (`occ learning:cert:init-issuer`), at which point
# did.json returns 200 with a non-empty verificationMethod and the block auto-activates
# to HARD-assert that an issued JWT's `kid` equals a verificationMethod.id. Keeping the
# interim a SKIP is the honest "written but not live" state (issuer provisioning is gated
# behind a multi-AI review — HARD PROD BOUNDARY).

# Decode a compact JWT header and print its `kid` (base64url, padding-restored).
jwt_header_kid() {
    # NOTE: declare separately — a single `local jwt="$1" h="${jwt%%.*}"` expands
    # ${jwt%%.*} against the OUTER (unset) jwt before local assigns it, which aborts
    # under `set -u` ("jwt: unbound variable"). Only surfaced once a real cert existed.
    local jwt="${1:-}"
    local h="${jwt%%.*}"
    local mod
    mod=$(( ${#h} % 4 ))
    if [[ $mod -eq 2 ]]; then h="${h}=="; elif [[ $mod -eq 3 ]]; then h="${h}="; fi
    printf '%s' "$h" | tr '_-' '/+' | base64 -d 2>/dev/null | jq -r '.kid // empty' 2>/dev/null
}

cert_host="$(base_host)"
request GET "" "/apps/learning/did.json"
if [[ "$LAST_STATUS" != "200" ]] || ! jq -e '.verificationMethod | type == "array" and length > 0' "$LAST_BODY" >/dev/null 2>&1; then
    skip "did.json resolves with verificationMethod" "issuer unprovisioned (155-01 migration unapplied / no issuer key) — HTTP ${LAST_STATUS}; deferred to post-review live gate"
    skip "JWT kid == did.json verificationMethod.id" "no live did.json — deferred to post-review live gate"
    skip "rotation preserves retired key in did.json" "no live issuer — deferred to post-review live gate"
else
    pass "did.json resolves with verificationMethod"
    # Every verificationMethod.id must be the path-based did:web fragment of THIS host.
    assert_json "did.json ids are did:web:${cert_host}:apps:learning#…" --arg h "$cert_host" \
        '.verificationMethod | all(.[]; .id | startswith("did:web:" + $h + ":apps:learning#"))'
    cp "$LAST_BODY" "$TMP_DIR/did.json"

    # kid ↔ verificationMethod.id on a REAL issued credential (own certs list).
    request GET admin "/apps/learning/api/certificates"
    cert_jwt="$(jq -r 'if type=="array" then .[0] else ((.certificates // .data // [])[0]) end | (.credential_json // .credentialJson // empty)' "$LAST_BODY" 2>/dev/null)"
    if [[ -z "$cert_jwt" || "$cert_jwt" == "null" ]]; then
        skip "JWT kid == did.json verificationMethod.id" "no issued certificate for admin yet — trigger a qualifying pass first (post-review live gate)"
    else
        cert_kid="$(jwt_header_kid "$cert_jwt")"
        if [[ -n "$cert_kid" ]] && jq -e --arg k "$cert_kid" 'any(.verificationMethod[]; .id == $k)' "$TMP_DIR/did.json" >/dev/null 2>&1; then
            pass "JWT kid == did.json verificationMethod.id (kid=${cert_kid})"
        else
            fail "JWT kid == did.json verificationMethod.id" "kid '${cert_kid}' not among did.json verificationMethod ids"
        fi
    fi

    # rotation-preserves: after --rotate, the PREVIOUS (now retired) key id must still be
    # served by did.json so credentials signed by it keep verifying. This step runs
    # `occ learning:cert:init-issuer --rotate` — a DESTRUCTIVE live mutation — so it is
    # opt-in (ALLOW_LIVE_ROTATE=1) and only in the post-review live gate. Default: SKIP.
    if [[ "${ALLOW_LIVE_ROTATE:-0}" == "1" && "$TRANSPORT" == "remote-container" ]]; then
        jq -r '.verificationMethod[].id' "$TMP_DIR/did.json" | sort -u > "$TMP_DIR/prev_ids"
        run_remote docker exec -u "$CONTAINER_USER" "$CONTAINER_NAME" \
            php occ learning:cert:init-issuer --rotate >/dev/null 2>&1 || true
        request GET "" "/apps/learning/did.json"
        jq -r '.verificationMethod[].id' "$LAST_BODY" 2>/dev/null | sort -u > "$TMP_DIR/now_ids"
        missing_ids="$(comm -23 "$TMP_DIR/prev_ids" "$TMP_DIR/now_ids")"
        if [[ -z "$missing_ids" ]]; then
            pass "rotation preserves retired key in did.json"
        else
            fail "rotation preserves retired key in did.json" "vanished after --rotate: ${missing_ids//$'\n'/, }"
        fi
    else
        skip "rotation preserves retired key in did.json" "destructive live --rotate; set ALLOW_LIVE_ROTATE=1 (post-review live gate only)"
    fi
fi

# ── DSGVO Art.20 — self-service data export: certificates block (Phase 163-04) ──────
# SESSION-ONLY endpoint: GET /apps/learning/api/export/my-data returns the caller's own
# data. There is NO userId query/path param — a foreign export is structurally impossible.
# Bruteforce-reset is a precondition (done above at test start).
# Gate 2 deferred: runs after Wave-1 deploy via orchestrator's central API-integration run.
request GET admin "/apps/learning/api/export/my-data"
assert_status "DSGVO my-data export: admin gets 200" "200"
# Structural assertion: 'certificates' key always present; value is always an array.
assert_json "DSGVO my-data export: certificates is an array" '.certificates | arrays'
# credential_jwt check: conditional — admin may not hold a cert in all environments.
export_cert_jwt="$(jq -r '.certificates[0].credential_jwt // empty' "$LAST_BODY" 2>/dev/null)"
if [[ -z "$export_cert_jwt" ]]; then
    skip "DSGVO my-data export: credential_jwt present in certificates[0]" \
        "admin has no issued certificate yet — run qualifying pass first (post-deploy Gate 2)"
else
    if [[ "$export_cert_jwt" =~ ^eyJ ]]; then
        pass "DSGVO my-data export: credential_jwt present and is a JWT (Art.20 portable artifact)"
    else
        fail "DSGVO my-data export: credential_jwt present and is a JWT (Art.20 portable artifact)" \
            "got: $(printf '%s' "$export_cert_jwt" | head -c 40)"
    fi
fi

# ── Cleanup ───────────────────────────────────────────────────────

request DELETE admin "/apps/learning/api/courses/${COURSE_ID}"
assert_status "Admin can delete course" "204"
COURSE_ID=""

request DELETE admin "/apps/learning/api/pools/${POOL_ID}"
assert_status "Admin can delete pool" "204"
POOL_ID=""
QUESTION_ID=""
LONG_TEXT_QUESTION_ID=""

printf '\nResults: %d passed, %d failed, %d skipped\n' "$PASS" "$FAIL" "$SKIP"
if [[ "$FAIL" -ne 0 ]]; then
    exit 1
fi
