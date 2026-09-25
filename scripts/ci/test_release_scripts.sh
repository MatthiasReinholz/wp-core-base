#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
FIXTURE_ROOT="${REPO_ROOT}/tools/wporg-updater/tests/fixtures/release-scripts"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

LOCAL_DIR="${TMP_DIR}/local"
REMOTE_DIR="${TMP_DIR}/remote"
FAKE_BIN="${TMP_DIR}/fake-bin"
mkdir -p "${LOCAL_DIR}" "${REMOTE_DIR}" "${FAKE_BIN}"
CURL_LOG="${TMP_DIR}/curl.log"
GIT_LOG="${TMP_DIR}/git.log"

ARTIFACT_PATH="${LOCAL_DIR}/wp-core-base-vendor-snapshot.zip"
CHECKSUM_PATH="${LOCAL_DIR}/wp-core-base-vendor-snapshot.zip.sha256"
SIGNATURE_PATH="${LOCAL_DIR}/wp-core-base-vendor-snapshot.zip.sha256.sig"
EXPECTED_RELEASE_TITLE="wp-core-base v1.3.2"
EXPECTED_RELEASE_NOTES_PATH="${LOCAL_DIR}/release-notes.md"

printf 'fixture artifact\n' > "${ARTIFACT_PATH}"
printf '%s  %s\n' "$(shasum -a 256 "${ARTIFACT_PATH}" | awk '{print $1}')" "$(basename "${ARTIFACT_PATH}")" > "${CHECKSUM_PATH}"
printf 'fixture signature\n' > "${SIGNATURE_PATH}"
printf 'Fixture release notes.\n' > "${EXPECTED_RELEASE_NOTES_PATH}"

cp "${ARTIFACT_PATH}" "${REMOTE_DIR}/artifact-current"
cp "${CHECKSUM_PATH}" "${REMOTE_DIR}/checksum-current"
cp "${SIGNATURE_PATH}" "${REMOTE_DIR}/signature-current"
printf 'stale remote artifact\n' > "${REMOTE_DIR}/artifact-stale"

cat > "${FAKE_BIN}/curl" <<'EOF'
#!/usr/bin/env bash

set -euo pipefail

output=""
write_format=""
url=""
redirect_url=""
auth_header_present="false"
method="GET"
follow_redirects=false
https_only=false
connect_timeout=false
total_timeout=false
size_limit=false

while [ "$#" -gt 0 ]; do
  case "$1" in
    -o)
      output="$2"
      shift 2
      ;;
    -w)
      write_format="$2"
      shift 2
      ;;
    -H)
      if [ "$2" = "Authorization: Bearer ${GITHUB_TOKEN:-}" ]; then
        auth_header_present="true"
      fi
      shift 2
      ;;
    -X)
      method="$2"
      shift 2
      ;;
    --proto)
      [ "$2" != '=https' ] || https_only=true
      shift 2
      ;;
    --connect-timeout)
      connect_timeout=true
      shift 2
      ;;
    --max-time)
      total_timeout=true
      shift 2
      ;;
    --max-filesize)
      size_limit=true
      shift 2
      ;;
    -fsSL|-L)
      follow_redirects=true
      shift
      ;;
    -sS|-f|-s|-S|--tlsv1.2)
      shift
      ;;
    *)
      url="$1"
      shift
      ;;
  esac
done

[ "$follow_redirects" = false ] && [ "$https_only" = true ] && [ "$connect_timeout" = true ] && [ "$total_timeout" = true ] && [ "$size_limit" = true ] || {
  echo 'Release helpers must use bounded HTTPS without implicit redirects.' >&2
  exit 1
}

printf '%s %s\n' "${method}" "${url}" >> "${FAKE_CURL_LOG:-/dev/null}"

status="200"
body_file=""

  case "$url" in
    */commits/*/pulls)
      status="${FAKE_API_READ_STATUS:-200}"
      body_file="${FAKE_PULLS_FIXTURE:?}"
      ;;
  */actions/workflows/*/runs\?head_sha=*)
    if [ -n "${FAKE_RUNS_FIXTURE_SEQUENCE:-}" ]; then
      sequence_index_file="${FAKE_RUNS_SEQUENCE_INDEX_FILE:?}"
      sequence_index=0

      if [ -f "${sequence_index_file}" ]; then
        sequence_index="$(cat "${sequence_index_file}")"
      fi

      IFS=':' read -r -a run_fixtures <<< "${FAKE_RUNS_FIXTURE_SEQUENCE}"

      if [ "${sequence_index}" -ge "${#run_fixtures[@]}" ]; then
        sequence_index=$((${#run_fixtures[@]} - 1))
      fi

      body_file="${run_fixtures[$sequence_index]}"
      printf '%s' "$((sequence_index + 1))" > "${sequence_index_file}"
    else
      body_file="${FAKE_RUNS_FIXTURE:?}"
    fi
    ;;
    */releases/tags/*)
    if [ -n "${FAKE_RELEASE_STATUS_SEQUENCE:-}" ]; then
      sequence_index_file="${FAKE_RELEASE_SEQUENCE_INDEX_FILE:?}"
      sequence_index=0

      if [ -f "${sequence_index_file}" ]; then
        sequence_index="$(cat "${sequence_index_file}")"
      fi

      IFS=':' read -r -a release_statuses <<< "${FAKE_RELEASE_STATUS_SEQUENCE}"

      if [ "${sequence_index}" -ge "${#release_statuses[@]}" ]; then
        sequence_index=$((${#release_statuses[@]} - 1))
      fi

      status="${release_statuses[$sequence_index]}"
      printf '%s' "$((sequence_index + 1))" > "${sequence_index_file}"
    else
      status="${FAKE_RELEASE_STATUS:-200}"
    fi

    body_file="${FAKE_RELEASE_FIXTURE:-}"
    ;;
  */releases/*)
    status="204"
    ;;
  https://api.github.com/assets/artifact)
    if [ -n "${FAKE_REDIRECT_ARTIFACT_URL:-}" ]; then
      status="302"
      redirect_url="${FAKE_REDIRECT_ARTIFACT_URL}"
    else
      body_file="${FAKE_REMOTE_ARTIFACT_FILE:?}"
    fi
    ;;
  https://api.github.com/assets/checksum)
    if [ -n "${FAKE_REDIRECT_CHECKSUM_URL:-}" ]; then
      status="302"
      redirect_url="${FAKE_REDIRECT_CHECKSUM_URL}"
    else
      body_file="${FAKE_REMOTE_CHECKSUM_FILE:?}"
    fi
    ;;
  https://api.github.com/assets/signature)
    if [ -n "${FAKE_REDIRECT_SIGNATURE_URL:-}" ]; then
      status="302"
      redirect_url="${FAKE_REDIRECT_SIGNATURE_URL}"
    else
      body_file="${FAKE_REMOTE_SIGNATURE_FILE:?}"
    fi
    ;;
  https://api.github.com/assets/returned)
    if [ "$auth_header_present" = true ]; then echo 'Credentials restored after origin change.' >&2; exit 1; fi
    body_file="${FAKE_REMOTE_ARTIFACT_FILE:?}"
    ;;
  https://objects.githubusercontent.com/artifact)
    case "${FAKE_REDIRECT_CHAIN:-}" in
      evil) status=302; redirect_url=https://evil.example.invalid/artifact ;;
      http) status=302; redirect_url=http://objects.githubusercontent.com/artifact ;;
      port) status=302; redirect_url=https://objects.githubusercontent.com:444/artifact ;;
      credentials) status=302; redirect_url=https://user:secret@objects.githubusercontent.com/artifact ;;
      loop) status=302; redirect_url=https://objects.githubusercontent.com/artifact ;;
      returnapi) status=302; redirect_url=https://api.github.com/assets/returned ;;
    esac
    body_file="${FAKE_REMOTE_ARTIFACT_FILE:?}"
    ;;
  https://objects.githubusercontent.com/checksum)
    body_file="${FAKE_REMOTE_CHECKSUM_FILE:?}"
    ;;
  https://objects.githubusercontent.com/signature)
    body_file="${FAKE_REMOTE_SIGNATURE_FILE:?}"
    ;;
  https://evil.example.invalid/artifact)
    body_file="${FAKE_REMOTE_ARTIFACT_FILE:?}"
    ;;
  https://evil.example.invalid/checksum)
    body_file="${FAKE_REMOTE_CHECKSUM_FILE:?}"
    ;;
  https://evil.example.invalid/signature)
    body_file="${FAKE_REMOTE_SIGNATURE_FILE:?}"
    ;;
  *)
    echo "Unexpected curl URL: ${url}" >&2
    exit 1
    ;;
esac

if [ "${FAKE_FAIL_ON_REDIRECT_AUTH:-0}" = "1" ] && [ "$auth_header_present" = "true" ]; then
  case "$url" in
    https://objects.githubusercontent.com/*|https://evil.example.invalid/*)
      echo "Authorization header must not be forwarded to redirected asset host: ${url}" >&2
      exit 1
      ;;
  esac
fi

if [ -n "${output}" ]; then
  if [ -n "${body_file}" ]; then
    cp "${body_file}" "${output}"
  else
    : > "${output}"
  fi
else
  if [ -n "${body_file}" ]; then
    cat "${body_file}"
  fi
fi

if [ -n "${write_format}" ]; then
  rendered="${write_format//\%\{http_code\}/${status}}"
  rendered="${rendered//\%\{redirect_url\}/${redirect_url}}"
  printf '%b' "${rendered}"
fi
EOF

chmod +x "${FAKE_BIN}/curl"

cat > "${FAKE_BIN}/git" <<'EOF'
#!/usr/bin/env bash

set -euo pipefail

printf '%s\n' "$*" >> "${FAKE_GIT_LOG:-/dev/null}"

if [ "${1:-}" = "push" ] && [ "${2:-}" = "--delete" ]; then
  exit 0
fi

if [ "${1:-}" = "tag" ] && [ "${2:-}" = "-d" ]; then
  exit 0
fi

if [ "${1:-}" = "ls-remote" ] && [ "${2:-}" = "--exit-code" ] && [ "${3:-}" = "--tags" ] && [ "${4:-}" = "origin" ]; then
  ref="${5:-}"
  state="${FAKE_GIT_LS_REMOTE_STATE:-absent}"

  if [ -n "${FAKE_GIT_LS_REMOTE_SEQUENCE:-}" ]; then
    sequence_index_file="${FAKE_GIT_LS_REMOTE_SEQUENCE_INDEX_FILE:?}"
    sequence_index=0

    if [ -f "${sequence_index_file}" ]; then
      sequence_index="$(cat "${sequence_index_file}")"
    fi

    IFS=':' read -r -a ls_remote_states <<< "${FAKE_GIT_LS_REMOTE_SEQUENCE}"

    if [ "${sequence_index}" -ge "${#ls_remote_states[@]}" ]; then
      sequence_index=$((${#ls_remote_states[@]} - 1))
    fi

    state="${ls_remote_states[$sequence_index]}"
    printf '%s' "$((sequence_index + 1))" > "${sequence_index_file}"
  fi

  case "${state}" in
    present|exists|true|1)
      printf '0000000000000000000000000000000000000000\t%s\n' "${ref}"
      exit 0
      ;;
    absent|missing|false|0)
      exit 2
      ;;
    *)
      echo "Unexpected fake ls-remote state: ${state}" >&2
      exit 1
      ;;
  esac
fi

echo "Unexpected git invocation in release script test: $*" >&2
exit 1
EOF
chmod +x "${FAKE_BIN}/git"

assert_contains() {
  local file="$1"
  local expected="$2"

  if ! grep -Fq "$expected" "$file"; then
    echo "Expected '${expected}' in ${file}." >&2
    exit 1
  fi
}

assert_count() {
  local file="$1"
  local pattern="$2"
  local expected="$3"
  local count

  count="$(grep -F -c "$pattern" "$file" || true)"

  if [ "$count" != "$expected" ]; then
    echo "Expected ${expected} occurrence(s) of '${pattern}' in ${file}, found ${count}." >&2
    exit 1
  fi
}

export PATH="${FAKE_BIN}:${PATH}"
export GITHUB_TOKEN="fixture-token"
export FAKE_CURL_LOG="${CURL_LOG}"
export FAKE_GIT_LOG="${GIT_LOG}"
export FAKE_PULLS_FIXTURE="${FIXTURE_ROOT}/pulls-success.json"
export FAKE_RELEASE_FIXTURE="${FIXTURE_ROOT}/release-current.json"
export FAKE_REMOTE_CHECKSUM_FILE="${REMOTE_DIR}/checksum-current"
export FAKE_REMOTE_SIGNATURE_FILE="${REMOTE_DIR}/signature-current"
unset FAKE_REDIRECT_ARTIFACT_URL
unset FAKE_REDIRECT_CHECKSUM_URL
unset FAKE_REDIRECT_SIGNATURE_URL
unset FAKE_FAIL_ON_REDIRECT_AUTH

CI_SUCCESS_OUTPUT="${TMP_DIR}/ci-success.out"
unset FAKE_RUNS_FIXTURE_SEQUENCE
export FAKE_RUNS_FIXTURE="${FIXTURE_ROOT}/runs-push-success.json"
bash "${REPO_ROOT}/scripts/ci/check_framework_release_ci.sh" example/repo v1.3.2 merge-commit-sha > "${CI_SUCCESS_OUTPUT}"
assert_contains "${CI_SUCCESS_OUTPUT}" "successful wporg-validate-runtime.yml push run on merged commit merge-commit-sha"

PR_SUCCESS_OUTPUT="${TMP_DIR}/pr-success.out"
bash "${REPO_ROOT}/scripts/ci/check_framework_release_pr.sh" example/repo v1.3.2 merge-commit-sha > "$PR_SUCCESS_OUTPUT"
assert_contains "$PR_SUCCESS_OUTPUT" 'Verified release commit merge-commit-sha'
for helper in check_framework_release_ci.sh check_framework_release_pr.sh; do
  for status in 302 500; do
    : > "$CURL_LOG"
    if FAKE_API_READ_STATUS="$status" bash "${SCRIPT_DIR}/${helper}" example/repo v1.3.2 merge-commit-sha > "$TMP_DIR/gate-failure.out" 2>&1; then
      echo "Expected ${helper} to reject API response ${status}." >&2; exit 1
    fi
    assert_contains "$TMP_DIR/gate-failure.out" "Release API read failed (HTTP ${status})"
    assert_count "$CURL_LOG" 'GET ' 1
  done
  : > "$CURL_LOG"
  if GITHUB_API_URL=http://api.github.com bash "${SCRIPT_DIR}/${helper}" example/repo v1.3.2 merge-commit-sha > "$TMP_DIR/gate-http.out" 2>&1; then
    echo "Expected ${helper} to reject an insecure API URL." >&2; exit 1
  fi
  assert_count "$CURL_LOG" 'GET ' 0
done

CI_DELAYED_SUCCESS_OUTPUT="${TMP_DIR}/ci-delayed-success.out"
export FAKE_RUNS_FIXTURE_SEQUENCE="${FIXTURE_ROOT}/runs-push-pending.json:${FIXTURE_ROOT}/runs-push-success.json"
export FAKE_RUNS_SEQUENCE_INDEX_FILE="${TMP_DIR}/runs-sequence-index"
rm -f "${FAKE_RUNS_SEQUENCE_INDEX_FILE}"
RELEASE_CI_MAX_ATTEMPTS=2 RELEASE_CI_POLL_INTERVAL_SECONDS=0 \
  bash "${REPO_ROOT}/scripts/ci/check_framework_release_ci.sh" example/repo v1.3.2 merge-commit-sha > "${CI_DELAYED_SUCCESS_OUTPUT}"
assert_contains "${CI_DELAYED_SUCCESS_OUTPUT}" "successful wporg-validate-runtime.yml push run on merged commit merge-commit-sha"

CI_FAILURE_OUTPUT="${TMP_DIR}/ci-failure.out"
unset FAKE_RUNS_FIXTURE_SEQUENCE
export FAKE_RUNS_FIXTURE="${FIXTURE_ROOT}/runs-no-push-success.json"
if RELEASE_CI_MAX_ATTEMPTS=1 RELEASE_CI_POLL_INTERVAL_SECONDS=0 \
  bash "${REPO_ROOT}/scripts/ci/check_framework_release_ci.sh" example/repo v1.3.2 merge-commit-sha > "${CI_FAILURE_OUTPUT}" 2>&1; then
  echo "Expected release CI helper to reject commits without a successful push run." >&2
  exit 1
fi
assert_contains "${CI_FAILURE_OUTPUT}" "no successful wporg-validate-runtime.yml push run on merged commit merge-commit-sha after 1 check(s)"

ASSET_CURRENT_OUTPUT="${TMP_DIR}/assets-current.out"
ASSET_CURRENT_GITHUB_OUTPUT="${TMP_DIR}/assets-current.github-output"
export FAKE_RELEASE_STATUS="200"
export FAKE_REMOTE_ARTIFACT_FILE="${REMOTE_DIR}/artifact-current"
GITHUB_OUTPUT="${ASSET_CURRENT_GITHUB_OUTPUT}" \
  bash "${REPO_ROOT}/scripts/ci/check_framework_release_assets.sh" \
    --expected-title "${EXPECTED_RELEASE_TITLE}" \
    --expected-notes-file "${EXPECTED_RELEASE_NOTES_PATH}" \
    example/repo \
    v1.3.2 \
    "${ARTIFACT_PATH}" \
    "${CHECKSUM_PATH}" \
    "${SIGNATURE_PATH}" > "${ASSET_CURRENT_OUTPUT}"
assert_contains "${ASSET_CURRENT_OUTPUT}" "already contains the current verified release assets and metadata"
assert_contains "${ASSET_CURRENT_GITHUB_OUTPUT}" "publish_required=false"
assert_contains "${ASSET_CURRENT_GITHUB_OUTPUT}" "reason=current"

ASSET_REDIRECT_OUTPUT="${TMP_DIR}/assets-redirect.out"
ASSET_REDIRECT_GITHUB_OUTPUT="${TMP_DIR}/assets-redirect.github-output"
export FAKE_REDIRECT_ARTIFACT_URL="https://objects.githubusercontent.com/artifact"
export FAKE_REDIRECT_CHECKSUM_URL="https://objects.githubusercontent.com/checksum"
export FAKE_REDIRECT_SIGNATURE_URL="https://objects.githubusercontent.com/signature"
export FAKE_FAIL_ON_REDIRECT_AUTH="1"
GITHUB_OUTPUT="${ASSET_REDIRECT_GITHUB_OUTPUT}" \
  bash "${REPO_ROOT}/scripts/ci/check_framework_release_assets.sh" \
    --expected-title "${EXPECTED_RELEASE_TITLE}" \
    --expected-notes-file "${EXPECTED_RELEASE_NOTES_PATH}" \
    example/repo \
    v1.3.2 \
    "${ARTIFACT_PATH}" \
    "${CHECKSUM_PATH}" \
    "${SIGNATURE_PATH}" > "${ASSET_REDIRECT_OUTPUT}"
assert_contains "${ASSET_REDIRECT_OUTPUT}" "already contains the current verified release assets and metadata"
assert_contains "${ASSET_REDIRECT_GITHUB_OUTPUT}" "publish_required=false"

ASSET_BAD_REDIRECT_OUTPUT="${TMP_DIR}/assets-bad-redirect.out"
export FAKE_REDIRECT_ARTIFACT_URL="https://evil.example.invalid/artifact"
export FAKE_REDIRECT_CHECKSUM_URL="https://evil.example.invalid/checksum"
export FAKE_REDIRECT_SIGNATURE_URL="https://evil.example.invalid/signature"
if GITHUB_OUTPUT="${TMP_DIR}/assets-bad-redirect.github-output" \
  bash "${REPO_ROOT}/scripts/ci/check_framework_release_assets.sh" \
    --expected-title "${EXPECTED_RELEASE_TITLE}" \
    --expected-notes-file "${EXPECTED_RELEASE_NOTES_PATH}" \
    example/repo \
    v1.3.2 \
    "${ARTIFACT_PATH}" \
    "${CHECKSUM_PATH}" \
    "${SIGNATURE_PATH}" > "${ASSET_BAD_REDIRECT_OUTPUT}" 2>&1; then
  echo "Expected release asset helper to reject non-allowlisted redirect hosts." >&2
  exit 1
fi
assert_contains "${ASSET_BAD_REDIRECT_OUTPUT}" "Release asset redirect host is not allowlisted"

ASSET_STALE_OUTPUT="${TMP_DIR}/assets-stale.out"
ASSET_STALE_GITHUB_OUTPUT="${TMP_DIR}/assets-stale.github-output"
unset FAKE_REDIRECT_ARTIFACT_URL
unset FAKE_REDIRECT_CHECKSUM_URL
unset FAKE_REDIRECT_SIGNATURE_URL
export FAKE_REMOTE_ARTIFACT_FILE="${REMOTE_DIR}/artifact-stale"
GITHUB_OUTPUT="${ASSET_STALE_GITHUB_OUTPUT}" \
  bash "${REPO_ROOT}/scripts/ci/check_framework_release_assets.sh" \
    --expected-title "${EXPECTED_RELEASE_TITLE}" \
    --expected-notes-file "${EXPECTED_RELEASE_NOTES_PATH}" \
    example/repo \
    v1.3.2 \
    "${ARTIFACT_PATH}" \
    "${CHECKSUM_PATH}" \
    "${SIGNATURE_PATH}" > "${ASSET_STALE_OUTPUT}"
assert_contains "${ASSET_STALE_OUTPUT}" "does not match the current built snapshot"
assert_contains "${ASSET_STALE_GITHUB_OUTPUT}" "publish_required=true"
assert_contains "${ASSET_STALE_GITHUB_OUTPUT}" "reason=artifact-mismatch"

ASSET_REQUIRE_CURRENT_OUTPUT="${TMP_DIR}/assets-require-current.out"
if GITHUB_OUTPUT="${TMP_DIR}/assets-require-current.github-output" \
  bash "${REPO_ROOT}/scripts/ci/check_framework_release_assets.sh" \
    --require-current \
    --expected-title "${EXPECTED_RELEASE_TITLE}" \
    --expected-notes-file "${EXPECTED_RELEASE_NOTES_PATH}" \
    example/repo \
    v1.3.2 \
    "${ARTIFACT_PATH}" \
    "${CHECKSUM_PATH}" \
    "${SIGNATURE_PATH}" > "${ASSET_REQUIRE_CURRENT_OUTPUT}" 2>&1; then
  echo "Expected --require-current to fail when release assets are stale." >&2
  exit 1
fi
assert_contains "${ASSET_REQUIRE_CURRENT_OUTPUT}" "does not match the current built snapshot"

ASSET_MISSING_OUTPUT="${TMP_DIR}/assets-missing.out"
ASSET_MISSING_GITHUB_OUTPUT="${TMP_DIR}/assets-missing.github-output"
export FAKE_RELEASE_STATUS="404"
GITHUB_OUTPUT="${ASSET_MISSING_GITHUB_OUTPUT}" \
  bash "${REPO_ROOT}/scripts/ci/check_framework_release_assets.sh" \
    --expected-title "${EXPECTED_RELEASE_TITLE}" \
    --expected-notes-file "${EXPECTED_RELEASE_NOTES_PATH}" \
    example/repo \
    v1.3.2 \
    "${ARTIFACT_PATH}" \
    "${CHECKSUM_PATH}" \
    "${SIGNATURE_PATH}" > "${ASSET_MISSING_OUTPUT}"
assert_contains "${ASSET_MISSING_OUTPUT}" "does not exist yet"
assert_contains "${ASSET_MISSING_GITHUB_OUTPUT}" "publish_required=true"
assert_contains "${ASSET_MISSING_GITHUB_OUTPUT}" "reason=release-missing"

ASSET_STALE_TITLE_FIXTURE="${TMP_DIR}/release-stale-title.json"
cat > "${ASSET_STALE_TITLE_FIXTURE}" <<'JSON'
{
  "name": "wp-core-base v0.0.0",
  "body": "Fixture release notes.",
  "assets": [
    { "name": "wp-core-base-vendor-snapshot.zip", "url": "https://api.github.com/assets/artifact" },
    { "name": "wp-core-base-vendor-snapshot.zip.sha256", "url": "https://api.github.com/assets/checksum" },
    { "name": "wp-core-base-vendor-snapshot.zip.sha256.sig", "url": "https://api.github.com/assets/signature" }
  ]
}
JSON

ASSET_STALE_TITLE_OUTPUT="${TMP_DIR}/assets-stale-title.out"
ASSET_STALE_TITLE_GITHUB_OUTPUT="${TMP_DIR}/assets-stale-title.github-output"
export FAKE_RELEASE_STATUS="200"
export FAKE_RELEASE_FIXTURE="${ASSET_STALE_TITLE_FIXTURE}"
export FAKE_REMOTE_ARTIFACT_FILE="${REMOTE_DIR}/artifact-current"
GITHUB_OUTPUT="${ASSET_STALE_TITLE_GITHUB_OUTPUT}" \
  bash "${REPO_ROOT}/scripts/ci/check_framework_release_assets.sh" \
    --expected-title "${EXPECTED_RELEASE_TITLE}" \
    --expected-notes-file "${EXPECTED_RELEASE_NOTES_PATH}" \
    example/repo \
    v1.3.2 \
    "${ARTIFACT_PATH}" \
    "${CHECKSUM_PATH}" \
    "${SIGNATURE_PATH}" > "${ASSET_STALE_TITLE_OUTPUT}"
assert_contains "${ASSET_STALE_TITLE_OUTPUT}" "title does not match expected metadata"
assert_contains "${ASSET_STALE_TITLE_GITHUB_OUTPUT}" "publish_required=true"
assert_contains "${ASSET_STALE_TITLE_GITHUB_OUTPUT}" "reason=title-mismatch"

ASSET_STALE_NOTES_FIXTURE="${TMP_DIR}/release-stale-notes.json"
cat > "${ASSET_STALE_NOTES_FIXTURE}" <<'JSON'
{
  "name": "wp-core-base v1.3.2",
  "body": "Outdated release notes.",
  "assets": [
    { "name": "wp-core-base-vendor-snapshot.zip", "url": "https://api.github.com/assets/artifact" },
    { "name": "wp-core-base-vendor-snapshot.zip.sha256", "url": "https://api.github.com/assets/checksum" },
    { "name": "wp-core-base-vendor-snapshot.zip.sha256.sig", "url": "https://api.github.com/assets/signature" }
  ]
}
JSON

ASSET_STALE_NOTES_OUTPUT="${TMP_DIR}/assets-stale-notes.out"
ASSET_STALE_NOTES_GITHUB_OUTPUT="${TMP_DIR}/assets-stale-notes.github-output"
export FAKE_RELEASE_FIXTURE="${ASSET_STALE_NOTES_FIXTURE}"
GITHUB_OUTPUT="${ASSET_STALE_NOTES_GITHUB_OUTPUT}" \
  bash "${REPO_ROOT}/scripts/ci/check_framework_release_assets.sh" \
    --expected-title "${EXPECTED_RELEASE_TITLE}" \
    --expected-notes-file "${EXPECTED_RELEASE_NOTES_PATH}" \
    example/repo \
    v1.3.2 \
    "${ARTIFACT_PATH}" \
    "${CHECKSUM_PATH}" \
    "${SIGNATURE_PATH}" > "${ASSET_STALE_NOTES_OUTPUT}"
assert_contains "${ASSET_STALE_NOTES_OUTPUT}" "notes body does not match expected metadata"
assert_contains "${ASSET_STALE_NOTES_GITHUB_OUTPUT}" "publish_required=true"
assert_contains "${ASSET_STALE_NOTES_GITHUB_OUTPUT}" "reason=notes-mismatch"

# Every redirect hop is checked, including a CDN redirect back to the API.
export FAKE_RELEASE_FIXTURE="${FIXTURE_ROOT}/release-current.json"
export FAKE_REMOTE_ARTIFACT_FILE="${REMOTE_DIR}/artifact-current"
export FAKE_REDIRECT_ARTIFACT_URL="https://objects.githubusercontent.com/artifact"
export FAKE_REDIRECT_CHECKSUM_URL="https://objects.githubusercontent.com/checksum"
export FAKE_REDIRECT_SIGNATURE_URL="https://objects.githubusercontent.com/signature"
for chain in evil http port credentials loop; do
  export FAKE_REDIRECT_CHAIN="$chain"
  : > "$CURL_LOG"
  if bash "${REPO_ROOT}/scripts/ci/check_framework_release_assets.sh" example/repo v1.3.2 "$ARTIFACT_PATH" "$CHECKSUM_PATH" "$SIGNATURE_PATH" > "$TMP_DIR/chain-$chain.out" 2>&1; then
    echo "Expected redirect chain $chain to be rejected." >&2; exit 1
  fi
  if grep -Eq 'GET (http:|https://evil|https://user|https://objects.githubusercontent.com:444)' "$CURL_LOG"; then
    echo 'Unsafe redirect was requested.' >&2; exit 1
  fi
done
export FAKE_REDIRECT_CHAIN=returnapi
bash "${REPO_ROOT}/scripts/ci/check_framework_release_assets.sh" example/repo v1.3.2 "$ARTIFACT_PATH" "$CHECKSUM_PATH" "$SIGNATURE_PATH" > "$TMP_DIR/chain-return-api.out"
unset FAKE_REDIRECT_CHAIN

# An API-supplied initial asset URL cannot select another authenticated origin.
jq '.assets[0].url="https://evil.example.invalid/artifact"' "$FIXTURE_ROOT/release-current.json" > "$TMP_DIR/initial-evil.json"
export FAKE_RELEASE_FIXTURE="$TMP_DIR/initial-evil.json"
: > "$CURL_LOG"
if bash "${REPO_ROOT}/scripts/ci/check_framework_release_assets.sh" example/repo v1.3.2 "$ARTIFACT_PATH" "$CHECKSUM_PATH" "$SIGNATURE_PATH" > "$TMP_DIR/initial-evil.out" 2>&1; then
  echo 'Expected initial asset origin rejection.' >&2; exit 1
fi
if grep -Fq 'GET https://evil' "$CURL_LOG"; then echo 'Initial evil URL was requested.' >&2; exit 1; fi

PATH="${PATH#*:}" bash "${REPO_ROOT}/scripts/ci/test_release_publication.sh"
echo "Release helper scripts verified."
