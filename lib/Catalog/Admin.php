<?php

namespace Catalog;

use PluginContext;
use PluginRequest;

final class Admin
{
    public function __construct(
        private PluginContext $plugin,
        private Settings $settings,
        private Gallery $gallery,
        private SectionBoard $board,
    ) {
    }

    public function index(PluginRequest $request): void
    {
        $this->plugin->render('admin', [
            'section' => 'index',
            'sections' => $this->settings->sections(),
            'settings_error' => $this->settings->error(),
            'image_caps' => ImageProcessor::capabilities(),
            'saved' => $request->query('saved') === '1',
        ], [
            'title' => 'Каталог',
            'styles' => [$this->plugin->assetUrl('admin.css')],
            'scripts' => [$this->plugin->assetUrl('admin.js')],
        ]);
    }

    public function settingsGet(PluginRequest $request): void
    {
        $this->plugin->render('admin', [
            'section' => 'settings',
            'settings' => $this->settings->all(),
            'settings_error' => $this->settings->error(),
            'form_error' => null,
            'raw_json' => json_encode($this->exportSettings($this->settings->all()), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'image_caps' => ImageProcessor::capabilities(),
        ], [
            'title' => 'Каталог — настройки',
            'styles' => [$this->plugin->assetUrl('admin.css')],
        ]);
    }

    public function settingsPost(PluginRequest $request): void
    {
        $raw = (string) $request->input('settings_json', '');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->plugin->render('admin', [
                'section' => 'settings',
                'settings' => $this->settings->all(),
                'settings_error' => $this->settings->error(),
                'form_error' => 'Некорректный JSON',
                'raw_json' => $raw,
                'image_caps' => ImageProcessor::capabilities(),
            ], [
                'title' => 'Каталог — настройки',
                'styles' => [$this->plugin->assetUrl('admin.css')],
                'status' => 422,
            ]);
            return;
        }
        try {
            $this->settings->save($decoded);
        } catch (\InvalidArgumentException $e) {
            $this->plugin->render('admin', [
                'section' => 'settings',
                'settings' => $this->settings->all(),
                'settings_error' => $this->settings->error(),
                'form_error' => $e->getMessage(),
                'raw_json' => $raw,
                'image_caps' => ImageProcessor::capabilities(),
            ], [
                'title' => 'Каталог — настройки',
                'styles' => [$this->plugin->assetUrl('admin.css')],
                'status' => 422,
            ]);
            return;
        }
        $this->plugin->redirect($this->plugin->adminUrl('/settings?saved=1'));
    }

    public function sectionGet(PluginRequest $request): void
    {
        $slug = rawurldecode((string) $request->param('slug'));
        $section = $this->settings->section($slug);
        if ($section === null) {
            $this->plugin->render('admin', [
                'section' => 'missing',
                'message' => 'Раздел не настроен в плагине Каталог',
            ], ['title' => 'Каталог', 'styles' => [$this->plugin->assetUrl('admin.css')], 'status' => 404]);
            return;
        }
        $this->plugin->render('admin', [
            'section' => 'board',
            'section_slug' => $slug,
            'section_meta' => $section,
            'rows' => $this->board->rows($slug),
            'created' => $request->query('created'),
            'saved' => $request->query('saved') === '1',
        ], [
            'title' => 'Каталог — ' . $this->label($section['label']),
            'styles' => [$this->plugin->assetUrl('admin.css')],
            'scripts' => [$this->plugin->assetUrl('admin.js')],
        ]);
    }

    public function sectionLayout(PluginRequest $request): void
    {
        $slug = rawurldecode((string) $request->param('slug'));
        if ($this->settings->section($slug) === null) {
            $this->plugin->jsonError('Раздел не найден', 404);
            return;
        }
        $layout = $request->input('layout');
        if (is_string($layout)) {
            $layout = json_decode($layout, true);
        }
        if (!is_array($layout)) {
            $this->plugin->jsonError('Некорректная раскладка');
            return;
        }
        $this->board->applyLayout($slug, $layout);
        $this->plugin->json(['ok' => true, 'rows' => $this->board->rows($slug)]);
    }

    public function sectionCreate(PluginRequest $request): void
    {
        $slug = rawurldecode((string) $request->param('slug'));
        $section = $this->settings->section($slug);
        if ($section === null) {
            $this->plugin->jsonError('Раздел не найден', 404);
            return;
        }
        try {
            $created = $this->board->createPage(
                $slug,
                (string) $request->input('title', ''),
                (string) $request->input('leaf_slug', ''),
                (string) ($section['template'] ?? 'product'),
                (int) $request->input('group', 0),
            );
        } catch (\Throwable $e) {
            $this->plugin->jsonError($e->getMessage());
            return;
        }
        if ($request->wantsJson() || $request->header('HX-Request')) {
            $this->plugin->json(['ok' => true, 'slug' => $created, 'rows' => $this->board->rows($slug)]);
            return;
        }
        $this->plugin->redirect($this->plugin->adminUrl('/sections/' . rawurlencode($slug) . '?created=' . rawurlencode($created)));
    }

    public function sectionPublish(PluginRequest $request): void
    {
        $slug = rawurldecode((string) $request->param('slug'));
        $pageSlug = (string) $request->input('page', '');
        $published = !empty($request->input('published'));
        $page = $this->plugin->pages()->load($pageSlug);
        if ($page === null || (($page['front_matter']['parent'] ?? null) !== $slug)) {
            $this->plugin->jsonError('Страница не из этого раздела', 404);
            return;
        }
        $this->board->setPublished($pageSlug, $published);
        $this->plugin->json(['ok' => true, 'published' => $published]);
    }

    public function galleryPanel(array $page): string
    {
        $fm = $page['front_matter'] ?? [];
        $slug = (string) ($fm['slug'] ?? '');
        $parent = isset($fm['parent']) && is_string($fm['parent']) ? $fm['parent'] : null;
        $section = $this->settings->sectionForPage($slug, $parent);
        if ($section === null) {
            return '<p class="mc-hint">Страница не входит в настроенный раздел каталога.</p>';
        }
        $items = $this->gallery->listForPage($fm);
        $caps = ImageProcessor::capabilities();
        ob_start();
        $plugin = $this->plugin;
        $sectionSlug = (string) $section['_slug'];
        $mediaDir = (string) $section['media_dir'];
        require $this->plugin->dir() . '/templates/gallery-panel.php';

        return (string) ob_get_clean();
    }

    public function galleryList(PluginRequest $request): void
    {
        $slug = rawurldecode((string) $request->param('slug'));
        $page = $this->plugin->pages()->load($slug);
        if ($page === null) {
            $this->plugin->jsonError('Страница не найдена', 404);
            return;
        }
        $this->plugin->json(['ok' => true, 'images' => $this->gallery->listForPage($page['front_matter'])]);
    }

    public function galleryUpload(PluginRequest $request): void
    {
        $slug = rawurldecode((string) $request->param('slug'));
        $page = $this->plugin->pages()->load($slug);
        if ($page === null) {
            $this->plugin->jsonError('Страница не найдена', 404);
            return;
        }
        $fm = $page['front_matter'];
        $parent = isset($fm['parent']) && is_string($fm['parent']) ? $fm['parent'] : null;
        $section = $this->settings->sectionForPage($slug, $parent);
        if ($section === null) {
            $this->plugin->jsonError('Страница вне раздела каталога');
            return;
        }
        $file = $request->file('file');
        if ($file === null) {
            $this->plugin->jsonError('Файл не передан');
            return;
        }
        try {
            $item = $this->gallery->upload($slug, $file, (string) $section['media_dir']);
        } catch (\Throwable $e) {
            $this->plugin->jsonError($e->getMessage());
            return;
        }
        $page = $this->plugin->pages()->load($slug);
        $this->plugin->json([
            'ok' => true,
            'item' => $item,
            'images' => $this->gallery->listForPage($page['front_matter'] ?? []),
        ]);
    }

    public function galleryReorder(PluginRequest $request): void
    {
        $slug = rawurldecode((string) $request->param('slug'));
        $order = $request->input('order');
        if (is_string($order)) {
            $order = json_decode($order, true);
        }
        if (!is_array($order)) {
            $this->plugin->jsonError('Некорректный порядок');
            return;
        }
        try {
            $this->gallery->reorder($slug, $order);
        } catch (\Throwable $e) {
            $this->plugin->jsonError($e->getMessage());
            return;
        }
        $page = $this->plugin->pages()->load($slug);
        $this->plugin->json(['ok' => true, 'images' => $this->gallery->listForPage($page['front_matter'] ?? [])]);
    }

    public function galleryPrimary(PluginRequest $request): void
    {
        $slug = rawurldecode((string) $request->param('slug'));
        try {
            $this->gallery->setPrimary($slug, (string) $request->input('path', ''));
        } catch (\Throwable $e) {
            $this->plugin->jsonError($e->getMessage());
            return;
        }
        $page = $this->plugin->pages()->load($slug);
        $this->plugin->json(['ok' => true, 'images' => $this->gallery->listForPage($page['front_matter'] ?? [])]);
    }

    public function galleryDelete(PluginRequest $request): void
    {
        $slug = rawurldecode((string) $request->param('slug'));
        $path = (string) $request->input('path', '');
        $deleteFiles = !empty($request->input('delete_files'));
        try {
            $files = $this->gallery->remove($slug, $path, $deleteFiles);
        } catch (\Throwable $e) {
            $this->plugin->jsonError($e->getMessage());
            return;
        }
        $page = $this->plugin->pages()->load($slug);
        $this->plugin->json([
            'ok' => true,
            'deleted_files' => $deleteFiles,
            'files' => $files,
            'images' => $this->gallery->listForPage($page['front_matter'] ?? []),
        ]);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function exportSettings(array $settings): array
    {
        return [
            'image' => $settings['image'],
            'sections' => $settings['sections'] === [] ? new \stdClass() : $settings['sections'],
        ];
    }

    private function label(mixed $label): string
    {
        if (is_string($label)) {
            return $label;
        }
        if (is_array($label)) {
            return (string) $this->plugin->localized($label);
        }

        return 'Раздел';
    }
}
