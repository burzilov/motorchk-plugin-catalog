<?php

/**
 * Каталог: галерея страниц, доска разделов, настраиваемые поля карточек.
 * Админка: /admin-panel/plugins/catalog/
 * Данные: plugins-data/catalog/settings.json
 */

use Catalog\Admin;
use Catalog\FieldsRegistrar;
use Catalog\Gallery;
use Catalog\SectionBoard;
use Catalog\Settings;

return static function (PluginContext $plugin): void {
    $settings = static fn(): Settings => new Settings($plugin);
    $gallery = static fn(): Gallery => new Gallery($plugin, $settings());
    $board = static fn(): SectionBoard => new SectionBoard($plugin);
    $admin = static fn(): Admin => new Admin($plugin, $settings(), $gallery(), $board());

    $plugin->onInstall(static function (?string $from, string $to, PluginContext $ctx): void {
        if ($ctx->readFile(Settings::FILE) === null) {
            $defaults = Settings::defaults();
            $defaults['sections'] = new stdClass();
            $ctx->writeJson(Settings::FILE, $defaults);
        }
    });

    // Поля карточек из настроек разделов
    (new FieldsRegistrar($plugin, $settings()))->register();

    // Панель галереи в редакторе страницы (разделы из настроек)
    $sectionSlugs = array_keys($settings()->sections());
    if ($sectionSlugs !== []) {
        $plugin->registerPagePanel('gallery', [
            'label' => ['ru' => 'Галерея', 'en' => 'Gallery'],
            'placement' => 'main',
            'show_when' => ['section' => $sectionSlugs],
            'render' => static function (array $page, PluginContext $p) use ($admin): string {
                return $admin()->galleryPanel($page);
            },
        ]);
    }

    // ---------- админка ----------
    $plugin->adminRoute('GET', '/', static fn(PluginRequest $r) => $admin()->index($r));
    $plugin->adminRoute('GET', '/settings', static fn(PluginRequest $r) => $admin()->settingsGet($r));
    $plugin->adminRoute('POST', '/settings', static fn(PluginRequest $r) => $admin()->settingsPost($r));

    $plugin->adminRoute('GET', '/sections/{slug*}', static fn(PluginRequest $r) => $admin()->sectionGet($r));
    $plugin->adminRoute('POST', '/sections/{slug*}/layout', static fn(PluginRequest $r) => $admin()->sectionLayout($r));
    $plugin->adminRoute('POST', '/sections/{slug*}/create', static fn(PluginRequest $r) => $admin()->sectionCreate($r));
    $plugin->adminRoute('POST', '/sections/{slug*}/publish', static fn(PluginRequest $r) => $admin()->sectionPublish($r));

    $plugin->adminRoute('GET', '/gallery/{slug*}', static fn(PluginRequest $r) => $admin()->galleryList($r));
    $plugin->adminRoute('POST', '/gallery/{slug*}/upload', static fn(PluginRequest $r) => $admin()->galleryUpload($r));
    $plugin->adminRoute('POST', '/gallery/{slug*}/reorder', static fn(PluginRequest $r) => $admin()->galleryReorder($r));
    $plugin->adminRoute('POST', '/gallery/{slug*}/primary', static fn(PluginRequest $r) => $admin()->galleryPrimary($r));
    $plugin->adminRoute('POST', '/gallery/{slug*}/delete', static fn(PluginRequest $r) => $admin()->galleryDelete($r));
};
