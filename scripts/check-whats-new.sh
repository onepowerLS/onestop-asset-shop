#!/bin/bash
# scripts/check-whats-new.sh
#
# Enforces the What's New policy (docs/SYSTEM_SPECS.md section 1,
# docs/whats_new/README.md): any feature-bearing commit in a push MUST be
# accompanied by at least one new marker file under docs/whats_new/entries/
# in the same push.
#
# A commit is "feature-bearing" if its subject starts with
#   feat: | feature: | improve: | enhance:   (Conventional Commits style).
#
# Usage:
#   scripts/check-whats-new.sh [range]   # default range: origin/main..HEAD
#   # or as a pre-push hook (reads stdin lines: local_ref local_sha remote_ref remote_sha)
#
# Exit 0 = OK, 1 = policy violation.

range=""

if [ $# -ge 1 ]; then
    range="$1"
elif [ ! -t 0 ]; then
    while read -r local_ref local_sha remote_ref remote_sha; do
        if [ "$local_sha" = "0000000000000000000000000000000000000000" ]; then
            continue
        fi
        if [ "$remote_sha" = "0000000000000000000000000000000000000000" ]; then
            r="origin/main..$local_sha"
        else
            r="$remote_sha..$local_sha"
        fi
        if [ -z "$range" ]; then
            range="$r"
        else
            range="$range $r"
        fi
    done
fi

if [ -z "$range" ]; then
    range="origin/main..HEAD"
fi

# Gather commits across the (possibly multi-range) spec.
commits=""
for r in $range; do
    commits="$commits
$(git rev-list "$r" 2>/dev/null || true)"
done

commits=$(printf '%s\n' "$commits" | grep -v '^$' || true)
if [ -z "$commits" ]; then
    exit 0
fi

# Identify feature-bearing commits.
feature_commits=""
for sha in $commits; do
    subject=$(git log -1 --format='%s' "$sha" 2>/dev/null || echo "")
    if printf '%s' "$subject" | grep -qE '^(feat|feature|improve|enhance)([(:])'; then
        feature_commits="$feature_commits
  - $sha  $subject"
    fi
done

# Trim leading newline.
feature_commits=$(printf '%s' "$feature_commits" | sed -e '/^$/d')
if [ -z "$feature_commits" ]; then
    exit 0
fi

# Check that at least one new marker file was added in the same range(s).
new_entries=""
for r in $range; do
    new_entries="$new_entries
$(git diff --name-only --diff-filter=A "$r" -- 'docs/whats_new/entries/*.md' 2>/dev/null || true)"
done
new_entries=$(printf '%s\n' "$new_entries" | grep -v '^$' || true)

if [ -z "$new_entries" ]; then
    echo "ERROR (What's New policy): this push includes feature-bearing commits but no" >&2
    echo "new marker file under docs/whats_new/entries/ in the same push." >&2
    echo "" >&2
    echo "Feature commits:" >&2
    printf '%s\n' "$feature_commits" >&2
    echo "" >&2
    echo "How to fix:" >&2
    echo "  1. Add the entry in the deployed app at Admin -> What's New (/admin/whats-new.php)." >&2
    echo "  2. Add a marker file docs/whats_new/entries/YYYY-MM-DD-slug.md in this push," >&2
    echo "     using the template in docs/whats_new/README.md." >&2
    echo "  3. Retry the push." >&2
    echo "" >&2
    echo "Policy: docs/SYSTEM_SPECS.md section 1, docs/whats_new/README.md" >&2
    echo "Emergency bypass (NOT recommended): git push --no-verify" >&2
    exit 1
fi

echo "OK: feature commit(s) detected with What's New marker file(s):"
printf '%s\n' "$new_entries"
exit 0
