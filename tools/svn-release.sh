#!/usr/bin/env bash
# Выкладка плагина в каталог WordPress.org через SVN.
# Запускать из терминала: пароль SVN спросит сам (он отдельный от пароля сайта).
#
#   sudo apt install -y subversion     # один раз
#   ~/proj/ozon-pickup-points/tools/svn-release.sh
#
# Первый запуск сделает checkout, дальше будет только обновлять trunk и ставить тег.

set -euo pipefail

SVN_URL="https://plugins.svn.wordpress.org/sovetov-ozon-pickup-points"
SVN_USER="asovetov"
REPO="$(cd "$(dirname "$0")/.." && pwd)"
WORK="${WORK:-$HOME/svn/sovetov-ozon-pickup-points}"

MAIN="$REPO/sovetov-ozon-pickup-points.php"
VERSION="$(grep -m1 '^ \* Version:' "$MAIN" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '\r')"

if [ -z "$VERSION" ]; then
	echo "Не нашёл версию в $MAIN" >&2
	exit 1
fi

echo "Плагин: sovetov-ozon-pickup-points"
echo "Версия: $VERSION"
echo "Рабочая копия: $WORK"
echo

if [ ! -d "$WORK/.svn" ]; then
	echo "→ Первый запуск: делаю checkout (спросит логин $SVN_USER и пароль SVN)"
	mkdir -p "$(dirname "$WORK")"
	svn checkout "$SVN_URL" "$WORK" --username "$SVN_USER"
else
	echo "→ Обновляю рабочую копию"
	svn update "$WORK" --username "$SVN_USER"
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
	--exclude 'REVIEW.md' \
	--exclude 'README.md' \
	"$REPO/" "$WORK/trunk/"

# удалённые из репозитория файлы надо снять с учёта, иначе коммит ругнётся
if svn status "$WORK/trunk" | grep -q '^!'; then
	svn status "$WORK/trunk" | awk '/^!/{ $1=""; sub(/^ /,""); print }' | while IFS= read -r path; do
		svn rm --force "$path" >/dev/null
	done
fi

svn add --force "$WORK/trunk" >/dev/null

echo
echo "→ Что уедет в каталог:"
svn status "$WORK" | sed 's/^/   /' | head -60
echo

read -r -p "Коммитить trunk и ставить тег $VERSION? [y/N] " answer
case "$answer" in
	y|Y|да|Да) ;;
	*) echo "Отменено."; exit 0 ;;
esac

svn commit "$WORK" -m "Release $VERSION" --username "$SVN_USER"

if svn info "$SVN_URL/tags/$VERSION" >/dev/null 2>&1; then
	echo "Тег $VERSION уже есть, оставляю как есть."
else
	svn copy "$SVN_URL/trunk" "$SVN_URL/tags/$VERSION" \
		-m "Tag $VERSION" --username "$SVN_USER"
fi

echo
echo "Готово."
echo "Страница плагина: https://wordpress.org/plugins/sovetov-ozon-pickup-points/"
echo "Проверить версию: curl -s https://api.wordpress.org/plugins/info/1.0/sovetov-ozon-pickup-points.json | head -c 200"
