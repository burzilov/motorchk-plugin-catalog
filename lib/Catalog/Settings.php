<?php

namespace Catalog;

use PluginContext;
use PluginDataException;

/**
 * Настройки каталога: plugins-data/catalog/settings.json.
 * Разделы и поля карточек задаёт администратор — встроенных полей под клиента нет.
 */
final class Settings
{
    public const FILE = 'settings.json';

    public const FIELD_TYPES = ['text', 'textarea', 'number', 'url', 'select', 'checkbox'];

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    private ?string $error = null;

    public function __construct(private PluginContext $plugin)
    {
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'image' => [
                'large' => 1200,
                'small' => 480,
                'quality' => 82,
            ],
            'sections' => new \stdClass(),
        ];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $defaults = self::defaults();
        $defaults['sections'] = [];
        $this->data = $defaults;
        try {
            $decoded = $this->plugin->readJsonStrict(self::FILE, null);
        } catch (PluginDataException $e) {
            $this->error = $e->getMessage() . ' Действуют настройки по умолчанию; сохранение формы перезапишет файл.';

            return $this->data;
        }

        if ($decoded === null) {
            return $this->data;
        }

        [$clean, $errors] = self::validate($decoded);
        $this->data = $clean ?? $defaults;
        if ($errors !== []) {
            $this->error = 'Файл настроек содержит ошибки (' . implode('; ', $errors) . ') — действуют настройки по умолчанию.';
        }

        return $this->data;
    }

    public function error(): ?string
    {
        $this->all();

        return $this->error;
    }

    /**
     * @return array{large: int, small: int, quality: int}
     */
    public function image(): array
    {
        $img = $this->all()['image'];

        return [
            'large' => (int) $img['large'],
            'small' => (int) $img['small'],
            'quality' => (int) $img['quality'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>> slug раздела → настройки
     */
    public function sections(): array
    {
        return $this->all()['sections'];
    }

    public function section(string $slug): ?array
    {
        return $this->sections()[$slug] ?? null;
    }

    /**
     * Раздел, к которому относится страница (сама страница-раздел или её родитель из списка sections).
     */
    public function sectionForPage(string $slug, ?string $parent): ?array
    {
        $sections = $this->sections();
        if (isset($sections[$slug])) {
            return $sections[$slug] + ['_slug' => $slug];
        }
        if ($parent !== null && $parent !== '' && isset($sections[$parent])) {
            return $sections[$parent] + ['_slug' => $parent];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: ?array<string, mixed>, 1: list<string>}
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $imageIn = is_array($input['image'] ?? null) ? $input['image'] : [];
        $large = (int) ($imageIn['large'] ?? 1200);
        $small = (int) ($imageIn['small'] ?? 480);
        $quality = (int) ($imageIn['quality'] ?? 82);
        if ($large < 100 || $large > 4000) {
            $errors[] = 'image.large — от 100 до 4000';
        }
        if ($small < 50 || $small > 2000 || $small > $large) {
            $errors[] = 'image.small — от 50 до large';
        }
        if ($quality < 40 || $quality > 100) {
            $errors[] = 'image.quality — от 40 до 100';
        }

        $sections = [];
        $rawSections = $input['sections'] ?? [];
        if ($rawSections instanceof \stdClass) {
            $rawSections = (array) $rawSections;
        }
        if (!is_array($rawSections)) {
            return [null, ['sections — объект']];
        }

        foreach ($rawSections as $slug => $section) {
            if (!is_string($slug) || !preg_match('#^[a-z0-9]+(?:/[a-z0-9-]+)*$#', $slug)) {
                $errors[] = 'некорректный slug раздела';
                continue;
            }
            if (!is_array($section)) {
                $errors[] = 'раздел «' . $slug . '» — объект';
                continue;
            }
            $template = trim((string) ($section['template'] ?? 'product'));
            if ($template === '' || !preg_match('/^[a-z0-9_-]+$/', $template)) {
                $errors[] = 'раздел «' . $slug . '»: template';
                continue;
            }
            $mediaDir = trim((string) ($section['media_dir'] ?? ('products/' . str_replace('/', '-', $slug))));
            $mediaDir = trim(str_replace('\\', '/', $mediaDir), '/');
            if ($mediaDir === '' || !\PluginManager::isSafeRelativePath($mediaDir)) {
                $errors[] = 'раздел «' . $slug . '»: media_dir';
                continue;
            }
            $label = self::normalizeLabel($section['label'] ?? $slug, $errors, 'раздел «' . $slug . '».label');
            $fields = [];
            $rawFields = $section['fields'] ?? [];
            if (!is_array($rawFields)) {
                $errors[] = 'раздел «' . $slug . '»: fields — массив';
                continue;
            }
            $seen = [];
            foreach ($rawFields as $field) {
                if (!is_array($field)) {
                    $errors[] = 'раздел «' . $slug . '»: элемент fields';
                    continue;
                }
                $key = (string) ($field['key'] ?? '');
                if (!preg_match('/^[a-z][a-z0-9_]*$/', $key) || \PageFields::isReserved($key) || isset($seen[$key])) {
                    $errors[] = 'раздел «' . $slug . '»: ключ поля «' . $key . '»';
                    continue;
                }
                $type = (string) ($field['type'] ?? 'text');
                if (!in_array($type, self::FIELD_TYPES, true)) {
                    $errors[] = 'раздел «' . $slug . '»: тип поля «' . $key . '»';
                    continue;
                }
                $fieldLabel = self::normalizeLabel($field['label'] ?? $key, $errors, 'поле «' . $key . '».label');
                $options = [];
                if ($type === 'select') {
                    $rawOpts = $field['options'] ?? [];
                    if (!is_array($rawOpts) || $rawOpts === []) {
                        $errors[] = 'поле «' . $key . '»: нужны options';
                        continue;
                    }
                    foreach ($rawOpts as $optValue => $optLabel) {
                        $options[(string) $optValue] = is_string($optLabel) || is_array($optLabel)
                            ? $optLabel
                            : (string) $optLabel;
                    }
                }
                $seen[$key] = true;
                $fields[] = [
                    'key' => $key,
                    'type' => $type,
                    'label' => $fieldLabel ?? $key,
                    'options' => $options,
                ];
            }

            $sections[$slug] = [
                'label' => $label ?? $slug,
                'template' => $template,
                'media_dir' => $mediaDir,
                'fields' => $fields,
            ];
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        return [[
            'image' => [
                'large' => $large,
                'small' => $small,
                'quality' => $quality,
            ],
            'sections' => $sections,
        ], []];
    }

    /**
     * @param array<string, mixed> $data уже проверенные настройки
     */
    public function save(array $data): void
    {
        [$clean, $errors] = self::validate($data);
        if ($clean === null) {
            throw new \InvalidArgumentException(implode('; ', $errors));
        }
        // JSON-объект, а не [] при пустых разделах
        $payload = $clean;
        $payload['sections'] = $clean['sections'] === [] ? new \stdClass() : $clean['sections'];
        $this->plugin->writeJson(self::FILE, $payload);
        $this->data = $clean;
        $this->error = null;
    }

    /**
     * @param list<string> $errors
     * @return array<string, string>|string|null
     */
    private static function normalizeLabel(mixed $label, array &$errors, string $what): array|string|null
    {
        if (is_string($label)) {
            $label = trim($label);
            if ($label === '' || mb_strlen($label) > 120) {
                $errors[] = $what;

                return null;
            }

            return $label;
        }
        if (!is_array($label) || $label === [] || array_is_list($label)) {
            $errors[] = $what;

            return null;
        }
        $out = [];
        foreach ($label as $locale => $text) {
            if (!is_string($locale) || !is_string($text)) {
                $errors[] = $what;

                return null;
            }
            $text = trim($text);
            if ($text === '') {
                continue;
            }
            $out[$locale] = $text;
        }
        if ($out === []) {
            $errors[] = $what;

            return null;
        }

        return $out;
    }
}
