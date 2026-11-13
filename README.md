# Каталог (catalog)

Публичный плагин Motorchk CMS: галерея страниц, доска разделов каталога и настраиваемые поля карточек. **Не содержит** полей под конкретного клиента — всё задаётся в `plugins-data/catalog/settings.json`.

Требует ядро **≥ 0.8.5** (API `page_fields` / `pages()->updateMeta`).

## Установка

1. Распакуйте архив `catalog-X.Y.Z.zip` в `public_html/plugins/catalog/` (папка `catalog/` с `plugin.json` внутри).
2. Убедитесь, что на сервере есть **PHP GD с WebP** или **ImageMagick** (`magick` / `convert`) — иначе загрузка фото в админке недоступна, чтение существующих `images:` работает.
3. Откройте **Админка → Каталог → Настройки** и задайте разделы (или положите готовый `settings.json` в `plugins-data/catalog/`).

Плагин читает уже существующие поля `images:`, `group`, `order` без миграции файлов.

## Настройки

Файл: `plugins-data/catalog/settings.json`.

| Ключ | Назначение |
|------|------------|
| `image.large` | Сторона большого WebP (по умолчанию 1200) |
| `image.small` | Сторона `-sm.webp` (480) |
| `image.quality` | Качество WebP 40–100 (82) |
| `sections.{slug}` | Раздел каталога: страница-список с этим slug |

У раздела:

- `label` — строка или `{"ru":"…","en":"…"}`
- `template` — шаблон новых страниц (например `product`)
- `media_dir` — каталог внутри `media/` для загрузок
- `fields` — массив полей карточки: `key`, `type` (`text`\|`textarea`\|`number`\|`url`\|`select`\|`checkbox`), `label`, для `select` — `options`

Поля попадают в редактор страницы ядра, если страница — сам раздел или его прямой потомок. Значения пишутся в front matter под `key`.

Галерея пишет массив путей в `images:` (без префикса `/media/`, например `products/accessories/ng1-1.webp`). Первый элемент — главный кадр; при изменении галереи устаревшее поле `image` снимается, чтобы карточка темы брала `images[0]` → `-sm.webp`.

## Админка

- **Каталог** — список настроенных разделов.
- **Раздел** — ряды (`group`) и порядок (`order`), перетаскивание, создание черновика с `parent` = slug раздела, переключатель «Опубликовано».
- **Редактор страницы** — панель «Галерея» и поля из настроек раздела.

Удаление фото из галереи по умолчанию **только убирает путь из front matter**. Физическое удаление файлов — отдельный флаг `delete_files` в API (в UI по умолчанию выключен).

## Пример для Миклина

```json
{
  "image": { "large": 1200, "small": 480, "quality": 82 },
  "sections": {
    "equipment/accessories": {
      "label": { "ru": "Аксессуары", "en": "Accessories" },
      "template": "product",
      "media_dir": "products/accessories",
      "fields": [
        {
          "key": "customizer",
          "type": "url",
          "label": { "ru": "Ссылка на конструктор", "en": "Customizer link" }
        }
      ]
    },
    "equipment/motors": {
      "label": { "ru": "Моторы", "en": "Motors" },
      "template": "product",
      "media_dir": "products/motors",
      "fields": [
        {
          "key": "customizer",
          "type": "url",
          "label": { "ru": "Ссылка на конструктор", "en": "Customizer link" }
        }
      ]
    }
  }
}
```

Тема Миклина уже читает `customizer`, `images`, `group`, `order` — менять шаблоны не нужно.

## Тесты

```bash
php tests/run.php /path/to/motorchk/core
```

## Релиз

```bash
./release.sh          # тег vX.Y.Z и zip в dist/
./release.sh --push   # + push (когда появится origin)
```
