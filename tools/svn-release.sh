#!/usr/bin/env bash
# Выкладка плагина в каталог WordPress.org через SVN.
#
# Пароль SVN отдельный от пароля сайта: profiles.wordpress.org → Account & Security → SVN password.
#
# Два режима:
#   1. Пароль лежит в файле (по умолчанию ~/.secrets/wp-svn, права 600) — всё идёт без вопросов:
#        ~/proj/ozon-pickup-points/tools/svn-release.sh
#   2. Файла нет — svn спросит пароль сам, в интерактивном терминале.
#
# Переменные: ASSUME_YES=1 — не спрашивать подтверждение; SVN_PASS_FILE=путь — свой файл с паролем.

set -euo pipefail

SVN_URL="https://plugins.svn.wordpress.org/sovetov-ozon-pickup-points"
SVN_USER="asovetov"
REPO="$(cd "$(dirname "$0")/.." && pwd)"
WORK="${WORK:-$HOME/svn/sovetov-ozon-pickup-points}"
PASS_FILE="${SVN_PASS_FILE:-$HOME/.secrets/wp-svn}"

MAIN="$REPO/sovetov-ozon-pickup-points.php"
VERSION="$(grep -m1 '^ \* Version:' "$MAIN" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '\r')"

if [ -z "$VERSION" ]; then
	echo "Не нашёл версию в $MAIN" >&2
	exit 1
fi

echo "Плагин: sovetov-ozon-pickup-points"
echo "Версия: $VERSION"
echo "Рабочая копия: $WORK"
if [ -s "$PASS_FILE" ]; then
	echo "Пароль SVN: из $PASS_FILE"
else
	echo "Пароль SVN: файла нет, спрошу интерактивно"
fi
echo

# Чтение в каталоге публичное — checkout и update пароль не требуют.
if [ ! -d "$WORK/.svn" ]; then
	echo "→ Первый запуск: checkout"
	mkdir -p "$(dirname "$WORK")"
	svn checkout "$SVN_URL" "$WORK"
else
	echo "→ Обновляю рабочую копию"
	svn update "$WORK"
fi

mkdir -p "$WORK/trunk" "$WORK/tags"

echo "→ Кладу файлы плагина в trunk"
rsync -a --delete \
	--exclude '.git' \
	--exclude '.github' \
	--exclude '.gitignore' \
	--exclude '.editorconfig' \
	--exclude 'dist' \
	--exclude 'docs' \
	--exclude 'node_modules' \
	--exclude 'svn-tree' \
	--exclude 'tools' \
	--exclude 'wp-assets' \
	--exclude 'REVIEW.md' \
	--exclude 'README.md' \
	"$REPO/" "$WORK/trunk/"

# файлы, которых больше нет в репозитории, надо снять с учёта, иначе коммит ругнётся
if svn status "$WORK/trunk" | grep -q '^!'; then
	svn status "$WORK/trunk" | awk '/^!/{ $1=""; sub(/^ /,""); print }' | while IFS= read -r path; do
		svn rm --force "$path" >/dev/null
	done
fi

svn add --force "$WORK/trunk" >/dev/null

# баннеры и иконки каталога живут в assets/ и в архив плагина не входят
if [ -d "$REPO/wp-assets" ] && [ -n "$(ls -A "$REPO/wp-assets" 2>/dev/null)" ]; then
	echo "→ Кладу иконки и баннеры в assets/"
	mkdir -p "$WORK/assets"
	rsync -a --delete "$REPO/wp-assets/" "$WORK/assets/"
	svn add --force "$WORK/assets" >/dev/null
fi

echo
echo "→ Что уедет в каталог:"
svn status "$WORK" | sed 's/^/   /' | head -60
echo

if [ "${ASSUME_YES:-0}" != "1" ]; then
	read -r -p "Коммитить trunk и ставить тег $VERSION? [y/N] " answer
	case "$answer" in
		y|Y|да|Да) ;;
		*) echo "Отменено."; exit 0 ;;
	esac
fi

echo "→ Коммичу trunk"
if [ -s "$PASS_FILE" ]; then
	svn commit "$WORK" -m "Release $VERSION" \
		--username "$SVN_USER" --non-interactive --no-auth-cache --password-from-stdin < "$PASS_FILE"
else
	svn commit "$WORK" -m "Release $VERSION" --username "$SVN_USER"
fi

if svn info "$SVN_URL/tags/$VERSION" >/dev/null 2>&1; then
	echo "Тег $VERSION уже есть, оставляю как есть."
else
	echo "→ Ставлю тег $VERSION"
	if [ -s "$PASS_FILE" ]; then
		svn copy "$SVN_URL/trunk" "$SVN_URL/tags/$VERSION" -m "Tag $VERSION" \
			--username "$SVN_USER" --non-interactive --no-auth-cache --password-from-stdin < "$PASS_FILE"
	else
		svn copy "$SVN_URL/trunk" "$SVN_URL/tags/$VERSION" -m "Tag $VERSION" --username "$SVN_USER"
	fi
fi

echo
echo "Готово."
echo "Страница: https://wordpress.org/plugins/sovetov-ozon-pickup-points/"
