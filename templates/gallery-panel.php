<?php
/** @var PluginContext $plugin */
/** @var string $slug */
/** @var string $sectionSlug */
/** @var string $mediaDir */
/** @var list<array{path: string, url: string, thumb_url: string}> $items */
/** @var array{driver: string, webp: bool}|null $caps */
?>
<div
    class="cat-gallery"
    data-catalog-gallery
    data-slug="<?= PluginContext::e($slug) ?>"
    data-upload-url="<?= PluginContext::e($plugin->adminUrl('/gallery/' . $slug . '/upload')) ?>"
    data-reorder-url="<?= PluginContext::e($plugin->adminUrl('/gallery/' . $slug . '/reorder')) ?>"
    data-primary-url="<?= PluginContext::e($plugin->adminUrl('/gallery/' . $slug . '/primary')) ?>"
    data-delete-url="<?= PluginContext::e($plugin->adminUrl('/gallery/' . $slug . '/delete')) ?>"
    data-csrf="<?= PluginContext::e($plugin->csrfToken()) ?>"
>
    <?php if ($caps === null): ?>
        <p class="mc-note"><?= PluginContext::e(\Catalog\ImageProcessor::unavailableMessage()) ?></p>
    <?php else: ?>
        <p class="mc-hint">Раздел <?= PluginContext::e($sectionSlug) ?>, медиа <?= PluginContext::e($mediaDir) ?>. Первый кадр — главный (карточка на сайте). Обработчик: <?= PluginContext::e($caps['driver']) ?>.</p>
        <div class="cat-gallery__toolbar">
            <label class="mc-btn">
                Загрузить
                <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" hidden data-catalog-upload multiple>
            </label>
            <span class="mc-muted" data-catalog-status></span>
        </div>
    <?php endif; ?>

    <ul class="cat-gallery__list" data-catalog-list>
        <?php foreach ($items as $i => $item): ?>
            <li class="cat-gallery__item<?= $i === 0 ? ' is-primary' : '' ?>" draggable="true" data-path="<?= PluginContext::e($item['path']) ?>">
                <img src="<?= PluginContext::e($item['thumb_url']) ?>" alt="" width="96" height="96" loading="lazy">
                <div class="cat-gallery__actions">
                    <button type="button" class="mc-btn mc-btn--quiet" data-catalog-primary title="Сделать главным">★</button>
                    <button type="button" class="mc-btn mc-btn--quiet mc-btn--danger-text" data-catalog-delete title="Убрать из галереи">×</button>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if ($items === []): ?>
        <p class="mc-muted" data-catalog-empty>Пока нет изображений. Можно загрузить или оставить текущие files вручную в images:.</p>
    <?php endif; ?>
</div>
<script src="<?= PluginContext::e($plugin->assetUrl('admin.js')) ?>"></script>
<link rel="stylesheet" href="<?= PluginContext::e($plugin->assetUrl('admin.css')) ?>">
