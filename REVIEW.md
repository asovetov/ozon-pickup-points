# Плагин в каталоге WordPress.org

## Статус

**Одобрен 26.11.2026.** Review ID: `APPROVED sovetov-ozon-pickup-points/asovetov/25Nov26/T3 26Nov26/4.3`.

- Каталог: https://wordpress.org/plugins/sovetov-ozon-pickup-points/
- SVN: https://plugins.svn.wordpress.org/sovetov-ozon-pickup-points
- Пользователь SVN: `asovetov`. Пароль SVN отдельный от пароля сайта:
  `profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password`
- Код готов к выкладке: версия **0.5.2**, главный файл и text domain — `sovetov-ozon-pickup-points`.

## Опубликовано 27.11.2026

- Версия 0.5.2 в каталоге: https://wordpress.org/plugins/sovetov-ozon-pickup-points/
- SVN: trunk — r3828857, тег `tags/0.5.2` — r3828858.
- Проверено: страница отдаёт 200, API каталога показывает `0.5.2`, архив
  `downloads.wordpress.org/plugin/sovetov-ozon-pickup-points.0.5.2.zip` скачивается (143 КБ).
- Иконка на странице пока стандартная (пиксельный квадрат) — нужны `assets/icon-256x256.png`
  и `assets/icon-128x128.png`, баннер `assets/banner-1544x500.png`.

## Выкладка в каталог

```bash
# пароль SVN в файл с правами 600 (в чат не писать)
install -m 600 /dev/null ~/.secrets/wp-svn
nano ~/.secrets/wp-svn

~/proj/ozon-pickup-points/tools/svn-release.sh   # ASSUME_YES=1 — без вопросов
```

Скрипт делает checkout в `~/svn/sovetov-ozon-pickup-points`, кладёт файлы в `trunk`,
показывает изменения, спрашивает подтверждение, коммитит и ставит тег версии.

Структура SVN: `trunk/` — файлы плагина **без вложенной папки**, `tags/<версия>/` — теги,
`assets/` — баннеры и иконки (не входят в zip плагина, заливаются отдельно).

Проверка после выкладки:

```bash
curl -s https://api.wordpress.org/plugins/info/1.0/sovetov-ozon-pickup-points.json | head -c 200
```

## Что нельзя забывать

- Скрытые файлы (`.gitignore`, `.editorconfig`, `.git`) — источник ошибок и в архиве, и в SVN.
- В `trunk` не кладём `dist/`, `docs/`, `node_modules/`, `REVIEW.md`, `README.md`, `tools/`.
- После смены версии: коммит, тег **и релиз на GitHub** — тег сам релиз не создаёт.
- Каталог работает как система релизов: только готовые версии, черновики — в git.

## Как дошло до одобрения

- Имя и слаг переведены с `3D-Club …` на `Sovetov Ozon Pickup Points for WooCommerce`:
  автор — человек, а не магазин.
- Владение доменом подтверждено TXT-записью на `sovetov.spb.ru`:
  `wordpressorg-asovetov-verification` (проверено на 8.8.8.8 и 1.1.1.1).
- В readme указаны оба хоста Ozon: `api-delivery.ozon.ru` (API) и `xapi.ozon.ru` (токен).
- Ловушка переименования: пока слаг не зарезервирован, text domain обязан совпадать
  со старым слагом, иначе автопроверка валит загрузку с `TextDomainMismatch`.
- Архив без скрытых файлов: `.gitignore` в первой попытке завалил загрузку (`hidden_files`).

## Статический анализ

phpcs + WordPress: 28 ошибок, 54 предупреждения — все стилевые (докблоки, короткие
тернарники, имена файлов классов, `ALTER TABLE` в двойных кавычках). Проблем безопасности
нет. Каталог это не блокирует, можно поправить отдельным проходом.
