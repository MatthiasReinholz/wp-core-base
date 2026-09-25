#!/usr/bin/env bash
# Publish only resources created with positive receipts. Ambiguous requests preserve recovery state.
set -euo pipefail
umask 077
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/ci/release_http.sh
source "${SCRIPT_DIR}/release_http.sh"
REPOSITORY="${1:-}" TAG="${2:-}" COMMIT="${3:-}" NOTES="${4:-}" ARTIFACT="${5:-}" CHECKSUM="${6:-}" SIGNATURE="${7:-}" JOURNAL="${8:-}"
[[ "$REPOSITORY" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || { echo 'Invalid repository.' >&2; exit 1; }
[[ "$TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo 'Invalid release tag.' >&2; exit 1; }
[[ "$COMMIT" =~ ^[a-f0-9]{40}$ ]] || { echo 'Invalid source commit.' >&2; exit 1; }
[ -n "$JOURNAL" ] && [ ! -e "$JOURNAL" ] || { echo 'A new publication journal path is required.' >&2; exit 1; }
for file in "$NOTES" "$ARTIFACT" "$CHECKSUM" "$SIGNATURE"; do [ -f "$file" ] || { echo "Missing publication file: $file" >&2; exit 1; }; done
API_ROOT="${GITHUB_API_URL:-https://api.github.com}"
release_assert_api_url "$API_ROOT" || exit 1
TMP_DIR="$(mktemp -d)"
CREATED_TAG='' CREATED_RELEASE='' AMBIGUOUS=false PUBLISHED=false
mkdir -p "$(dirname "$JOURNAL")"
write_journal() {
  jq -n --arg repository "$REPOSITORY" --arg tag "$TAG" --arg commit "$COMMIT" --arg tag_object "$CREATED_TAG" --arg release_id "$CREATED_RELEASE" --argjson ambiguous "$AMBIGUOUS" --argjson published "$PUBLISHED" \
    '{repository:$repository,tag:$tag,commit:$commit,created_tag_object:$tag_object,created_release_id:$release_id,ambiguous:$ambiguous,published:$published}' > "${JOURNAL}.tmp"
  mv "${JOURNAL}.tmp" "$JOURNAL"
}
write_journal

on_exit() {
  local result="$?"
  trap - EXIT
  if [ "$result" -ne 0 ]; then
    # A receipt proves creation, not exclusive ownership of its current state.
    # GitHub has no conditional release deletion: another actor can publish a
    # draft between inspection and deletion, or attach a release to our tag.
    # Preserve both resources for explicit operator recovery on every failure.
    echo "Publication failed; resource receipts and recovery state: ${JOURNAL}" >&2
  fi
  # Hosted runners are disposable; retain non-secret receipts in durable job logs.
  if [ -f "$JOURNAL" ]; then cat "$JOURNAL"; fi
  rm -rf "$TMP_DIR"
  exit "$result"
}
trap on_exit EXIT

remote_tag="$(git ls-remote origin "refs/tags/${TAG}" "refs/tags/${TAG}^{}")"
if [ -n "$remote_tag" ]; then
  remote_commit="$(printf '%s\n' "$remote_tag" | awk -v ref="refs/tags/${TAG}^{}" '$2 == ref { print $1 }')"
  if [ -z "$remote_commit" ]; then remote_commit="$(printf '%s\n' "$remote_tag" | awk -v ref="refs/tags/${TAG}" '$2 == ref { print $1 }')"; fi
  [ "$remote_commit" = "$COMMIT" ] || { echo 'Existing tag does not identify the verified source commit.' >&2; exit 1; }
fi

status="$(release_api_request GET "${API_ROOT}/repos/${REPOSITORY}/releases/tags/${TAG}" "${TMP_DIR}/existing.json")"
if [ "$status" = 200 ]; then
  [ -n "$remote_tag" ] || { echo 'Existing release has no source tag; explicit operator recovery is required.' >&2; exit 1; }
  # Pre-existing draft or published release is immutable to this run.
  jq -e '.draft == false' "${TMP_DIR}/existing.json" >/dev/null || { echo 'A pre-existing draft requires explicit operator recovery.' >&2; exit 1; }
  bash "${SCRIPT_DIR}/check_framework_release_assets.sh" --require-current --expected-title "wp-core-base ${TAG}" --expected-notes-file "$NOTES" "$REPOSITORY" "$TAG" "$ARTIFACT" "$CHECKSUM" "$SIGNATURE"
  echo "GitHub Release ${TAG} already contains the current verified assets and metadata; nothing to publish."
  exit 0
fi
[ "$status" = 404 ] || { echo "Release lookup failed (HTTP ${status}); existing state preserved." >&2; exit 1; }

if [ -z "$remote_tag" ]; then
  git -c tag.gpgsign=false tag -a "$TAG" "$COMMIT" -m "wp-core-base ${TAG}"
  tag_object="$(git rev-parse "refs/tags/${TAG}")"
  AMBIGUOUS=true; write_journal
  if ! git push "--force-with-lease=refs/tags/${TAG}:" origin "refs/tags/${TAG}:refs/tags/${TAG}"; then
    echo 'Tag push outcome is uncertain; preserving all remote state.' >&2; exit 1
  fi
  CREATED_TAG="$tag_object"; AMBIGUOUS=false; write_journal
fi

jq -n --arg tag "$TAG" --arg commit "$COMMIT" --arg name "wp-core-base ${TAG}" --rawfile body "$NOTES" '{tag_name:$tag,target_commitish:$commit,name:$name,body:$body,draft:true,prerelease:false}' > "${TMP_DIR}/create.json"
AMBIGUOUS=true; write_journal
status="$(release_api_request POST "${API_ROOT}/repos/${REPOSITORY}/releases" "${TMP_DIR}/created.json" "${TMP_DIR}/create.json")"
if [ "$status" != 201 ]; then
  if [[ "$status" =~ ^4[0-9][0-9]$ ]]; then AMBIGUOUS=false; write_journal; fi
  echo "Draft creation failed (HTTP ${status})." >&2; exit 1
fi
CREATED_RELEASE="$(jq -er --arg tag "$TAG" 'select(.draft == true and .tag_name == $tag) | .id | select(type == "number" and . > 0 and floor == .)' "${TMP_DIR}/created.json")"
[[ "$CREATED_RELEASE" =~ ^[1-9][0-9]*$ ]] || exit 1
AMBIGUOUS=false; write_journal
upload_url="$(jq -er '.upload_url | split("{")[0]' "${TMP_DIR}/created.json")"
upload_origin="$(release_url_origin "$upload_url")"
if [ "$upload_origin" != "$(release_api_origin)" ] && { [ "$(release_api_origin)" != 'https://api.github.com:443' ] || [ "$upload_origin" != 'https://uploads.github.com:443' ]; }; then
  echo 'Release upload URL has an unexpected origin.' >&2; exit 1
fi
for file in "$ARTIFACT" "$CHECKSUM" "$SIGNATURE"; do
  asset_name="$(basename "$file")"
  [[ "$asset_name" =~ ^[A-Za-z0-9_.-]+$ ]] || { echo 'Unsafe release asset name.' >&2; exit 1; }
  AMBIGUOUS=true; write_journal
  status="$(curl --proto '=https' --tlsv1.2 --connect-timeout 15 --max-time 300 --max-filesize 5242880 -sS -X POST -o "${TMP_DIR}/upload.json" -w '%{http_code}' -H "Authorization: Bearer ${GITHUB_TOKEN}" -H 'Content-Type: application/octet-stream' --data-binary "@$file" "${upload_url}?name=${asset_name}")"
  if [ "$status" != 201 ]; then
    if [[ "$status" =~ ^4[0-9][0-9]$ ]]; then AMBIGUOUS=false; write_journal; fi
    echo "Asset upload failed (HTTP ${status})." >&2; exit 1
  fi
  AMBIGUOUS=false; write_journal
done
bash "${SCRIPT_DIR}/check_framework_release_assets.sh" --release-id "$CREATED_RELEASE" --require-current --expected-title "wp-core-base ${TAG}" --expected-notes-file "$NOTES" "$REPOSITORY" "$TAG" "$ARTIFACT" "$CHECKSUM" "$SIGNATURE"
printf '{"draft":false}\n' > "${TMP_DIR}/publish.json"
AMBIGUOUS=true; write_journal
status="$(release_api_request PATCH "${API_ROOT}/repos/${REPOSITORY}/releases/${CREATED_RELEASE}" "${TMP_DIR}/published.json" "${TMP_DIR}/publish.json")"
if [ "$status" != 200 ] || ! jq -e --argjson id "$CREATED_RELEASE" '.id == $id and .draft == false' "${TMP_DIR}/published.json" >/dev/null; then
  echo 'Publish result is uncertain; release and tag preserved.' >&2; exit 1
fi
PUBLISHED=true; AMBIGUOUS=false; write_journal
echo "Published verified release ${TAG}; receipt: ${JOURNAL}"
