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

# Normalize line endings to LF, then strip control chars (e.g., stray backspace).
perl -0pi -e 's/\r\n/\n/g; s/\r/\n/g; s/[\x00-\x08\x0B\x0C\x0E-\x1F]//g' "${PLUGIN_FILE}"

# Ensure a proper Version: line exists and remove malformed version-only lines.
tmpfile="$(mktemp)"
if ! awk -v insertver="0.0.0" '
function is_malformed(line) { return line ~ /^[[:space:]]*\*[[:space:]]*\.?[0-9]+\.[0-9]+(\.[0-9]+)?[[:space:]]*$/ }
BEGIN{in_header=0; hcount=0}
{
  if (!in_header) {
    if ($0 ~ /^\/\*\*/) { in_header=1; hcount=0; header[++hcount]=$0; next }
    print; next
  }
  header[++hcount]=$0
  if ($0 ~ /^[[:space:]]*\*\//) {
    has=0; insertAfter=0; desc=0; puri=0; pname=0;
    for (i=1;i<=hcount;i++) {
      line=header[i]
      if (line ~ /^[[:space:]]*\*[[:space:]]*Version:/) { has=1 }
      if (!desc && line ~ /^[[:space:]]*\*[[:space:]]*Description:/) desc=i
      if (!puri && line ~ /^[[:space:]]*\*[[:space:]]*Plugin URI:/) puri=i
      if (!pname && line ~ /^[[:space:]]*\*[[:space:]]*Plugin Name:/) pname=i
    }
    if (desc) insertAfter=desc; else if (puri) insertAfter=puri; else if (pname) insertAfter=pname
    for (i=1;i<=hcount;i++) {
      line=header[i]
      if (is_malformed(line)) { continue }
      print line
      if (!has && insertAfter==i) {
        print " * Version:     " insertver
        has=1
      }
    }
    in_header=0
  }
  next
}
END{
  if (in_header) { for (i=1;i<=hcount;i++) print header[i] }
}
' "${PLUGIN_FILE}" > "${tmpfile}"; then
  rm -f "${tmpfile}"
  echo "❌ Failed to normalize header in ${PLUGIN_FILE}"
  exit 1
fi
mv "${tmpfile}" "${PLUGIN_FILE}"

CURRENT_VERSION="$(awk 'BEGIN{v=""} /^[[:space:]]*\*[[:space:]]*Version:/ { if (match($0, /[0-9]+\.[0-9]+\.[0-9]+/)) { v=substr($0,RSTART,RLENGTH); exit } } END{print v}' "${PLUGIN_FILE}")"
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

# Update plugin header version (and strip malformed version-only lines).
tmpfile="$(mktemp)"
if ! awk -v newver="${NEW_VERSION}" '
function is_malformed(line) { return line ~ /^[[:space:]]*\*[[:space:]]*\.?[0-9]+\.[0-9]+(\.[0-9]+)?[[:space:]]*$/ }
BEGIN{in_header=0; hcount=0}
{
  if (!in_header) {
    if ($0 ~ /^\/\*\*/) { in_header=1; hcount=0; header[++hcount]=$0; next }
    print; next
  }
  header[++hcount]=$0
  if ($0 ~ /^[[:space:]]*\*\//) {
    for (i=1;i<=hcount;i++) {
      line=header[i]
      if (is_malformed(line)) { continue }
      if (line ~ /^[[:space:]]*\*[[:space:]]*Version:/) {
        print " * Version:     " newver
        continue
      }
      print line
    }
    in_header=0
  }
  next
}
END{
  if (in_header) { for (i=1;i<=hcount;i++) print header[i] }
}
' "${PLUGIN_FILE}" > "${tmpfile}"; then
  rm -f "${tmpfile}"
  echo "❌ Failed to update Version header in ${PLUGIN_FILE}"
  exit 1
fi
mv "${tmpfile}" "${PLUGIN_FILE}"

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
