#!/usr/bin/env bash
#
# Builds the distributable plugin zip.
#
# This is the single source of truth for what ships. The release workflow calls
# this script rather than repeating the rules, so there is no way for CI and a
# local build to disagree.
#
# Output: repagio.zip, containing exactly one top-level folder,
# repagio/, which is the directory WordPress installs into. GitHub's own
# "Source code (zip)" unpacks to Owner-Repo-<sha>/ and would install to the
# wrong place, which is why we build our own.
#
# Exclusions come from .distignore and nowhere else.
#
# Usage:
#   bin/build-zip.sh            build the zip
#   bin/build-zip.sh --list     print the file list and stop, building nothing
#
set -euo pipefail

SLUG="repagio"

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
ROOT="$( cd "${SCRIPT_DIR}/.." && pwd )"
BUILD="${ROOT}/build"
STAGE="${BUILD}/${SLUG}"
ZIP="${ROOT}/${SLUG}.zip"
DISTIGNORE="${ROOT}/.distignore"

LIST_ONLY=0
if [ "${1:-}" = "--list" ]; then
	LIST_ONLY=1
fi

if [ ! -f "${DISTIGNORE}" ]; then
	echo "error: .distignore not found at ${DISTIGNORE}" >&2
	exit 1
fi

# ---------------------------------------------------------------------------
# Read the patterns.
#
# Blank lines and comments are ignored, matching rsync's --exclude-from and
# wp dist-archive.
# ---------------------------------------------------------------------------
PATTERNS=()
while IFS= read -r line || [ -n "${line}" ]; do
	line="${line%$'\r'}"                       # tolerate CRLF checkouts
	line="$( printf '%s' "${line}" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' )"

	case "${line}" in
		''|'#'*) continue ;;
	esac

	PATTERNS+=( "${line}" )
done < "${DISTIGNORE}"

# .git is always excluded whether or not the file says so.
PATTERNS+=( ".git" )

# ---------------------------------------------------------------------------
# Decide whether one repository-relative path is excluded.
#
# Semantics, chosen to match rsync --exclude-from:
#   /foo/   anchored to the repository root only
#   foo/    a directory named foo at any depth
#   foo     a path segment named foo at any depth
#   *.log   a glob, tested against each path segment
# ---------------------------------------------------------------------------
is_excluded() {
	local rel="$1"
	local pattern bare segment

	for pattern in "${PATTERNS[@]}"; do
		case "${pattern}" in
			/*)
				bare="${pattern#/}"
				bare="${bare%/}"

				if [ "${rel}" = "${bare}" ] || [ "${rel#"${bare}"/}" != "${rel}" ]; then
					return 0
				fi
				;;
			*)
				bare="${pattern%/}"

				local IFS='/'
				# shellcheck disable=SC2206 # deliberate word splitting on /
				local parts=( ${rel} )
				unset IFS

				for segment in "${parts[@]}"; do
					# shellcheck disable=SC2254 # the pattern is meant to glob
					case "${segment}" in
						${bare}) return 0 ;;
					esac
				done
				;;
		esac
	done

	return 1
}

# ---------------------------------------------------------------------------
# Collect what ships, sorted, so two runs always agree.
# ---------------------------------------------------------------------------
FILES=()
while IFS= read -r rel; do
	rel="${rel#./}"

	if is_excluded "${rel}"; then
		continue
	fi

	FILES+=( "${rel}" )
done < <( cd "${ROOT}" && find . -type f | sed 's|^\./||' | LC_ALL=C sort )

if [ "${#FILES[@]}" -eq 0 ]; then
	echo "error: every file was excluded; check .distignore" >&2
	exit 1
fi

if [ "${LIST_ONLY}" -eq 1 ]; then
	printf '%s\n' "${FILES[@]}"
	exit 0
fi

# ---------------------------------------------------------------------------
# Stage under the install directory name.
# ---------------------------------------------------------------------------
rm -rf "${BUILD}" "${ZIP}"
mkdir -p "${STAGE}"

for rel in "${FILES[@]}"; do
	mkdir -p "${STAGE}/$( dirname "${rel}" )"
	cp -p "${ROOT}/${rel}" "${STAGE}/${rel}"
done

# ---------------------------------------------------------------------------
# Refuse to ship something obviously wrong.
# ---------------------------------------------------------------------------
FAILED=0

for required in \
	"${SLUG}.php" \
	"readme.txt" \
	"uninstall.php" \
	"LICENSE" \
	"languages/${SLUG}.pot" \
	"admin/assets/admin.css" \
	"admin/assets/admin.js"
do
	if [ ! -f "${STAGE}/${required}" ]; then
		echo "error: ${required} is missing from the build" >&2
		FAILED=1
	fi
done

# Anything beginning with a dot, at any depth, is a hidden_files finding.
if find "${STAGE}" -name '.*' -mindepth 1 | grep -q .; then
	echo "error: a dotfile reached the build:" >&2
	find "${STAGE}" -name '.*' -mindepth 1 | sed "s|${STAGE}/|  |" >&2
	FAILED=1
fi

for unwanted in CLAUDE.md CONTRIBUTING.md README.md assets bin tests node_modules vendor; do
	if [ -e "${STAGE}/${unwanted}" ]; then
		echo "error: ${unwanted} should not be in the build" >&2
		FAILED=1
	fi
done

if [ "${FAILED}" -ne 0 ]; then
	exit 1
fi

# ---------------------------------------------------------------------------
# Archive. zip is used where present; otherwise Python's zipfile, which is on
# every GitHub runner and most developer machines. No project dependency is
# added either way.
#
# Files are added in the same sorted order regardless of archiver, so the
# listing is reproducible. Note that entry timestamps still reflect the files
# on disk, so the bytes are not identical between machines — the file list is.
# ---------------------------------------------------------------------------
if command -v zip >/dev/null 2>&1; then
	( cd "${BUILD}" && printf '%s\n' "${FILES[@]/#/${SLUG}/}" | zip -qX "${ZIP}" -@ )
	ARCHIVER="zip"
elif command -v python3 >/dev/null 2>&1 || command -v python >/dev/null 2>&1; then
	PY="$( command -v python3 || command -v python )"
	"${PY}" - "${BUILD}" "${ZIP}" "${SLUG}" <<'PYTHON'
import os
import sys
import zipfile

build, out, slug = sys.argv[1], sys.argv[2], sys.argv[3]
names = []

for root, dirs, files in os.walk(os.path.join(build, slug)):
    dirs.sort()
    for name in sorted(files):
        full = os.path.join(root, name)
        names.append((os.path.relpath(full, build).replace(os.sep, '/'), full))

names.sort()

with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as archive:
    for arcname, full in names:
        archive.write(full, arcname)

print('  archived %d files' % len(names))
PYTHON
	ARCHIVER="python zipfile"
else
	echo "error: neither zip nor python is available to create the archive" >&2
	exit 1
fi

echo
echo "Built ${ZIP##*/} with ${ARCHIVER}"
echo "Top-level folder: ${SLUG}/"
echo "Files: ${#FILES[@]}"
echo
printf '  %s\n' "${FILES[@]/#/${SLUG}/}"
