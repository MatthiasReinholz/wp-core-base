#!/usr/bin/env bash
# Shared bounded HTTPS transport. Never let curl follow a redirect with credentials.

release_url_origin() {
  # shellcheck disable=SC2016
  php -r '
    $p = parse_url($argv[1]);
    if (!is_array($p) || ($p["scheme"] ?? "") !== "https" || empty($p["host"]) || isset($p["user"]) || isset($p["pass"]) || isset($p["fragment"]) || (($p["port"] ?? 443) !== 443) || preg_match("/[\\x00-\\x20\\x7f]/", $argv[1])) { exit(1); }
    echo "https://" . strtolower($p["host"]) . ":443";
  ' "$1"
}

release_api_origin() { release_url_origin "${GITHUB_API_URL:-https://api.github.com}"; }

release_assert_api_url() {
  local origin
  origin="$(release_url_origin "$1")" || { echo 'Release API URL must be HTTPS without credentials or a nonstandard port.' >&2; return 1; }
  [ "$origin" = "$(release_api_origin)" ] || { echo 'Release API URL does not match the configured API origin.' >&2; return 1; }
}

release_api_request() {
  local method="$1" url="$2" destination="$3" body="${4:-}"
  release_assert_api_url "$url" || return 1
  local args=(--proto '=https' --tlsv1.2 --connect-timeout 15 --max-time 120 --max-filesize 5242880 -sS -X "$method" -o "$destination" -w '%{http_code}' -H 'Accept: application/vnd.github+json' -H "Authorization: Bearer ${GITHUB_TOKEN:?}" -H 'X-GitHub-Api-Version: 2022-11-28')
  if [ -n "$body" ]; then args+=(-H 'Content-Type: application/json' --data-binary "@$body"); fi
  curl "${args[@]}" "$url"
}

# Read-only release gates reject redirects and non-success responses, just like publication.
release_api_get_json() {
  local response status
  response="$(mktemp)" || return 1
  if ! status="$(release_api_request GET "$1" "$response")"; then
    rm -f "$response"
    return 1
  fi
  if [ "$status" != 200 ]; then
    rm -f "$response"
    echo "Release API read failed (HTTP ${status}); redirects are not followed." >&2
    return 1
  fi
  cat "$response"
  rm -f "$response"
}

release_asset_origin_allowed() {
  local origin="$1"
  [ "$origin" = "$(release_api_origin)" ] && return 0
  case "$origin" in
    https://github.com:443|https://uploads.github.com:443|https://objects.githubusercontent.com:443|https://objects-origin.githubusercontent.com:443|https://release-assets.githubusercontent.com:443|https://github-releases.githubusercontent.com:443) return 0 ;;
  esac
  return 1
}

release_download_asset() {
  local url="$1" destination="$2" origin previous_origin probe status redirect hop authenticated=true
  release_assert_api_url "$url" || return 1
  previous_origin="$(release_api_origin)"
  for ((hop=0; hop<6; hop++)); do
    origin="$(release_url_origin "$url")" || { echo 'Release asset redirect must use HTTPS without credentials.' >&2; return 1; }
    release_asset_origin_allowed "$origin" || { echo 'Release asset redirect host is not allowlisted.' >&2; return 1; }
    if [ "$origin" != "$previous_origin" ]; then authenticated=false; fi
    local args=(--proto '=https' --tlsv1.2 --connect-timeout 15 --max-time 120 --max-filesize 536870912 -sS -o "$destination" -w '%{http_code}\n%{redirect_url}' -H 'Accept: application/octet-stream')
    if [ "$authenticated" = true ]; then args+=(-H "Authorization: Bearer ${GITHUB_TOKEN:?}" -H 'X-GitHub-Api-Version: 2022-11-28'); fi
    probe="$(curl "${args[@]}" "$url")" || return 1
    status="${probe%%$'\n'*}"
    redirect="${probe#*$'\n'}"
    case "$status" in
      200) return 0 ;;
      301|302|303|307|308)
        [ -n "$redirect" ] && [ "$redirect" != "$probe" ] || { echo 'Release asset redirect has no destination.' >&2; return 1; }
        # Validate the next hop before another request; auth can never be restored.
        previous_origin="$origin"
        url="$redirect"
        ;;
      *) echo "Failed to download release asset (HTTP ${status})." >&2; return 1 ;;
    esac
  done
  echo 'Release asset redirect limit exceeded.' >&2
  return 1
}
