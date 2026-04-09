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

ARTIFACT_PATH="${LOCAL_DIR}/wp-core-base-vendor-snapshot.zip"
CHECKSUM_PATH="${LOCAL_DIR}/wp-core-base-vendor-snapshot.zip.sha256"
SIGNATURE_PATH="${LOCAL_DIR}/wp-core-base-vendor-snapshot.zip.sha256.sig"

printf 'fixture artifact\n' > "${ARTIFACT_PATH}"
printf '%s  %s\n' "$(shasum -a 256 "${ARTIFACT_PATH}" | awk '{print $1}')" "$(basename "${ARTIFACT_PATH}")" > "${CHECKSUM_PATH}"
printf 'fixture signature\n' > "${SIGNATURE_PATH}"

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
      shift 2
      ;;
    --proto)
      shift 2
      ;;
    -fsSL|-sS|-f|-s|-S|-L)
      shift
      ;;
    *)
      url="$1"
      shift
      ;;
  esac
done

status="200"
body_file=""

case "$url" in
  */commits/*/pulls)
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

      body_file="${run_fixtures[${sequence_index}]}"
      printf '%s' "$((sequence_index + 1))" > "${sequence_index_file}"
    else
      body_file="${FAKE_RUNS_FIXTURE:?}"
    fi
    ;;
  */releases/tags/*)
    status="${FAKE_RELEASE_STATUS:-200}"
    body_file="${FAKE_RELEASE_FIXTURE:-}"
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
  https://objects.githubusercontent.com/artifact)
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

assert_contains() {
  local file="$1"
  local expected="$2"

  if ! grep -Fq "$expected" "$file"; then
    echo "Expected '${expected}' in ${file}." >&2
    exit 1
  fi
}

export PATH="${FAKE_BIN}:${PATH}"
export GITHUB_TOKEN="fixture-token"
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
    example/repo \
    v1.3.2 \
    "${ARTIFACT_PATH}" \
    "${CHECKSUM_PATH}" \
    "${SIGNATURE_PATH}" > "${ASSET_CURRENT_OUTPUT}"
assert_contains "${ASSET_CURRENT_OUTPUT}" "already contains the current verified release assets"
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
    example/repo \
    v1.3.2 \
    "${ARTIFACT_PATH}" \
    "${CHECKSUM_PATH}" \
    "${SIGNATURE_PATH}" > "${ASSET_REDIRECT_OUTPUT}"
assert_contains "${ASSET_REDIRECT_OUTPUT}" "already contains the current verified release assets"
assert_contains "${ASSET_REDIRECT_GITHUB_OUTPUT}" "publish_required=false"

ASSET_BAD_REDIRECT_OUTPUT="${TMP_DIR}/assets-bad-redirect.out"
export FAKE_REDIRECT_ARTIFACT_URL="https://evil.example.invalid/artifact"
export FAKE_REDIRECT_CHECKSUM_URL="https://evil.example.invalid/checksum"
export FAKE_REDIRECT_SIGNATURE_URL="https://evil.example.invalid/signature"
if GITHUB_OUTPUT="${TMP_DIR}/assets-bad-redirect.github-output" \
  bash "${REPO_ROOT}/scripts/ci/check_framework_release_assets.sh" \
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
    example/repo \
    v1.3.2 \
    "${ARTIFACT_PATH}" \
    "${CHECKSUM_PATH}" \
    "${SIGNATURE_PATH}" > "${ASSET_MISSING_OUTPUT}"
assert_contains "${ASSET_MISSING_OUTPUT}" "does not exist yet"
assert_contains "${ASSET_MISSING_GITHUB_OUTPUT}" "publish_required=true"
assert_contains "${ASSET_MISSING_GITHUB_OUTPUT}" "reason=release-missing"

echo "Release helper scripts verified."
