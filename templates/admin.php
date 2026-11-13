<?php
/** @var string $section */
$e = static fn(mixed $v): string => PluginContext::e($v);
?>
<div class="mc-sheet cat-admin">
    <div class="mc-head mc-head--sheet">
        <h1 class="mc-head__title">
            <?php if ($section === 'settings'): ?>
                Настройки каталога
            <?php elseif ($section === 'board'): ?>
                <?php
                $boardTitle = $section_meta['label'] ?? $section_slug;
                echo $e(is_array($boardTitle) ? $plugin->localized($boardTitle) : $boardTitle);
                ?>
            <?php else: ?>
                Каталог
            <?php endif; ?>
        </h1>
        <div class="mc-head__actions">
            <a class="mc-link" href="<?= $e($plugin->adminUrl('/')) ?>">Разделы</a>
            <a class="mc-link" href="<?= $e($plugin->adminUrl('/settings')) ?>">Настройки</a>
        </div>
    </div>

    <?php if (!empty($saved)): ?>
        <p class="mc-note">Сохранено.</p>
    <?php endif; ?>
    <?php if (!empty($settings_error)): ?>
        <p class="mc-note"><?= $e($settings_error) ?></p>
    <?php endif; ?>
    <?php if (!empty($form_error)): ?>
        <p class="mc-note"><?= $e($form_error) ?></p>
    <?php endif; ?>

    <?php if ($section === 'index'): ?>
        <?php if (empty($image_caps)): ?>
            <p class="mc-note"><?= $e(\Catalog\ImageProcessor::unavailableMessage()) ?></p>
        <?php else: ?>
            <p class="mc-hint">Обработка изображений: <?= $e($image_caps['driver']) ?>.</p>
        <?php endif; ?>
        <?php if ($sections === []): ?>
            <p class="mc-muted">Разделы ещё не настроены. Откройте <a class="mc-link" href="<?= $e($plugin->adminUrl('/settings')) ?>">настройки</a> и добавьте slug раздела (например <code>catalog/widgets</code>).</p>
        <?php else: ?>
            <ul class="cat-section-list">
                <?php foreach ($sections as $slug => $meta): ?>
                    <li>
                        <a class="mc-link" href="<?= $e($plugin->adminUrl('/sections/' . $slug)) ?>">
                            <?php
                            $label = $meta['label'] ?? $slug;
                            echo $e(is_array($label) ? $plugin->localized($label) : $label);
                            ?>
                        </a>
                        <span class="mc-muted"><?= $e($slug) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

    <?php elseif ($section === 'settings'): ?>
        <form method="post" action="<?= $e($plugin->adminUrl('/settings')) ?>" class="mc-stack">
            <?= $plugin->csrfField() ?>
            <p class="mc-hint">JSON настроек: размеры WebP и разделы с полями карточек. Пример — <code>examples/settings.example.json</code> в архиве плагина.</p>
            <label class="mc-label" for="settings_json">settings.json</label>
            <textarea id="settings_json" name="settings_json" rows="24" class="mc-input mc-input--code"><?= $e($raw_json ?? '') ?></textarea>
            <button type="submit" class="mc-btn mc-btn--primary">Сохранить</button>
        </form>

    <?php elseif ($section === 'board'): ?>
        <?php
        $titleLabel = $section_meta['label'] ?? $section_slug;
        if (is_array($titleLabel)) {
            $titleLabel = $plugin->localized($titleLabel);
        }
        ?>
        <p class="mc-hint">
            Шаблон новых страниц: <code><?= $e($section_meta['template'] ?? 'product') ?></code>,
            медиа: <code><?= $e($section_meta['media_dir'] ?? '') ?></code>.
            Перетаскивайте карточки внутри ряда и между рядами.
        </p>
        <?php if (!empty($created)): ?>
            <p class="mc-note">Создана страница <code><?= $e($created) ?></code>.</p>
        <?php endif; ?>

        <div
            class="cat-board"
            data-catalog-board
            data-layout-url="<?= $e($plugin->adminUrl('/sections/' . $section_slug . '/layout')) ?>"
            data-publish-url="<?= $e($plugin->adminUrl('/sections/' . $section_slug . '/publish')) ?>"
            data-csrf="<?= $e($plugin->csrfToken()) ?>"
        >
            <?php foreach ($rows as $row): ?>
                <div class="cat-board__row" data-group="<?= (int) $row['group'] ?>">
                    <div class="cat-board__row-head">Ряд <?= (int) $row['group'] ?></div>
                    <ul class="cat-board__list">
                        <?php foreach ($row['pages'] as $page): ?>
                            <li class="cat-board__card" draggable="true" data-slug="<?= $e($page['slug']) ?>">
                                <?php if (!empty($page['thumb'])): ?>
                                    <img src="<?= $e($page['thumb']) ?>" alt="" width="64" height="64" loading="lazy">
                                <?php else: ?>
                                    <span class="cat-board__ph">нет фото</span>
                                <?php endif; ?>
                                <div class="cat-board__meta">
                                    <a class="mc-link" href="/admin-panel/pages/<?= $e($page['slug']) ?>"><?= $e($page['title']) ?></a>
                                    <label class="mc-check">
                                        <input type="checkbox" data-catalog-publish <?= !empty($page['published']) ? 'checked' : '' ?>>
                                        Опубликовано
                                    </label>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
            <div class="cat-board__row cat-board__row--empty" data-group="new">
                <div class="cat-board__row-head">Новый ряд</div>
                <ul class="cat-board__list"></ul>
            </div>
        </div>

        <form method="post" action="<?= $e($plugin->adminUrl('/sections/' . $section_slug . '/create')) ?>" class="mc-stack cat-create" style="margin-top:1.5rem">
            <?= $plugin->csrfField() ?>
            <h2 class="mc-page-panel__title">Новая страница в разделе</h2>
            <div class="mc-grid-2">
                <div>
                    <label class="mc-label" for="title">Заголовок</label>
                    <input class="mc-input" id="title" name="title" required>
                </div>
                <div>
                    <label class="mc-label" for="leaf_slug">Часть адреса</label>
                    <input class="mc-input" id="leaf_slug" name="leaf_slug" required pattern="[a-z0-9-]+">
                </div>
            </div>
            <input type="hidden" name="group" value="0">
            <button type="submit" class="mc-btn mc-btn--primary">Создать черновик</button>
        </form>

    <?php else: ?>
        <p class="mc-muted"><?= $e($message ?? 'Не найдено') ?></p>
    <?php endif; ?>
</div>
