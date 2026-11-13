<?php

namespace Catalog;

/**
 * Конвертация загрузок в WebP и генерация большого / -sm кадра.
 * Предпочитает GD; иначе ImageMagick CLI (magick/convert).
 */
final class ImageProcessor
{
    /**
     * @return array{driver: string, webp: bool}|null null — обработчик недоступен
     */
    public static function capabilities(): ?array
    {
        if (extension_loaded('gd') && function_exists('imagewebp')) {
            $info = function_exists('gd_info') ? gd_info() : [];
            if (!empty($info['WebP Support'])) {
                return ['driver' => 'gd', 'webp' => true];
            }
        }

        $bin = self::magickBinary();
        if ($bin !== null) {
            return ['driver' => 'imagemagick', 'webp' => true, 'binary' => $bin];
        }

        return null;
    }

    public static function unavailableMessage(): string
    {
        return 'Нет обработчика изображений: нужен PHP GD с WebP или ImageMagick (magick/convert) в PATH.';
    }

    /**
     * Создаёт {base}.webp и {base}-sm.webp. $sourcePath — временный загруженный файл.
     *
     * @param array{large: int, small: int, quality: int} $sizes
     * @return array{large: string, small: string} абсолютные пути
     */
    public static function convertToWebpPair(string $sourcePath, string $destDir, string $baseName, array $sizes): array
    {
        $caps = self::capabilities();
        if ($caps === null) {
            throw new \RuntimeException(self::unavailableMessage());
        }

        if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
            throw new \RuntimeException('Не удалось создать каталог media');
        }

        $largePath = $destDir . '/' . $baseName . '.webp';
        $smallPath = $destDir . '/' . $baseName . '-sm.webp';

        if ($caps['driver'] === 'gd') {
            self::gdSquareWebp($sourcePath, $largePath, $sizes['large'], $sizes['quality']);
            self::gdSquareWebp($sourcePath, $smallPath, $sizes['small'], $sizes['quality']);
        } else {
            self::magickSquareWebp($caps['binary'], $sourcePath, $largePath, $sizes['large'], $sizes['quality']);
            self::magickSquareWebp($caps['binary'], $sourcePath, $smallPath, $sizes['small'], $sizes['quality']);
        }

        return ['large' => $largePath, 'small' => $smallPath];
    }

    private static function magickBinary(): ?string
    {
        foreach (['magick', 'convert'] as $bin) {
            $path = self::which($bin);
            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    private static function which(string $bin): ?string
    {
        $out = [];
        $code = 0;
        exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null', $out, $code);
        if ($code === 0 && isset($out[0]) && is_executable($out[0])) {
            return $out[0];
        }

        return null;
    }

    private static function gdSquareWebp(string $source, string $dest, int $size, int $quality): void
    {
        $raw = @file_get_contents($source);
        if ($raw === false) {
            throw new \RuntimeException('Не удалось прочитать загруженный файл');
        }
        $src = @imagecreatefromstring($raw);
        if ($src === false) {
            throw new \RuntimeException('Файл не является изображением');
        }

        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw < 1 || $sh < 1) {
            imagedestroy($src);
            throw new \RuntimeException('Пустое изображение');
        }

        $side = min($sw, $sh);
        $sx = (int) (($sw - $side) / 2);
        $sy = (int) (($sh - $side) / 2);
        $dst = imagecreatetruecolor($size, $size);
        if ($dst === false) {
            imagedestroy($src);
            throw new \RuntimeException('Не удалось создать изображение');
        }
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $size, $size, $side, $side);
        if (!imagewebp($dst, $dest, $quality)) {
            imagedestroy($src);
            imagedestroy($dst);
            throw new \RuntimeException('Не удалось записать WebP');
        }
        imagedestroy($src);
        imagedestroy($dst);
    }

    private static function magickSquareWebp(string $binary, string $source, string $dest, int $size, int $quality): void
    {
        $geom = $size . 'x' . $size;
        // cover + центр: квадрат без искажений
        if (basename($binary) === 'magick') {
            $cmd = sprintf(
                '%s %s -auto-orient -resize %s^ -gravity center -extent %s -quality %d %s',
                escapeshellcmd($binary),
                escapeshellarg($source),
                escapeshellarg($geom),
                escapeshellarg($geom),
                $quality,
                escapeshellarg($dest)
            );
        } else {
            $cmd = sprintf(
                '%s %s -auto-orient -resize %s^ -gravity center -extent %s -quality %d %s',
                escapeshellcmd($binary),
                escapeshellarg($source),
                escapeshellarg($geom),
                escapeshellarg($geom),
                $quality,
                escapeshellarg($dest)
            );
        }
        $out = [];
        $code = 0;
        exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0 || !is_file($dest)) {
            throw new \RuntimeException('ImageMagick: ' . implode(' ', $out));
        }
    }
}
