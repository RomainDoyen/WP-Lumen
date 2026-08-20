#!/usr/bin/env bash
# Construit le zip installable Lumen pour GitHub Releases / WordPress.
# Staging rsync + .distignore (pas seulement git archive).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_SLUG="lumen-wp"
DIST_DIR="$ROOT/dist"
MAIN_FILE="$ROOT/lumen.php"

if [[ ! -f "$MAIN_FILE" ]]; then
	echo "❌ Plugin introuvable : $MAIN_FILE" >&2
	exit 1
fi

VERSION="$(
	grep -E "^\s*\*\s*Version:" "$MAIN_FILE" \
		| head -1 \
		| sed -E 's/.*Version:[[:space:]]*//' \
		| tr -d '[:space:]'
)"

if [[ -z "$VERSION" ]]; then
	echo "❌ Impossible de lire la Version dans lumen.php" >&2
	exit 1
fi

CONST_VERSION="$(
	grep -E "define\(\s*'LUMEN_WP_VERSION'" "$MAIN_FILE" \
		| head -1 \
		| sed -E "s/.*'([0-9.]+)'.*/\1/" \
		| tr -d '[:space:]'
)"

if [[ -n "$CONST_VERSION" && "$CONST_VERSION" != "$VERSION" ]]; then
	echo "⚠️  LUMEN_WP_VERSION ($CONST_VERSION) ≠ en-tête Version ($VERSION) — aligner lumen.php" >&2
fi

if [[ -n "$(git -C "$ROOT" status --porcelain 2>/dev/null || true)" ]]; then
	echo "⚠️  Working tree non propre — le ZIP reflète le disque (fichiers non commités inclus si non exclus)." >&2
fi

echo "📦 Build Lumen $VERSION"
mkdir -p "$DIST_DIR"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

STAGE="$TMP/$PLUGIN_SLUG"
mkdir -p "$STAGE"

echo "→ Copie des fichiers (respect .distignore)…"
DISTIGNORE="$ROOT/.distignore"
RSYNC_EXCLUDES=(
	--exclude '.git'
	--exclude 'dist'
	--exclude 'node_modules'
	--exclude 'tests'
	--exclude 'Tests'
	--exclude '.phpunit.result.cache'
)

if [[ -f "$DISTIGNORE" ]]; then
	while IFS= read -r line || [[ -n "$line" ]]; do
		line="${line%%#*}"
		# trim
		line="${line#"${line%%[![:space:]]*}"}"
		line="${line%"${line##*[![:space:]]}"}"
		[[ -z "$line" ]] && continue
		[[ "$line" == !* ]] && continue
		RSYNC_EXCLUDES+=( --exclude "$line" )
	done < "$DISTIGNORE"
fi

rsync -a \
	"${RSYNC_EXCLUDES[@]}" \
	"$ROOT/" "$STAGE/"

# Garde-fous contenu
if [[ ! -f "$STAGE/lumen.php" ]]; then
	echo "❌ Staging invalide : lumen.php manquant" >&2
	exit 1
fi

ZIP_VERSIONED="${PLUGIN_SLUG}-${VERSION}.zip"
ZIP_STABLE="${PLUGIN_SLUG}.zip"
ZIP_VERSIONED_PATH="$DIST_DIR/$ZIP_VERSIONED"
ZIP_STABLE_PATH="$DIST_DIR/$ZIP_STABLE"

rm -f "$ZIP_VERSIONED_PATH" "$ZIP_STABLE_PATH"

(
	cd "$TMP"
	zip -r -q "$ZIP_VERSIONED_PATH" "$PLUGIN_SLUG"
)

# Alias stable (compat workflow / docs existants)
cp -f "$ZIP_VERSIONED_PATH" "$ZIP_STABLE_PATH"

# Vérifications rapides
if ! unzip -p "$ZIP_STABLE_PATH" "${PLUGIN_SLUG}/lumen.php" >/dev/null 2>&1; then
	echo "❌ ZIP invalide : ${PLUGIN_SLUG}/lumen.php absent" >&2
	exit 1
fi

ZIP_VER="$(
	unzip -p "$ZIP_STABLE_PATH" "${PLUGIN_SLUG}/lumen.php" \
		| grep -E "^\s*\*\s*Version:" \
		| head -1 \
		| sed -E 's/.*Version:[[:space:]]*//' \
		| tr -d '[:space:]'
)"
if [[ "$ZIP_VER" != "$VERSION" ]]; then
	echo "❌ Version dans le ZIP ($ZIP_VER) ≠ attendue ($VERSION)" >&2
	exit 1
fi

# Pas de docs / superpowers dans le zip
if unzip -Z1 "$ZIP_STABLE_PATH" 2>/dev/null | grep -E -q '(^|/ )docs/|\.github/|bin/build-zip'; then
	echo "❌ ZIP contient des chemins exclus (docs/.github/bin)" >&2
	unzip -Z1 "$ZIP_STABLE_PATH" | grep -E 'docs/|\.github/|bin/' || true
	exit 1
fi

SIZE="$(du -h "$ZIP_STABLE_PATH" | awk '{print $1}')"
echo "✅ $ZIP_VERSIONED_PATH"
echo "✅ $ZIP_STABLE_PATH ($SIZE)"
echo "   Préfixe dossier : ${PLUGIN_SLUG}/"
echo "   Version          : ${VERSION}"
