#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MANIFEST="${SCRIPT_DIR}/quality-tools.json"
DESTINATION="${1:-.wp-core-base/build/quality-tools}"
case "$(uname -s)-$(uname -m)" in
  Linux-x86_64) platform=linux-amd64; action_platform=linux_amd64; shell_platform=linux.x86_64 ;;
  Darwin-arm64) platform=darwin-arm64; action_platform=darwin_arm64; shell_platform=darwin.aarch64 ;;
  *) echo 'Quality tools are pinned for Linux x86_64 and macOS arm64.' >&2; exit 1 ;;
esac
mkdir -p "$DESTINATION"
temporary="$(mktemp -d)"
trap 'rm -rf "$temporary"' EXIT
fetch_verified() {
  local url="$1" expected="$2" output="$3" actual
  [[ "$expected" =~ ^[a-f0-9]{64}$ ]] || { echo 'Missing pinned quality tool digest.' >&2; exit 1; }
  curl --proto '=https' --proto-redir '=https' --tlsv1.2 --connect-timeout 15 --max-time 180 --max-filesize 104857600 -fsSL "$url" -o "$output"
  actual="$(shasum -a 256 "$output" | awk '{print $1}')"
  [ "$actual" = "$expected" ] || { echo "Quality tool checksum mismatch: $url" >&2; exit 1; }
}
phpstan_url="$(jq -er '.phpstan.url' "$MANIFEST")"
fetch_verified "$phpstan_url" "$(jq -er '.phpstan.sha256' "$MANIFEST")" "$temporary/phpstan.phar"
action_version="$(jq -er '.actionlint.version' "$MANIFEST")"
fetch_verified "https://github.com/rhysd/actionlint/releases/download/v${action_version}/actionlint_${action_version}_${action_platform}.tar.gz" "$(jq -er --arg platform "$platform" '.actionlint[$platform].sha256' "$MANIFEST")" "$temporary/actionlint.tar.gz"
tar -xOf "$temporary/actionlint.tar.gz" actionlint > "$temporary/actionlint"
shell_version="$(jq -er '.shellcheck.version' "$MANIFEST")"
fetch_verified "https://github.com/koalaman/shellcheck/releases/download/v${shell_version}/shellcheck-v${shell_version}.${shell_platform}.tar.gz" "$(jq -er --arg platform "$platform" '.shellcheck[$platform].sha256' "$MANIFEST")" "$temporary/shellcheck.tar.gz"
tar -xOf "$temporary/shellcheck.tar.gz" "shellcheck-v${shell_version}/shellcheck" > "$temporary/shellcheck"
chmod 0755 "$temporary/actionlint" "$temporary/shellcheck"
for tool in phpstan.phar actionlint shellcheck; do mv "$temporary/$tool" "$DESTINATION/$tool"; done
printf 'Verified quality tools installed at %s\n' "$DESTINATION"
