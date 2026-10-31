#!/usr/bin/env bash
# Refreshes ios/RuleSets: downloads every rule-set listed in ios/scripts/rule-sets.tsv to
# ios/RuleSets/<tag>.srs and rewrites ios/RuleSets/SHA256SUMS.
#
# The app ships these files and never downloads rule-sets itself, so run this by hand when the
# rule data should be refreshed, then commit the result. Honors https_proxy.
set -euo pipefail

repo_root="$(cd "$(dirname "$0")/../.." && pwd)"
sources="$repo_root/ios/scripts/rule-sets.tsv"
output="$repo_root/ios/RuleSets"
staging="$(mktemp -d)"
trap 'rm -rf "$staging"' EXIT

if command -v sha256sum >/dev/null 2>&1; then
  sha256() { sha256sum "$@"; }
else
  sha256() { shasum -a 256 "$@"; }
fi

count=0
while IFS=$'\t' read -r tag url; do
  case "$tag" in ''|'#'*) continue ;; esac
  curl --fail --silent --show-error --location --retry 5 --retry-all-errors --max-time 60 -o "$staging/$tag.srs" "$url"
  test -s "$staging/$tag.srs"
  count=$((count + 1))
done < "$sources"

rm -rf "$output"
mkdir -p "$output"
cp "$staging"/*.srs "$output"/
(cd "$output" && LC_ALL=C ls -1 *.srs | while read -r file; do sha256 "$file"; done | sed 's| \*|  |' > SHA256SUMS)
echo "$count rule-sets in $output ($(du -sh "$output" | cut -f1))"
