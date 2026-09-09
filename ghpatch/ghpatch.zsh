# Vendored from Peter Wilson's gist on 2026-07-26:
# https://gist.github.com/peterwilsoncc/ceca0f962d9eb2416c09f89d695a19ad

# Apply a PR diff to a Subversion working copy.
#
# @param $pr_number  int Pull Request number. Required.
# @param $repository string Repository to pull from. Defaults to WordPress/WordPress-Develop
function ghpatch() {
	# If no arguments are passed, fail.
	if [ -z "$1" ]; then
		echo "🤦‍♂️ No PR number provided";
		return 1;
	elif ! [[ $1 =~ ^[0-9]+$ ]]; then
		echo "🤦‍♂️ PR number must be a number";
		return 1;
	else
		local pr_number=$1;
	fi

	# If no repository is provided, use WordPress/wordpress-develop
	if [ -z "$2" ]; then
		local repository="WordPress/wordpress-develop";
	# Check the repository is valid
	elif ! gh repo view $2 > /dev/null 2>&1; then
		echo "🤦‍♂️ Repository $2 not found";
		return 1;
	else
		local repository=$2;
	fi

	# Ensure that the PR exists
	if ! gh pr view $pr_number --repo=$repository > /dev/null 2>&1; then
		echo "🤦‍♂️ PR $pr_number not found in $repository";
		return 1;
	fi

	# Get the PR title
	local pr_title=$(gh pr view $pr_number --json title --jq .title --repo=$repository);

	# Apply the patch.
	#
	# `svn patch` rather than `patch -p1`: GNU patch writes a new file's contents but never
	# versions it, and `svn commit` silently omits unversioned files, so a PR's new files
	# vanish while its modified files land. That is how r62859 landed without
	# reusable-prepare-gutenberg.yml. `svn patch` schedules the add itself, and strips the
	# diff's a/ and b/ prefixes on its own, so no --strip is needed. It reads a file rather
	# than stdin, hence the temporary file.
	echo "Applying $repository#$pr_number: $pr_title";
	local patch_file;
	patch_file=$(mktemp -t ghpatch) || return 1;
	if ! gh pr diff $pr_number --repo=$repository > $patch_file; then
		echo "🤦‍♂️ Could not read the diff for $repository#$pr_number";
		rm -f $patch_file;
		return 1;
	fi
	local patch_output;
	patch_output=$(svn patch $patch_file);
	local patch_status=$?;
	rm -f $patch_file;
	echo $patch_output;

	# `svn patch` exits 0 even when it rejects a hunk, where GNU patch exited non-zero.
	# Fail loudly instead, so a partial apply cannot be mistaken for a clean one.
	if echo $patch_output | grep -qE 'rejected hunk|Summary of conflicts'; then
		echo "🤦‍♂️ $repository#$pr_number did not apply cleanly. Resolve the conflicts above before committing.";
		return 1;
	fi

	return $patch_status;
}
