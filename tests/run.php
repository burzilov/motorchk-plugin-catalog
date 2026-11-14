<?php

/**
 * Тесты плагина catalog: php tests/run.php [path/to/core]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$pluginRoot = dirname(__DIR__);
$corePath = $argv[1] ?? getenv('MOTORCHK_CORE') ?: '';
if ($corePath === '') {
    $guess = dirname($pluginRoot, 3) . '/core';
    $corePath = is_dir($guess) ? $guess : '';
}
$corePath = rtrim((string) $corePath, '/');
if ($corePath === '' || !is_file($corePath . '/includes/PluginManager.php')) {
    fwrite(STDERR, "Укажите путь к ядру: php tests/run.php /path/to/core\n");
    exit(1);
}
if (!is_file($corePath . '/vendor/autoload.php')) {
    fwrite(STDERR, "В ядре нет vendor/autoload.php (composer install)\n");
    exit(1);
}

require_once $corePath . '/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($corePath): void {
    $file = $corePath . '/includes/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

ob_start();
Env::set('APP_SECRET', 'catalog-test-secret');
$failures = 0;
$passed = 0;

function out(string $t): void { fwrite(STDOUT, $t); }
function check(bool $ok, string $name, string $details = ''): void
{
    global $failures, $passed;
    if ($ok) { $passed++; out("  ok   {$name}\n"); return; }
    $failures++; out("  FAIL {$name}" . ($details !== '' ? " — {$details}" : '') . "\n");
}

function rm(string $path): void
{
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $item) {
        if ($item !== '.' && $item !== '..') rm($path . '/' . $item);
    }
    rmdir($path);
}

function copyTree(string $src, string $dst): void
{
    if (!is_dir($dst)) mkdir($dst, 0775, true);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $item) {
        $target = $dst . '/' . $it->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($target)) mkdir($target, 0775, true);
        } else {
            $parent = dirname($target);
            if (!is_dir($parent)) mkdir($parent, 0775, true);
            copy($item->getPathname(), $target);
        }
    }
}

/**
 * @return array{0:int,1:string}
 */
function capture(callable $fn, string $method = 'GET', array $post = [], array $files = []): array
{
    $_SERVER['REQUEST_METHOD'] = strtoupper($method);
    $_SERVER['HTTP_HOST'] = 'catalog.test';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    $_POST = $post;
    $_GET = [];
    $_FILES = $files;
    http_response_code(200);
    ob_start();
    try { $fn(); } finally { $body = (string) ob_get_clean(); }
    return [http_response_code(), $body];
}

out("catalog tests (core " . Version::current() . ")\n");

if (version_compare(Version::current(), '0.8.5', '<')) {
    fwrite(STDERR, "Нужно ядро >= 0.8.5\n");
    exit(1);
}

$site = sys_get_temp_dir() . '/motorchk-catalog-' . bin2hex(random_bytes(4));
rm($site);
mkdir($site, 0775, true);

try {
    copyTree($corePath . '/skeleton/content', $site . '/content');
    copyTree($corePath . '/skeleton/themes', $site . '/themes');
    if (is_dir($corePath . '/skeleton/config')) {
        copyTree($corePath . '/skeleton/config', $site . '/config');
    }
    mkdir($site . '/cache', 0775, true);
    mkdir($site . '/media', 0775, true);
    mkdir($site . '/plugins', 0775, true);
    mkdir($site . '/plugins-data', 0775, true);
    file_put_contents($site . '/cache/update-check.json', '{"checked_at":"2099-01-01T00:00:00+00:00"}');

    // install plugin copy
    copyTree($pluginRoot, $site . '/plugins/catalog');
    // drop tests from installed copy noise — ok to keep

    $config = [
        'site_path' => $site,
        'core_path' => $corePath,
        'base_path' => $site,
        'content_path' => $site . '/content',
        'cache_path' => $site . '/cache',
        'themes_path' => $site . '/themes',
        'forms_path' => $site . '/content/forms',
        'submissions_path' => $site . '/submissions',
        'admin_templates_path' => $corePath . '/admin-panel/templates',
        'plugins_path' => $site . '/plugins',
        'plugin_data_path' => $site . '/plugins-data',
        'media_path' => $site . '/media',
        'installed' => true,
    ];

    $plugins = new PluginManager($config);
    $info = $plugins->discover()['catalog'] ?? null;
    check(($info['status'] ?? '') === 'ok', 'plugin discovers', json_encode($info['errors'] ?? [], JSON_UNESCAPED_UNICODE));
    check(!empty($info['page_fields']), 'page_fields flag');

    $ctx = $plugins->boot('catalog');
    check($ctx instanceof PluginContext, 'boot catalog');
    check(is_file($site . '/plugins-data/catalog/settings.json'), 'install wrote settings');

    // configure section + customizer field
    $settings = new Catalog\Settings($ctx);
    $settings->save([
        'image' => ['large' => 200, 'small' => 100, 'quality' => 80],
        'sections' => [
            'catalog/widgets' => [
                'label' => ['ru' => 'Виджеты', 'en' => 'Widgets'],
                'template' => 'default',
                'media_dir' => 'products/widgets',
                'fields' => [
                    ['key' => 'product_url', 'type' => 'url', 'label' => ['ru' => 'Ссылка на товар', 'en' => 'Product link']],
                ],
            ],
        ],
    ]);

    // recreate manager so boot re-registers fields from new settings
    $plugins = new PluginManager($config);
    $ctx = $plugins->boot('catalog');
    $fields = $plugins->pageFieldsFor(['template' => 'default', 'parent' => 'catalog/widgets'], 'catalog/widgets/item-a');
    $keys = array_map(static fn($f) => $f['key'], $fields);
    check(in_array('product_url', $keys, true), 'product_url field registered for section child');

    // seed pages with legacy images/group/order
    $writer = $ctx->pages();
    $cache = new Cache($site . '/cache');
    $renderer = new MarkdownRenderer();
    $loader = new PageLoader($config, $cache, $renderer);
    $writer->create('Каталог', 'catalog', null, 'default');
    $writer->create('Виджеты', 'widgets', 'catalog', 'default');

    $itemA = $writer->create('Item A', 'item-a', 'catalog/widgets', 'default');
    $writer->updateMeta($itemA, [
        'images' => ['products/widgets/item-a-1.webp', 'products/widgets/item-a-2.webp'],
        'group' => 1,
        'order' => 20,
        'product_url' => '/products/item-a',
        'published' => true,
    ]);
    $itemB = $writer->create('Item B', 'item-b', 'catalog/widgets', 'default');
    $writer->updateMeta($itemB, [
        'group' => 1,
        'order' => 10,
        'published' => true,
    ]);

    $gallery = new Catalog\Gallery($ctx, $settings);
    $list = $gallery->listForPage($loader->loadBySlug($itemA, false)['front_matter']);
    check(count($list) === 2, 'reads existing images without migration');
    check($list[0]['path'] === 'products/widgets/item-a-1.webp', 'preserves image order');

    $gallery->setPrimary($itemA, 'products/widgets/item-a-2.webp');
    $fm = $loader->loadBySlug($itemA, false)['front_matter'];
    check(($fm['images'][0] ?? '') === 'products/widgets/item-a-2.webp', 'set primary');
    check(!array_key_exists('image', $fm) || $fm['image'] === null, 'clears legacy image key');

    $gallery->reorder($itemA, ['products/widgets/item-a-1.webp', 'products/widgets/item-a-2.webp']);
    $fm = $loader->loadBySlug($itemA, false)['front_matter'];
    check(($fm['images'][0] ?? '') === 'products/widgets/item-a-1.webp', 'reorder');

    // upload via ImageMagick/GD if available
    $caps = Catalog\ImageProcessor::capabilities();
    check($caps !== null, 'image processor available', Catalog\ImageProcessor::unavailableMessage());
    if ($caps !== null) {
        $src = $site . '/tmp-src.png';
        if (extension_loaded('gd') && function_exists('imagepng')) {
            $im = imagecreatetruecolor(64, 64);
            $red = imagecolorallocate($im, 255, 0, 0);
            imagefilledrectangle($im, 0, 0, 63, 63, $red);
            imagepng($im, $src);
        } else {
            $code = 0;
            exec('magick -size 64x64 xc:red ' . escapeshellarg($src) . ' 2>&1', $out, $code);
            if ($code !== 0 || !is_file($src)) {
                exec('convert -size 64x64 xc:red ' . escapeshellarg($src) . ' 2>&1', $out, $code);
            }
        }
        check(is_file($src), 'fixture image created');
        $item = $gallery->upload($itemA, [
            'name' => 't.png',
            'type' => 'image/png',
            'tmp_name' => $src,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($src),
        ], 'products/widgets');
        check(str_ends_with($item['path'], '.webp'), 'upload writes webp path');
        check(is_file($site . '/media/' . $item['path']), 'large webp exists');
        check(is_file($site . '/media/' . Catalog\Gallery::thumbPath($item['path'])), 'sm webp exists');
    }

    // section board layout
    $board = new Catalog\SectionBoard($ctx);
    $rows = $board->rows('catalog/widgets');
    check($rows !== [], 'board rows from group/order');
    $board->applyLayout('catalog/widgets', [
        [$itemB],
        [$itemA],
    ]);
    $fm1 = $loader->loadBySlug($itemA, false)['front_matter'];
    $fm2 = $loader->loadBySlug($itemB, false)['front_matter'];
    check((int) $fm2['group'] === 0 && (int) $fm1['group'] === 1, 'layout moves between rows');

    $created = $board->createPage('catalog/widgets', 'Test Item', 'test-item', 'default', 1);
    check($created === 'catalog/widgets/test-item', 'create page in section');
    $cfm = $loader->loadBySlug($created, false)['front_matter'];
    check(($cfm['parent'] ?? null) === 'catalog/widgets', 'create sets parent');
    check(empty($cfm['published']), 'create unpublished');

    $board->setPublished($created, true);
    check(!empty($loader->loadBySlug($created, false)['front_matter']['published']), 'publish toggle');

    // remove from gallery without deleting files
    if ($caps !== null) {
        $before = $gallery->listForPage($loader->loadBySlug($itemA, false)['front_matter']);
        $path = $before[count($before) - 1]['path'];
        $abs = $gallery->remove($itemA, $path, false);
        check(is_file($abs[0]), 'remove without delete_files keeps file');
        $afterPaths = array_column($gallery->listForPage($loader->loadBySlug($itemA, false)['front_matter']), 'path');
        check(!in_array($path, $afterPaths, true), 'remove drops from images');
    }

    // admin route smoke
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $_SESSION['admin_authenticated'] = true;
    $token = Csrf::token();
    [$st, $body] = capture(static function () use ($config): void {
        (new AdminRouter($config))->dispatchAdmin('/admin-panel/plugins/catalog/');
    });
    check($st === 200, 'admin index', (string) $st);
    check(str_contains($body, 'catalog/widgets') || str_contains($body, 'Виджеты'), 'admin lists section');

    [$st] = capture(static function () use ($config): void {
        (new AdminRouter($config))->dispatchAdmin('/admin-panel/plugins/catalog/sections/catalog/widgets');
    });
    check($st === 200, 'admin section board');

    // CSRF on mutating route
    [$st, $body] = capture(static function () use ($config): void {
        (new AdminRouter($config))->dispatchAdmin('/admin-panel/plugins/catalog/sections/catalog/widgets/layout');
    }, 'POST', ['layout' => json_encode([[$itemA]])]);
    check($st === 403, 'layout without csrf → 403');

    [$st, $body] = capture(static function () use ($config): void {
        (new AdminRouter($config))->dispatchAdmin('/admin-panel/plugins/catalog/sections/catalog/widgets/layout');
    }, 'POST', ['_csrf' => $token, 'layout' => json_encode([[$itemA], [$itemB]])]);
    check($st === 200, 'layout with csrf');
    $json = json_decode($body, true);
    check(!empty($json['ok']), 'layout json ok');

} finally {
    rm($site);
}

out("\n{$passed} passed, {$failures} failed\n");
exit($failures > 0 ? 1 : 0);
