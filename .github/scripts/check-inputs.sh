#!/usr/bin/env bash
# Checks the release check workflow inputs before a tool runs.
#
# Reads $VERSIONS (comma-separated X.Y.Z or X.Y) and, with --max-timeout, $TIMEOUT.
# On success, writes list=<JSON array> to $GITHUB_OUTPUT and VERSIONS=<clean list> to
# $GITHUB_ENV. On failure, writes the reason to $GITHUB_STEP_SUMMARY and exits 1.
#
# Options:
#   --refuse-x-y-0       Refuse X.Y and X.Y.0 versions.
#   --max-timeout=DUR    Refuse a $TIMEOUT longer than DUR (s, m or h).
#   --min-timeout=DUR    Refuse a $TIMEOUT shorter than DUR.
set -euo pipefail
shopt -s extglob

refuse() {
	echo "::error::$1"
	echo "Refused: $1" >> "$GITHUB_STEP_SUMMARY"
	exit 1
}

seconds() {
	[[ $1 =~ ^([0-9]+)([smh]?)$ ]] || return 1
	case "${BASH_REMATCH[2]}" in
		h) echo $((10#${BASH_REMATCH[1]} * 3600)) ;;
		m) echo $((10#${BASH_REMATCH[1]} * 60)) ;;
		*) echo $((10#${BASH_REMATCH[1]})) ;;
	esac
}

refuse_x_y_0=false
max_timeout=
min_timeout=
for arg in "$@"; do
	case "$arg" in
		--refuse-x-y-0) refuse_x_y_0=true ;;
		--max-timeout=*) max_timeout="${arg#*=}" ;;
		--min-timeout=*) min_timeout="${arg#*=}" ;;
		*) echo "Unknown option: $arg" >&2; exit 1 ;;
	esac
done

[[ ${VERSIONS:-} != *$'\n'* ]] || refuse 'Use commas, not new lines, between versions.'
IFS=',' read -ra items <<< "${VERSIONS:-}"
[ "${#items[@]}" -gt 0 ] || refuse 'No versions given.'
list=()
for item in "${items[@]}"; do
	version="${item##+([[:space:]])}"
	version="${version%%+([[:space:]])}"
	[[ $version =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(\.(0|[1-9][0-9]*))?$ ]] || refuse "Not an X.Y.Z or X.Y version: '$item'."
	if $refuse_x_y_0 && [[ ! $version =~ \.[0-9]+\.[1-9][0-9]*$ ]]; then
		refuse "$version is an X.Y.0 release. package-smoke needs --previous for it, which this workflow does not pass yet. Run the tools locally."
	fi
	[[ " ${list[*]-} " == *" $version "* ]] || list+=("$version")
done

if [ -n "$max_timeout$min_timeout" ]; then
	timeout=$(seconds "${TIMEOUT:-}") || refuse "Bad timeout: '${TIMEOUT:-}'. Use a whole number with s, m or h."
	[ -z "$min_timeout" ] || [ "$timeout" -ge "$(seconds "$min_timeout")" ] || refuse "The timeout must be at least $min_timeout."
	[ -z "$max_timeout" ] || [ "$timeout" -le "$(seconds "$max_timeout")" ] || refuse "The timeout $TIMEOUT is longer than $max_timeout, so the job would stop first."
fi

echo "list=$(printf '%s\n' "${list[@]}" | jq -Rnc '[inputs]')" >> "$GITHUB_OUTPUT"
echo "VERSIONS=$(IFS=,; echo "${list[*]}")" >> "$GITHUB_ENV"
