<?php

namespace Catalog;

use PluginContext;

/**
 * Регистрирует настраиваемые поля разделов в редакторе страницы ядра.
 * Один ключ поля может встречаться в нескольких разделах — условия section объединяются.
 */
final class FieldsRegistrar
{
    public function __construct(
        private PluginContext $plugin,
        private Settings $settings,
    ) {
    }

    public function register(): void
    {
        /** @var array<string, array<string, mixed>> $byKey */
        $byKey = [];
        foreach ($this->settings->sections() as $sectionSlug => $section) {
            foreach ($section['fields'] as $field) {
                $key = $field['key'];
                if (!isset($byKey[$key])) {
                    $byKey[$key] = [
                        'type' => $field['type'],
                        'label' => $field['label'],
                        'options' => $field['options'],
                        'sections' => [],
                    ];
                } elseif ($byKey[$key]['type'] !== $field['type']) {
                    throw new \LogicException('Поле «' . $key . '» объявлено с разными типами в настройках каталога');
                }
                $byKey[$key]['sections'][] = $sectionSlug;
            }
        }

        foreach ($byKey as $key => $field) {
            $spec = [
                'type' => $field['type'],
                'label' => $field['label'],
                'placement' => 'aside',
                'show_when' => [
                    'section' => array_values(array_unique($field['sections'])),
                ],
            ];
            if ($field['type'] === 'select' && $field['options'] !== []) {
                $spec['options'] = $field['options'];
            }
            $this->plugin->registerPageField($key, $spec);
        }
    }
}
