#!/usr/bin/env bash
set -uo pipefail

BASE_URL="${BASE_URL:-http://192.168.178.65:8080}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-admin}"
SECOND_USER="${SECOND_USER:-andre}"
if [[ -z "${SECOND_PASS+x}" ]]; then
    SECOND_PASS='Pa$$w0rd!'
fi

PASS=0
FAIL=0
TMP_DIR="$(mktemp -d)"
LAST_BODY="$TMP_DIR/last-body.json"
LAST_STATUS="000"

POOL_ID=""
QUESTION_ID=""
SESSION_ID=""
COURSE_ID=""
INJECTION_POOL_ID=""
LONG_TEXT_QUESTION_ID=""

cleanup() {
    local auth="${ADMIN_USER}:${ADMIN_PASS}"

    if [[ -n "$QUESTION_ID" ]]; then
        curl -sS -u "$auth" -H "OCS-APIREQUEST: true" -X DELETE \
            "${BASE_URL}/apps/learning/api/questions/${QUESTION_ID}" >/dev/null 2>&1 || true
    fi

    if [[ -n "$LONG_TEXT_QUESTION_ID" ]]; then
        curl -sS -u "$auth" -H "OCS-APIREQUEST: true" -X DELETE \
            "${BASE_URL}/apps/learning/api/questions/${LONG_TEXT_QUESTION_ID}" >/dev/null 2>&1 || true
    fi

    if [[ -n "$INJECTION_POOL_ID" ]]; then
        curl -sS -u "$auth" -H "OCS-APIREQUEST: true" -X DELETE \
            "${BASE_URL}/apps/learning/api/pools/${INJECTION_POOL_ID}" >/dev/null 2>&1 || true
    fi

    if [[ -n "$COURSE_ID" ]]; then
        curl -sS -u "$auth" -H "OCS-APIREQUEST: true" -X DELETE \
            "${BASE_URL}/apps/learning/api/courses/${COURSE_ID}" >/dev/null 2>&1 || true
    fi

    if [[ -n "$POOL_ID" ]]; then
        curl -sS -u "$auth" -H "OCS-APIREQUEST: true" -X DELETE \
            "${BASE_URL}/apps/learning/api/pools/${POOL_ID}" >/dev/null 2>&1 || true
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

request() {
    local method="$1"
    local auth="${2:-}"
    local path="$3"
    local data="${4:-}"

    : >"$LAST_BODY"

    local curl_args=(
        curl -sS
        -H "Accept: application/json"
        -H "OCS-APIREQUEST: true"
        -o "$LAST_BODY"
        -w "%{http_code}"
        -X "$method"
    )

    if [[ -n "$auth" ]]; then
        curl_args+=(-u "$auth")
    fi

    if [[ -n "$data" ]]; then
        curl_args+=(-H "Content-Type: application/json" --data "$data")
    fi

    curl_args+=("${BASE_URL}${path}")

    if ! LAST_STATUS="$("${curl_args[@]}")"; then
        LAST_STATUS="000"
        printf '{"error":"curl_failed"}' >"$LAST_BODY"
    fi
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

admin_auth="${ADMIN_USER}:${ADMIN_PASS}"
second_auth="${SECOND_USER}:${SECOND_PASS}"
suffix="$(date +%s)"

request GET "" "/apps/learning/api/pools"
assert_status "Unauthenticated pools request is rejected" "401"

pool_name="Codex API Pool ${suffix}"
pool_body="$(jq -nc --arg name "$pool_name" --arg description "Codex integration test pool" '{name:$name,description:$description}')"
request POST "$admin_auth" "/apps/learning/api/pools" "$pool_body"
assert_status "Admin can create pool" "201"
assert_json "Created pool response contains id" '.id | numbers'
POOL_ID="$(jq -r '.id // empty' "$LAST_BODY")"

request GET "$admin_auth" "/apps/learning/api/pools"
assert_status "Admin can list pools" "200"
assert_json "Pools response contains own array" '.own | arrays'

updated_pool_name="${pool_name} Updated"
update_pool_body="$(jq -nc --arg name "$updated_pool_name" --arg description "Updated description" '{name:$name,description:$description}')"
request PUT "$admin_auth" "/apps/learning/api/pools/${POOL_ID}" "$update_pool_body"
assert_status "Admin can update pool" "200"
assert_json "Updated pool response reflects new name" --arg name "$updated_pool_name" '.name == $name'

question_body="$(jq -nc \
    --argjson poolId "$POOL_ID" \
    --arg text "Which answer is correct?" \
    --arg explanation "" \
    --arg difficulty "easy" \
    '{poolId:$poolId,text:$text,explanation:$explanation,difficulty:$difficulty,questionType:"single",answers:[{text:"Correct",is_correct:true,position:1},{text:"Wrong",is_correct:false,position:2}]}
')"
request POST "$admin_auth" "/apps/learning/api/questions" "$question_body"
assert_status "Admin can create question" "201"
assert_json "Question response includes answers" '.answers | length == 2'
QUESTION_ID="$(jq -r '.id // empty' "$LAST_BODY")"
CORRECT_ANSWER_ID="$(jq -r '.answers[] | select(.is_correct == true) | .id' "$LAST_BODY")"

request GET "$admin_auth" "/apps/learning/api/pools/${POOL_ID}/questions"
assert_status "Questions list for pool works" "200"
assert_json "Created question is visible in pool listing" --argjson questionId "$QUESTION_ID" 'map(select(.id == $questionId)) | length == 1'

request GET "$second_auth" "/apps/learning/api/pools/${POOL_ID}"
assert_status_in "Other user cannot read foreign pool" "403" "404"

request GET "$second_auth" "/apps/learning/api/pools"
assert_status "Second user can authenticate and list own pools" "200"
assert_json "Second user listing does not expose admin pool" --argjson poolId "$POOL_ID" '(.own + .shared) | map(select(.id == $poolId)) | length == 0'

request POST "$admin_auth" "/apps/learning/api/training/start" "$(jq -nc '{poolId:-1,limit:5,mode:"training"}')"
assert_status_in "Training start rejects negative pool ids" "400" "404"

request POST "$admin_auth" "/apps/learning/api/training/start" "$(jq -nc --argjson poolId "$POOL_ID" '{poolId:$poolId,limit:5,mode:"training"}')"
assert_status "Training session starts successfully" "201"
assert_json "Training start returns a session id" '.session_id | numbers'
assert_json "Training start returns at least one question" '.questions | length > 0'
SESSION_ID="$(jq -r '.session_id // empty' "$LAST_BODY")"

answer_body="$(jq -nc --argjson sessionId "$SESSION_ID" --argjson questionId "$QUESTION_ID" --argjson answerId "$CORRECT_ANSWER_ID" '{sessionId:$sessionId,questionId:$questionId,answerId:$answerId}')"
request POST "$admin_auth" "/apps/learning/api/training/answer" "$answer_body"
assert_status "Training answer submission works" "200"
assert_json "Training answer reports correctness" '.is_correct == true'
assert_json "Training answer returns xp" '.xp_earned > 0'

request POST "$admin_auth" "/apps/learning/api/training/complete" "$(jq -nc --argjson sessionId "$SESSION_ID" '{sessionId:$sessionId}')"
assert_status "Training session can be completed" "200"
assert_json "Complete session returns positive xp" '.xp_earned > 0'
assert_json "Complete session returns score" '.score_percentage >= 0'

course_body="$(jq -nc --arg title "Codex API Course ${suffix}" --arg description "Codex integration test course" '{title:$title,description:$description}')"
request POST "$admin_auth" "/apps/learning/api/courses" "$course_body"
assert_status "Admin can create course" "201"
assert_json "Course creation response contains id" '.id | numbers'
COURSE_ID="$(jq -r '.id // empty' "$LAST_BODY")"

request GET "$admin_auth" "/apps/learning/api/courses"
assert_status "Admin can list courses" "200"
assert_json "Courses response contains own array" '.own | arrays'

request POST "$admin_auth" "/apps/learning/api/courses/${COURSE_ID}/members" "$(jq -nc '{userId:"definitely-not-a-user",role:"student"}')"
assert_status_in "Adding invalid course member fails cleanly" "400" "404"

request GET "$second_auth" "/apps/learning/api/courses/${COURSE_ID}"
assert_status_in "Other user cannot read foreign course" "403" "404"

sql_name="Codex'; DROP TABLE learning_pools; -- ${suffix}"
request POST "$admin_auth" "/apps/learning/api/pools" "$(jq -nc --arg name "$sql_name" '{name:$name,description:"sql injection probe"}')"
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
request POST "$admin_auth" "/apps/learning/api/questions" "$long_text_body"
assert_not_status "Extremely long question text does not trigger a server error" "500"
if [[ "$LAST_STATUS" == "201" ]]; then
    LONG_TEXT_QUESTION_ID="$(jq -r '.id // empty' "$LAST_BODY")"
fi

request DELETE "$admin_auth" "/apps/learning/api/courses/${COURSE_ID}"
assert_status "Admin can delete course" "204"
COURSE_ID=""

request DELETE "$admin_auth" "/apps/learning/api/pools/${POOL_ID}"
assert_status "Admin can delete pool" "204"
POOL_ID=""
QUESTION_ID=""
LONG_TEXT_QUESTION_ID=""

printf '\nResults: %d passed, %d failed\n' "$PASS" "$FAIL"
if [[ "$FAIL" -ne 0 ]]; then
    exit 1
fi
