#!/usr/bin/env bash

set -euo pipefail

REPOSITORY="${1:-}"
VERSION="${2:-}"
COMMIT_SHA="${3:-}"
WORKFLOW_FILE="${4:-wporg-validate-runtime.yml}"
API_ROOT="${GITHUB_API_URL:-https://api.github.com}"

if [ -z "$REPOSITORY" ] || [ -z "$VERSION" ] || [ -z "$COMMIT_SHA" ]; then
  echo "Usage: $0 owner/repo vX.Y.Z commit-sha [workflow-file]" >&2
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

github_api_get() {
  curl -fsSL \
    -H "Accept: application/vnd.github+json" \
    -H "Authorization: Bearer ${GITHUB_TOKEN}" \
    -H "X-GitHub-Api-Version: 2022-11-28" \
    "$1"
}

pulls_json="$(
  github_api_get "${API_ROOT}/repos/${REPOSITORY}/commits/${COMMIT_SHA}/pulls"
)"

release_pr_json="$(
  printf '%s' "$pulls_json" | jq -cer --arg version "$VERSION" --arg sha "$COMMIT_SHA" '
    map(
      select(
        .merged_at != null and
        .base.ref == "main" and
        .head.ref == ("release/" + $version) and
        .merge_commit_sha == $sha
      )
    ) | first // empty
  '
)"

if [ -z "$release_pr_json" ]; then
  echo "Commit ${COMMIT_SHA} is not the merge commit of a merged release PR for version ${VERSION}." >&2
  exit 1
fi

pr_number="$(printf '%s' "$release_pr_json" | jq -r '.number')"

runs_json="$(
  github_api_get "${API_ROOT}/repos/${REPOSITORY}/actions/workflows/${WORKFLOW_FILE}/runs?head_sha=${COMMIT_SHA}&per_page=100"
)"

successful_run_count="$(
  printf '%s' "$runs_json" | jq --arg commit_sha "$COMMIT_SHA" '
    [
      .workflow_runs[]?
      | select(
          .head_sha == $commit_sha and
          .head_branch == "main" and
          .event == "push" and
          .status == "completed" and
          .conclusion == "success"
        )
    ] | length
  '
)"

if [ "$successful_run_count" -lt 1 ]; then
  run_summary="$(
    printf '%s' "$runs_json" | jq -r --arg commit_sha "$COMMIT_SHA" '
      [
        .workflow_runs[]?
        | select(.head_sha == $commit_sha)
        | "\(.event):\(.status):\(.conclusion // "null")"
      ] | unique | join(", ")
    '
  )"

  if [ -z "$run_summary" ] || [ "$run_summary" = 'null' ]; then
    run_summary="no matching workflow runs found"
  fi

  echo "Release PR #${pr_number} for ${VERSION} has no successful ${WORKFLOW_FILE} push run on merged commit ${COMMIT_SHA}. Found: ${run_summary}." >&2
  exit 1
fi

echo "Verified merged release PR #${pr_number} for ${VERSION} and successful ${WORKFLOW_FILE} push run on merged commit ${COMMIT_SHA}."
