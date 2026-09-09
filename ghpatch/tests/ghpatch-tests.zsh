#!/usr/bin/env zsh
# Offline test for ghpatch. Fakes `gh` on PATH, so it needs no network access
# or GitHub credentials, and builds a scratch Subversion repository with
# svnadmin to exercise a real `svn patch` apply.

TOOL="${0:A:h}/../ghpatch.zsh"
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

function run_ghpatch() {
	local dir="$1";
	shift;
	( cd "$dir" 2>/dev/null && ghpatch "$@" )
}

TMPROOT=$(mktemp -d)
function cleanup() {
	rm -rf "$TMPROOT";
}
trap cleanup EXIT INT TERM

# --- A fake `gh` on PATH, so the test needs no network access or GitHub
# credentials. WordPress/wordpress-develop and example/ok are known
# repositories; PRs 1 and 2 are known pull requests.

FAKEBIN="$TMPROOT/fakebin"
mkdir -p "$FAKEBIN"
cat > "$FAKEBIN/gh" <<'FAKEGH'
#!/bin/sh
case "$1 $2" in
	"repo view")
		case "$3" in
			WordPress/wordpress-develop|example/ok) exit 0 ;;
			*) exit 1 ;;
		esac
		;;
	"pr view")
		pr="$3"
		shift 3
		want_json=0
		for arg in "$@"; do
			[ "$arg" = "--json" ] && want_json=1
		done
		case "$pr" in
			1|2) : ;;
			*) exit 1 ;;
		esac
		if [ "$want_json" = 1 ]; then
			echo "Fixture PR $pr"
		fi
		exit 0
		;;
	"pr diff")
		if [ -z "$GHPATCH_TEST_DIFF_FILE" ]; then
			exit 1
		fi
		cat "$GHPATCH_TEST_DIFF_FILE"
		exit 0
		;;
	*)
		exit 1
		;;
esac
FAKEGH
chmod +x "$FAKEBIN/gh"
export PATH="$FAKEBIN:$PATH"

# --- A scratch Subversion repository, so `svn patch` runs against a real
# working copy without touching anything outside $TMPROOT.

REPO="$TMPROOT/repo"
REPO_URL="file://$REPO"
IMPORT="$TMPROOT/import/trunk"
WC="$TMPROOT/wc"

svnadmin create "$REPO" || { echo "setup: svnadmin create failed" >&2; exit 1; }
mkdir -p "$IMPORT"
echo "existing line" > "$IMPORT/existing.txt"
svn import -q -m "Initial import" "$IMPORT" "$REPO_URL/trunk" \
	|| { echo "setup: svn import failed" >&2; exit 1; }
svn checkout -q "$REPO_URL/trunk" "$WC" \
	|| { echo "setup: svn checkout failed" >&2; exit 1; }

# --- Fixture diffs, in git format, the shape `gh pr diff` returns.
#
# Fixture 1 modifies existing.txt and adds added.txt: this is the property
# ghpatch exists for, since GNU `patch -p1` would write added.txt without
# ever scheduling it for add.
#
# Fixture 2 modifies existing.txt with a hunk whose context does not match
# the working copy, so `svn patch` rejects it.

FIXTURE1="$TMPROOT/fixture1.diff"
cat > "$FIXTURE1" <<'DIFF1'
diff --git a/existing.txt b/existing.txt
index e69de29..b7c04ff 100644
--- a/existing.txt
+++ b/existing.txt
@@ -1 +1 @@
-existing line
+modified line
diff --git a/added.txt b/added.txt
new file mode 100644
index 0000000..3b18e51
--- /dev/null
+++ b/added.txt
@@ -0,0 +1 @@
+added line
DIFF1

FIXTURE2="$TMPROOT/fixture2.diff"
cat > "$FIXTURE2" <<'DIFF2'
diff --git a/existing.txt b/existing.txt
index e69de29..b7c04ff 100644
--- a/existing.txt
+++ b/existing.txt
@@ -1 +1 @@
-a line that does not appear in existing.txt
+replacement line
DIFF2

# 1. No arguments.
output=$(run_ghpatch "$WC")
rc=$?
assert_equal "no arguments returns 1" 1 "$rc"

# 2. Non-numeric PR number.
output=$(run_ghpatch "$WC" abc)
rc=$?
assert_equal "non-numeric PR returns 1" 1 "$rc"

# 3. Unknown repository.
output=$(run_ghpatch "$WC" 1 example/bad)
rc=$?
assert_equal "unknown repository returns 1" 1 "$rc"

# 4. PR that does not exist.
output=$(run_ghpatch "$WC" 999)
rc=$?
assert_equal "PR that does not exist returns 1" 1 "$rc"

# --- Snapshot the temp directory before either fixture runs `svn patch`, so
# the two fixture cases below can confirm ghpatch's mktemp file is gone
# afterward, on both the clean-apply and the rejected-hunk path.

before_tmp=$(ls "${TMPDIR:-/tmp}"/ghpatch*(N) 2>/dev/null)

# 5. Fixture 1 applies cleanly: existing.txt is modified, added.txt is
# scheduled for add. That scheduling is the property ghpatch exists for.
output=$(GHPATCH_TEST_DIFF_FILE="$FIXTURE1" run_ghpatch "$WC" 1)
rc=$?
assert_equal "fixture 1 applies cleanly" 0 "$rc"
assert_contains "fixture 1 announces the PR" "$output" "Applying WordPress/wordpress-develop#1: Fixture PR 1"

svn_status=$(cd "$WC" && svn status)
assert_contains "existing.txt is modified" "$svn_status" "M       existing.txt"
assert_contains "added.txt is scheduled for add" "$svn_status" "A       added.txt"

# 6. Fixture 2's hunk context does not match, so svn patch rejects it and
# ghpatch fails loudly instead of returning svn patch's own exit code (0).
( cd "$WC" && svn revert -R . > /dev/null && rm -f added.txt ) \
	|| { echo "setup: revert before fixture 2 failed" >&2; exit 1; }

output=$(GHPATCH_TEST_DIFF_FILE="$FIXTURE2" run_ghpatch "$WC" 2)
rc=$?
assert_equal "fixture 2 rejected hunk returns 1" 1 "$rc"
assert_contains "fixture 2 reports failure" "$output" "did not apply cleanly"

after_tmp=$(ls "${TMPDIR:-/tmp}"/ghpatch*(N) 2>/dev/null)
assert_equal "no leftover ghpatch temp file" "$before_tmp" "$after_tmp"

if (( FAIL_COUNT > 0 )); then
	echo "FAILED: $FAIL_COUNT of $PASS_COUNT assertions failed." >&2
	exit 1
fi

echo "Passed $PASS_COUNT assertions."
