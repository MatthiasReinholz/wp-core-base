#!/usr/bin/env bash

set -euo pipefail

REQUIRE_CURRENT='false'
EXPECTED_TITLE=''
EXPECTED_NOTES_FILE=''
RELEASE_ID=''
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/ci/release_http.sh
source "${SCRIPT_DIR}/release_http.sh"

while [ "$#" -gt 0 ]; do
  case "${1}" in
    --release-id)
      RELEASE_ID="${2:-}"
      shift 2
      ;;
    --require-current)
      REQUIRE_CURRENT='true'
      shift
      ;;
    --expected-title)
      EXPECTED_TITLE="${2:-}"
      shift 2
      ;;
    --expected-title=*)
      EXPECTED_TITLE="${1#*=}"
      shift
      ;;
    --expected-notes-file)
      EXPECTED_NOTES_FILE="${2:-}"
      shift 2
      ;;
    --expected-notes-file=*)
      EXPECTED_NOTES_FILE="${1#*=}"
      shift
      ;;
    --*)
      echo "Unknown option: ${1}" >&2
      exit 1
      ;;
    *)
      break
      ;;
  esac
done

REPOSITORY="${1:-}"
TAG="${2:-}"
ARTIFACT_PATH="${3:-}"
CHECKSUM_PATH="${4:-}"
SIGNATURE_PATH="${5:-}"
API_ROOT="${GITHUB_API_URL:-https://api.github.com}"

if [ -z "$REPOSITORY" ] || [ -z "$TAG" ] || [ -z "$ARTIFACT_PATH" ] || [ -z "$CHECKSUM_PATH" ] || [ -z "$SIGNATURE_PATH" ]; then
  echo "Usage: $0 [--require-current] [--expected-title title] [--expected-notes-file path] owner/repo vX.Y.Z artifact checksum signature" >&2
  exit 1
fi

if [ -z "${GITHUB_TOKEN:-}" ]; then
  echo "GITHUB_TOKEN is required." >&2
  exit 1
fi

if ! command -v jq >/dev/null 2>&1; then
  echo "jq is required." >&2
  exit 1
fi

for path in "$ARTIFACT_PATH" "$CHECKSUM_PATH" "$SIGNATURE_PATH"; do
  if [ ! -f "$path" ]; then
    echo "Required local file not found: $path" >&2
    exit 1
  fi
done

if [ -n "$EXPECTED_NOTES_FILE" ] && [ ! -f "$EXPECTED_NOTES_FILE" ]; then
  echo "Expected notes file not found: $EXPECTED_NOTES_FILE" >&2
  exit 1
fi

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

write_output() {
  local key="$1"
  local value="$2"

  if [ -n "${GITHUB_OUTPUT:-}" ]; then
    printf '%s=%s\n' "$key" "$value" >> "$GITHUB_OUTPUT"
  fi
}

api_request() { release_api_request GET "$1" "$2"; }
download_asset() { release_download_asset "$1" "$2"; }

mark_state() {
  local exists="$1"
  local publish_required="$2"
  local reason="$3"
  local message="$4"

  write_output "exists" "$exists"
  write_output "publish_required" "$publish_required"
  write_output "reason" "$reason"

  if [ "$REQUIRE_CURRENT" = 'true' ] && [ "$publish_required" = 'true' ]; then
    echo "$message" >&2
    exit 1
  fi

  echo "$message"
}

normalize_text_file() {
  # shellcheck disable=SC2016
  php -r '
    $contents = file_get_contents($argv[1]);
    if (! is_string($contents)) {
      fwrite(STDERR, "Failed to read text file: " . $argv[1] . PHP_EOL);
      exit(1);
    }
    $contents = str_replace(["\r\n", "\r"], "\n", $contents);
    $contents = rtrim($contents, "\n");
    fwrite(STDOUT, $contents);
  ' "$1"
}

release_json="$tmp_dir/release.json"
release_url="${API_ROOT}/repos/${REPOSITORY}/releases/tags/${TAG}"
if [ -n "$RELEASE_ID" ]; then
  [[ "$RELEASE_ID" =~ ^[1-9][0-9]*$ ]] || { echo 'Invalid release ID.' >&2; exit 1; }
  release_url="${API_ROOT}/repos/${REPOSITORY}/releases/${RELEASE_ID}"
fi
[[ "$REPOSITORY" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || { echo 'Invalid repository.' >&2; exit 1; }
[[ "$TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo 'Invalid release tag.' >&2; exit 1; }
status_code="$(api_request "$release_url" "$release_json")"

case "$status_code" in
  200)
    ;;
  404)
    mark_state 'false' 'true' 'release-missing' "GitHub Release ${TAG} does not exist yet."
    exit 0
    ;;
  *)
    cat "$release_json" >&2
    echo "Failed to inspect GitHub Release ${REPOSITORY}@${TAG}." >&2
    exit 1
    ;;
esac

artifact_name="$(basename "$ARTIFACT_PATH")"
checksum_name="$(basename "$CHECKSUM_PATH")"
signature_name="$(basename "$SIGNATURE_PATH")"

asset_api_url() {
  local asset_name="$1"

  jq -r --arg name "$asset_name" '
    [.assets[]? | select(.name == $name) | .url][0] // ""
  ' "$release_json"
}

artifact_asset_url="$(asset_api_url "$artifact_name")"
checksum_asset_url="$(asset_api_url "$checksum_name")"
signature_asset_url="$(asset_api_url "$signature_name")"

missing_assets=()

if [ -z "$artifact_asset_url" ]; then
  missing_assets+=("$artifact_name")
fi

if [ -z "$checksum_asset_url" ]; then
  missing_assets+=("$checksum_name")
fi

if [ -z "$signature_asset_url" ]; then
  missing_assets+=("$signature_name")
fi

if [ "${#missing_assets[@]}" -gt 0 ]; then
  mark_state 'true' 'true' 'missing-assets' "GitHub Release ${TAG} is missing required assets: ${missing_assets[*]}."
  exit 0
fi

remote_artifact="$tmp_dir/$artifact_name"
remote_checksum="$tmp_dir/$checksum_name"
remote_signature="$tmp_dir/$signature_name"

download_asset "$artifact_asset_url" "$remote_artifact"
download_asset "$checksum_asset_url" "$remote_checksum"
download_asset "$signature_asset_url" "$remote_signature"

sha256() {
  shasum -a 256 "$1" | awk '{print $1}'
}

local_artifact_sha="$(sha256 "$ARTIFACT_PATH")"
remote_artifact_sha="$(sha256 "$remote_artifact")"

if [ "$local_artifact_sha" != "$remote_artifact_sha" ]; then
  mark_state 'true' 'true' 'artifact-mismatch' "GitHub Release ${TAG} artifact ${artifact_name} does not match the current built snapshot."
  exit 0
fi

if ! cmp -s "$CHECKSUM_PATH" "$remote_checksum"; then
  mark_state 'true' 'true' 'checksum-mismatch' "GitHub Release ${TAG} checksum asset ${checksum_name} does not match the current built snapshot."
  exit 0
fi

if ! cmp -s "$SIGNATURE_PATH" "$remote_signature"; then
  mark_state 'true' 'true' 'signature-mismatch' "GitHub Release ${TAG} signature asset ${signature_name} does not match the current built snapshot."
  exit 0
fi

release_title="$(jq -r '.name // ""' "$release_json")"

if [ -n "$EXPECTED_TITLE" ] && [ "$release_title" != "$EXPECTED_TITLE" ]; then
  mark_state 'true' 'true' 'title-mismatch' "GitHub Release ${TAG} title does not match expected metadata."
  exit 0
fi

if [ -n "$EXPECTED_NOTES_FILE" ]; then
  release_notes_file="$tmp_dir/release-notes.txt"
  expected_notes_normalized="$tmp_dir/expected-notes-normalized.txt"
  release_notes_normalized="$tmp_dir/release-notes-normalized.txt"
  jq -r '.body // ""' "$release_json" > "$release_notes_file"
  normalize_text_file "$EXPECTED_NOTES_FILE" > "$expected_notes_normalized"
  normalize_text_file "$release_notes_file" > "$release_notes_normalized"

  if ! cmp -s "$expected_notes_normalized" "$release_notes_normalized"; then
    mark_state 'true' 'true' 'notes-mismatch' "GitHub Release ${TAG} notes body does not match expected metadata."
    exit 0
  fi
fi

mark_state 'true' 'false' 'current' "GitHub Release ${TAG} already contains the current verified release assets and metadata."
