#!/usr/bin/env zsh
# Offline test for svnmergecheck. Builds a scratch Subversion repository with
# svnadmin, so it needs no network access. A second section drives the
# function against a fake `svn` on PATH to exercise mergeinfo shapes a real
# repository cannot easily produce.

TOOL="${0:A:h}/../svnmergecheck.zsh"
source "$TOOL"

typeset -i PASS_COUNT=0
typeset -i FAIL_COUNT=0

function assert_equal() {
	local desc="$1" expected="$2" actual="$3"
	PASS_COUNT=$((PASS_COUNT + 1))
	if [[ "$expected" != "$actual" ]]; then
		FAIL_COUNT=$((FAIL_COUNT + 1))
		echo "FAIL: $desc (expected [$expected], got [$actual])" >&2
	fi
}

function assert_contains() {
	local desc="$1" haystack="$2" needle="$3"
	PASS_COUNT=$((PASS_COUNT + 1))
	if [[ "$haystack" != *"$needle"* ]]; then
		FAIL_COUNT=$((FAIL_COUNT + 1))
		echo "FAIL: $desc (expected output to contain [$needle])" >&2
		echo "--- actual output ---" >&2
		echo "$haystack" >&2
	fi
}

function assert_not_contains() {
	local desc="$1" haystack="$2" needle="$3"
	PASS_COUNT=$((PASS_COUNT + 1))
	if [[ "$haystack" == *"$needle"* ]]; then
		FAIL_COUNT=$((FAIL_COUNT + 1))
		echo "FAIL: $desc (expected output not to contain [$needle])" >&2
		echo "--- actual output ---" >&2
		echo "$haystack" >&2
	fi
}

function run_check() {
	local dir="$1";
	shift;
	( cd "$dir" 2>/dev/null && svnmergecheck "$@" )
}

TMPROOT=$(mktemp -d)
function cleanup() {
	rm -rf "$TMPROOT";
}
trap cleanup EXIT INT TERM

REPO="$TMPROOT/repo"
REPO_URL="file://$REPO"
TRUNK_WC="$TMPROOT/trunk_wc"
BRANCH_WC="$TMPROOT/branch_wc"
NOT_A_WC="$TMPROOT/not_a_wc"

svnadmin create "$REPO" || { echo "setup: svnadmin create failed" >&2; exit 1; }
mkdir -p "$NOT_A_WC"

# r1: trunk and branches directories.
svn mkdir -q -m "Create trunk and branches" \
	"$REPO_URL/trunk" "$REPO_URL/branches" \
	|| { echo "setup: svn mkdir failed" >&2; exit 1; }

# r2: branch 7.0, copied before any of the trunk commits below exist on it.
svn copy -q -m "Create branch 7.0" "$REPO_URL/trunk" "$REPO_URL/branches/7.0" \
	|| { echo "setup: svn copy failed" >&2; exit 1; }

svn checkout -q "$REPO_URL/trunk" "$TRUNK_WC" \
	|| { echo "setup: checkout trunk failed" >&2; exit 1; }

# r3..r8: six trunk commits. r3 is used alone; r4 is a deliberate gap so it
# does not merge into the span below; r5-r7 are merged together as one
# consecutive span; r8 is left unrecorded as the adjacent revision.
local name;
for name in a b c d e f; do
	echo "content-$name" > "$TRUNK_WC/$name.txt"
	( cd "$TRUNK_WC" && svn add -q "$name.txt" && svn commit -q -m "Add $name.txt" ) \
		|| { echo "setup: commit of $name.txt failed" >&2; exit 1; }
done

svn checkout -q "$REPO_URL/branches/7.0" "$BRANCH_WC" \
	|| { echo "setup: checkout branch failed" >&2; exit 1; }

# 1. No arguments.
output=$(run_check "$BRANCH_WC" 2>&1)
rc=$?
assert_equal "no arguments returns 1" 1 "$rc"

# 2. Not a working copy.
output=$(run_check "$NOT_A_WC" 3 2>&1)
rc=$?
assert_equal "outside a working copy returns 1" 1 "$rc"

# 3. A revision that was never record-only merged.
output=$(run_check "$BRANCH_WC" 3 2>&1)
rc=$?
assert_equal "unrecorded revision returns 1" 1 "$rc"
assert_contains "unrecorded revision named as missing" "$output" "is missing: 3"

# 4. Record-only merge, then check again.
( cd "$BRANCH_WC" && svn merge -q --record-only -c 3 '^/trunk' ) \
	|| { echo "setup: record-only merge of r3 failed" >&2; exit 1; }
output=$(run_check "$BRANCH_WC" 3 2>&1)
rc=$?
assert_equal "recorded revision returns 0" 0 "$rc"
assert_contains "recorded revision warns to commit the whole working copy" "$output" "Commit the whole working copy"

# 5. After the mergeinfo change is committed, '.' has nothing pending.
( cd "$BRANCH_WC" && svn commit -q -m "Record merge of r3" ) \
	|| { echo "setup: commit of mergeinfo for r3 failed" >&2; exit 1; }
output=$(run_check "$BRANCH_WC" 3 2>&1)
rc=$?
assert_equal "already-committed revision still returns 0" 0 "$rc"
assert_contains "already-committed revision notes no pending change" "$output" "shows no pending change"

# 6. Three consecutive revisions recorded together collapse into an a-b span.
( cd "$BRANCH_WC" && svn merge -q --record-only -c 5,6,7 '^/trunk' ) \
	|| { echo "setup: record-only merge of r5-r7 failed" >&2; exit 1; }

mergeinfo=$( (cd "$BRANCH_WC" && svn propget svn:mergeinfo .) )
assert_contains "consecutive revisions collapse into a span" "$mergeinfo" "5-7"

output=$(run_check "$BRANCH_WC" 5 2>&1)
rc=$?
assert_equal "span start boundary (r5) returns 0" 0 "$rc"

output=$(run_check "$BRANCH_WC" 6 2>&1)
rc=$?
assert_equal "span interior (r6) returns 0" 0 "$rc"

output=$(run_check "$BRANCH_WC" 7 2>&1)
rc=$?
assert_equal "span end boundary (r7) returns 0" 0 "$rc"

output=$(run_check "$BRANCH_WC" 5 6 7 2>&1)
rc=$?
assert_equal "full span requested together returns 0" 0 "$rc"

output=$(run_check "$BRANCH_WC" 8 2>&1)
rc=$?
assert_equal "adjacent unrecorded revision (r8) returns 1" 1 "$rc"
assert_contains "adjacent unrecorded revision named as missing" "$output" "is missing: 8"

# --- Fixture section: a fake `svn` on PATH, for mergeinfo shapes a real
# repository cannot easily produce (multi-span ranges, non-inheritable
# ranges, and non-trunk merge sources sharing the property).

FAKEBIN="$TMPROOT/fakebin"
mkdir -p "$FAKEBIN"
cat > "$FAKEBIN/svn" <<'FAKESVN'
#!/bin/sh
if [ "$1" = "info" ] && [ "$2" = "." ] && [ "$3" = "--show-item" ]; then
	case "$4" in
		repos-uuid) echo "fixture-uuid"; exit 0 ;;
		url) echo "https://develop.svn.wordpress.org/branches/fixture"; exit 0 ;;
		*) exit 1 ;;
	esac
elif [ "$1" = "info" ] && [ "$2" = "." ]; then
	exit 0
elif [ "$1" = "propget" ] && [ "$2" = "svn:mergeinfo" ] && [ "$3" = "." ]; then
	printf '%s\n' "$SVNMERGECHECK_TEST_MERGEINFO"
	exit 0
elif [ "$1" = "status" ] && [ "$2" = "." ]; then
	exit 0
else
	exit 1
fi
FAKESVN
chmod +x "$FAKEBIN/svn"

FIXTURE_OUTPUT=""
FIXTURE_STATUS=0
function run_fixture() {
	local mergeinfo="$1";
	shift;
	FIXTURE_OUTPUT=$(PATH="$FAKEBIN:$PATH" SVNMERGECHECK_TEST_MERGEINFO="$mergeinfo" zsh -c "source '$TOOL'; svnmergecheck $*" 2>&1)
	FIXTURE_STATUS=$?
}

# 1. Revisions inside a multi-entry range.
run_fixture '/trunk:63173,63489-63492' 63490 63491
assert_equal "revisions inside a range return 0" 0 "$FIXTURE_STATUS"
assert_not_contains "revisions inside a range are not reported missing" "$FIXTURE_OUTPUT" "is missing"

# 2. Range boundaries.
run_fixture '/trunk:63173,63489-63492' 63489 63492
assert_equal "range boundaries return 0" 0 "$FIXTURE_STATUS"

# 3. A single recorded revision ahead of a range.
run_fixture '/trunk:63173,63489-63492' 63173
assert_equal "single revision ahead of a range returns 0" 0 "$FIXTURE_STATUS"

# 4. Three separate single-revision entries.
run_fixture '/trunk:100,200,300' 100 200 300
assert_equal "three separate single-revision entries return 0" 0 "$FIXTURE_STATUS"

# 5. A revision nowhere in the recorded ranges.
run_fixture '/trunk:63173,63489-63492' 99999
assert_equal "unrecorded revision returns 1" 1 "$FIXTURE_STATUS"
assert_contains "unrecorded revision named as missing" "$FIXTURE_OUTPUT" "is missing: 99999"

# 6. Non-inheritable ranges, marked with a trailing "*".
run_fixture '/trunk:500*,600-602*' 500 601
assert_equal "non-inheritable ranges still count as recorded" 0 "$FIXTURE_STATUS"

# 7. The trunk line is still found alongside another merge source.
run_fixture "$(printf '/branches/6.4:63490\n/trunk:63173,63489-63492')" 63490
assert_equal "trunk line found alongside another merge source" 0 "$FIXTURE_STATUS"

# 8. A revision recorded only on a non-trunk path does not satisfy the check.
run_fixture "$(printf '/branches/6.4:9999\n/trunk:63173,63489-63492')" 9999
assert_equal "revision recorded only on a non-trunk path returns 1" 1 "$FIXTURE_STATUS"

# 9. No /trunk: line at all.
run_fixture '/branches/6.4:63490' 63490
assert_equal "no trunk line at all returns 1" 1 "$FIXTURE_STATUS"
assert_contains "no trunk line names the revision as missing" "$FIXTURE_OUTPUT" "is missing: 63490"

if (( FAIL_COUNT > 0 )); then
	echo "FAILED: $FAIL_COUNT of $PASS_COUNT assertions failed." >&2
	exit 1
fi

echo "Passed $PASS_COUNT assertions."
