<?php

namespace Catalog;

use PluginContext;
use Slugifier;

/**
 * Доска раздела: группы (group) и порядок (order) дочерних страниц.
 */
final class SectionBoard
{
    public function __construct(private PluginContext $plugin)
    {
    }

    /**
     * @return list<array{group: int, pages: list<array<string, mixed>>}>
     */
    public function rows(string $sectionSlug): array
    {
        $children = $this->plugin->pages()->children($sectionSlug);
        $groups = [];
        foreach ($children as $page) {
            $fm = $page['front_matter'];
            $g = (int) ($fm['group'] ?? 0);
            $groups[$g][] = [
                'slug' => (string) ($fm['slug'] ?? ''),
                'title' => (string) ($fm['title'] ?? ''),
                'order' => (int) ($fm['order'] ?? 0),
                'group' => $g,
                'published' => !empty($fm['published']),
                'thumb' => $this->thumb($fm),
            ];
        }
        ksort($groups, SORT_NUMERIC);
        $rows = [];
        foreach ($groups as $group => $pages) {
            usort($pages, static fn(array $a, array $b): int => $a['order'] <=> $b['order'] ?: strcmp($a['title'], $b['title']));
            $rows[] = ['group' => (int) $group, 'pages' => $pages];
        }

        return $rows;
    }

    /**
     * Применить раскладку: список рядов, в каждом — slug'и в порядке.
     * Новые номера group — 0,1,2…; order внутри ряда — 0,10,20…
     *
     * @param list<list<string>> $rows
     */
    public function applyLayout(string $sectionSlug, array $rows): void
    {
        $allowed = [];
        foreach ($this->plugin->pages()->children($sectionSlug) as $page) {
            $slug = (string) ($page['front_matter']['slug'] ?? '');
            if ($slug !== '') {
                $allowed[$slug] = true;
            }
        }

        $seen = [];
        $groupIndex = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $order = 0;
            $has = false;
            foreach ($row as $slug) {
                if (!is_string($slug) || $slug === '' || !isset($allowed[$slug]) || isset($seen[$slug])) {
                    continue;
                }
                $this->plugin->pages()->updateMeta($slug, [
                    'group' => $groupIndex,
                    'order' => $order,
                ]);
                $seen[$slug] = true;
                $order += 10;
                $has = true;
            }
            if ($has) {
                $groupIndex++;
            }
        }

        // Страницы, не попавшие в раскладку, уходят в новый ряд в конце
        if (count($seen) < count($allowed)) {
            $order = 0;
            foreach (array_keys($allowed) as $slug) {
                if (isset($seen[$slug])) {
                    continue;
                }
                $this->plugin->pages()->updateMeta($slug, [
                    'group' => $groupIndex,
                    'order' => $order,
                ]);
                $order += 10;
            }
        }
    }

    public function setPublished(string $slug, bool $published): void
    {
        $this->plugin->pages()->updateMeta($slug, ['published' => $published]);
    }

    public function createPage(string $sectionSlug, string $title, string $leafSlug, string $template, int $group = 0): string
    {
        $title = trim($title);
        $leafSlug = trim($leafSlug);
        if ($title === '') {
            throw new \InvalidArgumentException('Укажите заголовок');
        }
        if (!Slugifier::isValidLeaf($leafSlug)) {
            throw new \InvalidArgumentException('Некорректный slug');
        }
        $full = $this->plugin->pages()->create($title, $leafSlug, $sectionSlug, $template);
        $maxOrder = -10;
        foreach ($this->plugin->pages()->children($sectionSlug) as $page) {
            $fm = $page['front_matter'];
            if ((int) ($fm['group'] ?? 0) === $group) {
                $maxOrder = max($maxOrder, (int) ($fm['order'] ?? 0));
            }
        }
        $this->plugin->pages()->updateMeta($full, [
            'group' => $group,
            'order' => $maxOrder + 10,
            'published' => false,
        ]);

        return $full;
    }

    /**
     * @param array<string, mixed> $fm
     */
    private function thumb(array $fm): ?string
    {
        $image = (string) ($fm['image'] ?? '');
        if ($image !== '') {
            $rel = Gallery::normalizeStoredPath($image);

            return $rel !== null ? \Media::url($rel) : null;
        }
        $images = Gallery::imagesFrom($fm);
        if ($images === []) {
            return null;
        }

        return \Media::url(Gallery::thumbPath($images[0]));
    }
}
