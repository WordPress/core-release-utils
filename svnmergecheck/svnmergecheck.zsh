# Verify that a record-only merge actually recorded mergeinfo, before you commit.
#
# The failure this closes: r62873 claims to merge r62859 into 7.0, but its file
# list has no `.`, so svn:mergeinfo was never written. The commit looked fine.
# Nothing downstream noticed, because the fix content was there — only the
# provenance was missing.
#
# Run from the branch root, after `svn merge --record-only`, before `svn commit`.
#
# @param $@ int One or more trunk revision numbers the branch should record.
function svnmergecheck() {
	if [ -z "$1" ]; then
		echo "🤦‍♂️ No revisions given. Usage: svnmergecheck 62859 62862";
		return 1;
	fi

	if ! svn info . > /dev/null 2>&1; then
		echo "🤦‍♂️ Not a Subversion working copy: $(pwd)";
		return 1;
	fi

	# Wrong-repo guard. A rehearsal working copy and a real one look identical
	# at the shell; only the UUID separates them.
	echo "Repository UUID: $(svn info . --show-item repos-uuid)";
	echo "Working copy:    $(svn info . --show-item url)";
	echo;

	local recorded;
	recorded=$(svn propget svn:mergeinfo . 2>/dev/null);

	# svn:mergeinfo is multi-line, one "<path>:<ranges>" entry per merge
	# source, and only /trunk feeds this check. Each entry in the list is a
	# single revision or an "a-b" span (optionally suffixed with a
	# non-inheritable "*"), and Subversion collapses adjacent revisions into a
	# span, so a revision has to be range-tested rather than string-matched.
	local trunk_line;
	trunk_line=$(echo "$recorded" | grep -E '^/trunk:' | head -1);
	local trunk_ranges="${trunk_line#/trunk:}";

	local missing=();
	local rev entry found range_start range_end;
	for rev in "$@"; do
		found=0;
		for entry in "${(s:,:)trunk_ranges}"; do
			entry="${entry%\*}";
			if [[ "$entry" == *-* ]]; then
				range_start="${entry%%-*}";
				range_end="${entry##*-}";
				if (( rev >= range_start && rev <= range_end )); then
					found=1;
					break;
				fi
			elif [[ "$entry" == "$rev" ]]; then
				found=1;
				break;
			fi
		done;
		if [[ $found -eq 0 ]]; then
			missing+=("$rev");
		fi
	done

	if [ ${#missing[@]} -ne 0 ]; then
		echo "🤦‍♂️ svn:mergeinfo on '.' is missing: ${missing[*]}";
		echo;
		echo "Recorded now:";
		echo "${recorded:-  (none)}";
		echo;
		echo "Run the record-only merge before committing:";
		echo "  svn merge --record-only -c $(IFS=,; echo "$*") '^/trunk'";
		return 1;
	fi

	# Mergeinfo is present. Now confirm this commit will actually carry it:
	# a property change on '.' shows as 'M' in the second status column.
	local dotstatus;
	dotstatus=$(svn status . 2>/dev/null | grep -E '^.{0,1}M.* \.$' | head -1);

	echo "svn:mergeinfo on '.' contains: $*";
	echo "${recorded}";
	echo;
	if [ -n "$dotstatus" ]; then
		echo "'.' has a pending property change, so the commit will carry it.";
		echo "⚠️  Commit the whole working copy, not a path list. 'svn commit somefile.php' omits '.' and drops the mergeinfo — that is exactly how r62873 happened.";
	else
		echo "⚠️  '.' shows no pending change. Either the mergeinfo was committed by an earlier revision, or nothing new will be recorded by this commit. Check 'svn log -v' for a revision whose file list includes '.'.";
	fi
	return 0;
}
