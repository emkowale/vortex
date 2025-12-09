#!/usr/bin/env bash
set -euo pipefail

# Config that makes this script reusable outside of the bumblebee repo.
OWNER="emkowale"
REPO="vortex"
PLUGIN_SLUG="vortex"
MAIN_FILE="vortex.php"
REMOTE="origin"
REMOTE_URL="git@github.com:${OWNER}/${REPO}.git"

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

CURRENT_VERSION="$(grep -i "Version:" "${PLUGIN_FILE}" 2>/dev/null | head -n1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || true)"
if [[ -z "${CURRENT_VERSION}" ]]; then
  echo "❌ Couldn't parse Version: from ${PLUGIN_FILE}"
  exit 1
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

echo "🚀 Releasing $(basename "${PLUGIN_FILE}") v${NEW_VERSION}..."

git add "${PLUGIN_FILE}" release.sh
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
