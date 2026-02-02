#!/usr/bin/env bash
set -euo pipefail

# Config that makes this script reusable outside of the bumblebee repo.
OWNER="emkowale"
REPO="vortex"
PLUGIN_SLUG="vortex"
MAIN_FILE="vortex.php"
REMOTE="origin"
REMOTE_URL="git@github.com:${OWNER}/${REPO}.git"
CHANGELOG_FILE="CHANGELOG.md"

# Usage: ./release.sh [patch|minor|major]
BUMP_TYPE="${1:-patch}"
if [[ ! "${BUMP_TYPE}" =~ ^(patch|minor|major)$ ]]; then
  echo "Usage: $0 [patch|minor|major]"
  exit 1
fi

PLUGIN_FILE=""
if [[ -f "${PLUGIN_SLUG}/${MAIN_FILE}" ]]; then
  PLUGIN_FILE="${PLUGIN_SLUG}/${MAIN_FILE}"
elif [[ -f "${MAIN_FILE}" ]]; then
  PLUGIN_FILE="${MAIN_FILE}"
else
  PLUGIN_FILE="$(grep -ril "Plugin Name:" . 2>/dev/null | head -n1 || true)"
  if [[ -z "${PLUGIN_FILE}" ]]; then
    echo "❌ No plugin file found with 'Plugin Name:' header"
    exit 1
  fi
  echo "ℹ️  Detected plugin file: ${PLUGIN_FILE}"
fi

# Clean malformed version-only lines in the header (no label/colon).
perl -0pi -e 'if (m#^/\\*\\*.*?\\*/#s) { my $h=$&; my $r=$h; $r =~ s/^\\s*\\*\\s*[^:\\n]*\\d+\\.\\d+[^:\\n]*\\n//mg; s/\\Q$h\\E/$r/s; }' "${PLUGIN_FILE}"

CURRENT_VERSION="$(grep -i "Version:" "${PLUGIN_FILE}" 2>/dev/null | head -n1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || true)"
if [[ -z "${CURRENT_VERSION}" ]]; then
  echo "⚠️  No valid Version: header found. Inserting one."
  # Insert Version header after Description (or Plugin URI if Description is missing)
  perl -0pi -e "if (s#(\\*\\s*Description:.*\\n)#\$1 * Version: 0.0.0\\n#i) { } elsif (s#(\\*\\s*Plugin URI:.*\\n)#\$1 * Version: 0.0.0\\n#i) { }" "${PLUGIN_FILE}"
  CURRENT_VERSION="$(grep -i "Version:" "${PLUGIN_FILE}" 2>/dev/null | head -n1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || true)"
  if [[ -z "${CURRENT_VERSION}" ]]; then
    echo "❌ Couldn't insert Version: into ${PLUGIN_FILE}"
    exit 1
  fi
fi

IFS='.' read -r MAJOR MINOR PATCH <<< "${CURRENT_VERSION}"
case "${BUMP_TYPE}" in
  patch)
    PATCH=$((PATCH+1))
    ;;
  minor)
    MINOR=$((MINOR+1))
    PATCH=0
    ;;
  major)
    MAJOR=$((MAJOR+1))
    MINOR=0
    PATCH=0
    ;;
esac

NEW_VERSION="${MAJOR}.${MINOR}.${PATCH}"

# Update plugin header version
perl -0pi -e "s/(Version:\s*)[0-9]+\.[0-9]+\.[0-9]+/\1${NEW_VERSION}/i" "${PLUGIN_FILE}"

# Update PLUGIN_VERSION constant if present
perl -0pi -e "s/(define\(\s*'PLUGIN_VERSION'\s*,\s*')[^']+(')/\1${NEW_VERSION}\2/" "${PLUGIN_FILE}" || true

# Update changelog (prepend new release section if missing)
DATE="$(date +%Y-%m-%d)"
if [[ ! -f "${CHANGELOG_FILE}" ]]; then
  cat > "${CHANGELOG_FILE}" <<'EOF'
# Changelog

All notable changes to this project will be documented in this file.
EOF
fi

if ! grep -q "^## ${NEW_VERSION} " "${CHANGELOG_FILE}"; then
  tmpfile="$(mktemp)"
  {
    printf "## %s - %s\n\n- Automated release.\n\n" "${NEW_VERSION}" "${DATE}"
    cat "${CHANGELOG_FILE}"
  } > "${tmpfile}"
  mv "${tmpfile}" "${CHANGELOG_FILE}"
fi

echo "🚀 Releasing $(basename "${PLUGIN_FILE}") v${NEW_VERSION}..."

git add "${PLUGIN_FILE}" "${CHANGELOG_FILE}" release.sh
git commit -m "Release v${NEW_VERSION}" >/dev/null 2>&1 || echo "⚠️  Nothing to commit"

git tag -f "v${NEW_VERSION}" -m "Release v${NEW_VERSION}"

if ! git remote get-url "${REMOTE}" >/dev/null 2>&1; then
  git remote add "${REMOTE}" "${REMOTE_URL}"
else
  git remote set-url "${REMOTE}" "${REMOTE_URL}" >/dev/null 2>&1
fi

git push "${REMOTE}" main || true
git push "${REMOTE}" "v${NEW_VERSION}" || true

echo "✅ Release v${NEW_VERSION} pushed. GitHub Actions will build and publish the zip."
