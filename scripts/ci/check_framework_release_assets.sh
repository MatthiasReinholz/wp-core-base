#!/usr/bin/env bash

set -euo pipefail

REQUIRE_CURRENT='false'
EXPECTED_TITLE=''
EXPECTED_NOTES_FILE=''

while [ "$#" -gt 0 ]; do
  case "${1}" in
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

api_request() {
  curl -sS \
    -o "$2" \
    -w "%{http_code}" \
    -H "Accept: application/vnd.github+json" \
    -H "Authorization: Bearer ${GITHUB_TOKEN}" \
    -H "X-GitHub-Api-Version: 2022-11-28" \
    "$1"
}

url_scheme() {
  # shellcheck disable=SC2016
  php -r '$scheme = parse_url($argv[1], PHP_URL_SCHEME); echo is_string($scheme) ? $scheme : "";' "$1"
}

url_host() {
  # shellcheck disable=SC2016
  php -r '$host = parse_url($argv[1], PHP_URL_HOST); echo is_string($host) ? $host : "";' "$1"
}

allowed_asset_redirect_host() {
  case "$1" in
    api.github.com|uploads.github.com|github.com|objects.githubusercontent.com|objects-origin.githubusercontent.com|release-assets.githubusercontent.com|github-releases.githubusercontent.com)
      return 0
      ;;
    *.githubusercontent.com)
      return 0
      ;;
  esac

  return 1
}

assert_allowed_asset_redirect_url() {
  local redirect_url="$1"
  local redirect_scheme
  local redirect_host

  redirect_scheme="$(url_scheme "$redirect_url")"
  redirect_host="$(url_host "$redirect_url")"

  if [ "$redirect_scheme" != 'https' ]; then
    echo "Release asset redirect must use https: ${redirect_url}" >&2
    exit 1
  fi

  if [ -z "$redirect_host" ] || ! allowed_asset_redirect_host "$redirect_host"; then
    echo "Release asset redirect host is not allowlisted: ${redirect_url}" >&2
    exit 1
  fi
}

download_asset() {
  local asset_api_url="$1"
  local destination="$2"
  local probe_output
  local status_code
  local redirect_url

  probe_output="$(
    curl -sS \
      -o "$destination" \
      -w "%{http_code}\n%{redirect_url}" \
      -H "Accept: application/octet-stream" \
      -H "Authorization: Bearer ${GITHUB_TOKEN}" \
      -H "X-GitHub-Api-Version: 2022-11-28" \
      "$asset_api_url"
  )"

  status_code="$(printf '%s' "$probe_output" | sed -n '1p')"
  redirect_url="$(printf '%s' "$probe_output" | sed -n '2p')"

  case "$status_code" in
    200)
      return
      ;;
    302|303|307|308)
      rm -f "$destination"

      if [ -z "$redirect_url" ]; then
        echo "Release asset download redirect did not provide a destination URL: ${asset_api_url}" >&2
        exit 1
      fi

      assert_allowed_asset_redirect_url "$redirect_url"
      curl --proto '=https' -fsSL "$redirect_url" -o "$destination"
      return
      ;;
  esac

  rm -f "$destination"
  echo "Failed to download release asset via ${asset_api_url} (status ${status_code})." >&2
  exit 1
}

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
