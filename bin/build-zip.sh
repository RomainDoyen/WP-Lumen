#!/usr/bin/env bash
# Build lumen-wp.zip without .git (via git archive).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	echo "Erreur : ce dossier n’est pas un dépôt git." >&2
	exit 1
fi

VERSION="$(grep -E "^\s*\*\s*Version:" lumen.php | head -1 | sed -E 's/.*Version:[[:space:]]*//')"
VERSION="${VERSION:-dev}"
REF="${1:-HEAD}"
OUT_DIR="${ROOT}/dist"
OUT_FILE="${OUT_DIR}/lumen-wp.zip"

if [[ -n "$(git status --porcelain 2>/dev/null || true)" ]]; then
	echo "Attention : working tree non commitée — le ZIP ne contient que « ${REF} »." >&2
fi

mkdir -p "$OUT_DIR"
rm -f "$OUT_FILE"

git archive --format=zip --prefix=lumen-wp/ -o "$OUT_FILE" "$REF"

SIZE="$(wc -c < "$OUT_FILE" | tr -d ' ')"
echo "OK — ${OUT_FILE}"
echo "     version plugin : ${VERSION}"
echo "     ref git        : ${REF}"
echo "     taille         : ${SIZE} octets"
