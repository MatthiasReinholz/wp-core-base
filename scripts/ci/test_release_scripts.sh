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
    -H|-X)
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
    body_file="${FAKE_RUNS_FIXTURE:?}"
    ;;
  */releases/tags/*)
    status="${FAKE_RELEASE_STATUS:-200}"
    body_file="${FAKE_RELEASE_FIXTURE:-}"
    ;;
  https://api.github.com/assets/artifact)
    body_file="${FAKE_REMOTE_ARTIFACT_FILE:?}"
    ;;
  https://api.github.com/assets/checksum)
    body_file="${FAKE_REMOTE_CHECKSUM_FILE:?}"
    ;;
  https://api.github.com/assets/signature)
    body_file="${FAKE_REMOTE_SIGNATURE_FILE:?}"
    ;;
  *)
    echo "Unexpected curl URL: ${url}" >&2
    exit 1
    ;;
esac

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
  printf '%s' "${status}"
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

CI_SUCCESS_OUTPUT="${TMP_DIR}/ci-success.out"
export FAKE_RUNS_FIXTURE="${FIXTURE_ROOT}/runs-push-success.json"
bash "${REPO_ROOT}/scripts/ci/check_framework_release_ci.sh" example/repo v1.3.2 merge-commit-sha > "${CI_SUCCESS_OUTPUT}"
assert_contains "${CI_SUCCESS_OUTPUT}" "successful wporg-validate-runtime.yml push run on merged commit merge-commit-sha"

CI_FAILURE_OUTPUT="${TMP_DIR}/ci-failure.out"
export FAKE_RUNS_FIXTURE="${FIXTURE_ROOT}/runs-no-push-success.json"
if bash "${REPO_ROOT}/scripts/ci/check_framework_release_ci.sh" example/repo v1.3.2 merge-commit-sha > "${CI_FAILURE_OUTPUT}" 2>&1; then
  echo "Expected release CI helper to reject commits without a successful push run." >&2
  exit 1
fi
assert_contains "${CI_FAILURE_OUTPUT}" "no successful wporg-validate-runtime.yml push run on merged commit merge-commit-sha"

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

ASSET_STALE_OUTPUT="${TMP_DIR}/assets-stale.out"
ASSET_STALE_GITHUB_OUTPUT="${TMP_DIR}/assets-stale.github-output"
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
