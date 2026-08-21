<?php

/**
 * Image Optimization Service: WebP conversion via GD + local cache.
 * Any conversion failure falls back to original URL immediately.
 *
 * @version 1.0.0
 */
class ImageOptimizationService
{
    /** @var Cache */
    private $cache;

    /** @var string */
    private $cachePath;

    public function __construct()
    {
        $this->cache = new Cache();
        $this->cachePath = TOURFECTO_STORAGE . '/cache/images';
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }
    }

    /**
     * Convert image to WebP with caching.
     *
     * @param string $imageUrl Original image URL
     * @return string WebP URL (or original on failure)
     */
    public function optimize(string $imageUrl): string
    {
        if (!extension_loaded('gd')) {
            return $imageUrl;
        }

        $cacheKey = 'webp_' . md5($imageUrl);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null && file_exists($cached)) {
            return $this->buildPublicUrl($cached);
        }

        try {
            $originalData = $this->fetchImage($imageUrl);
            if ($originalData === null) {
                return $imageUrl;
            }

            $source = @imagecreatefromstring($originalData);
            if ($source === false) {
                return $imageUrl;
            }

            $filename = $cacheKey . '.webp';
            $filepath = $this->cachePath . '/' . $filename;

            $width = imagesx($source);
            $height = imagesy($source);

            // Resize if image > 1920px (performance)
            $maxWidth = 1920;
            if ($width > $maxWidth) {
                $ratio = $maxWidth / $width;
                $newWidth = (int) $maxWidth;
                $newHeight = (int) ($height * $ratio);
                $resized = imagecreatetruecolor($newWidth, $newHeight);
                imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                imagedestroy($source);
                $source = $resized;
            }

            $result = @imagewebp($source, $filepath, 85);
            imagedestroy($source);

            if (!$result || !file_exists($filepath)) {
                return $imageUrl;
            }

            $this->cache->set($cacheKey, $filepath, 86400 * 7); // 1 week cache
            return $this->buildPublicUrl($filepath);

        } catch (Exception $e) {
            Logger::warning('ImageOptimizationService: conversion failed', [
                'url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);
            return $imageUrl;
        }
    }

    /**
     * Apply lazy loading to HTML: add loading="lazy" to every <img> without it.
     * Skips first image (assumed above-the-fold hero).
     */
    public function applyLazyLoading(string $html): string
    {
        $firstImageSkipped = false;

        return preg_replace_callback(
            '/<img\b[^>]*>/i',
            function ($matches) use (&$firstImageSkipped) {
                $tag = $matches[0];

                // Already has loading attribute - تحسب كأول صورة (hero)
                // فوق الـ fold، فبنعتبر الصورة الأولى خلاص اتخطت.
                if (preg_match("/\sloading\s*=\s*[\"'][^\"']*[\"']/i", $tag)) {
                    $firstImageSkipped = true;
                    return $tag;
                }

                // First image = above-the-fold (assumed hero image)
                if (!$firstImageSkipped) {
                    $firstImageSkipped = true;
                    return $tag;
                }

                // Add loading="lazy" before closing tag
                return preg_replace('/\s*\/?>$/', ' loading="lazy"$0', $tag);
            },
            $html
        );
    }

    /**
     * Replace image src with WebP in HTML.
     */
    public function rewriteImageSrc(string $html, string $baseUrl): string
    {
        return preg_replace_callback(
            "/<img\b[^>]*\bsrc\s*=\s*[\"']([^\"']+)[\"'][^>]*>/i",
            function ($matches) use ($baseUrl) {
                $originalTag = $matches[0];
                $src = $matches[1];

                // Skip data URIs
                if (strpos($src, 'data:') === 0) {
                    return $originalTag;
                }

                // Resolve relative URL
                if (!preg_match('#^https?://#i', $src)) {
                    $src = rtrim($baseUrl, '/') . '/' . ltrim($src, '/');
                }

                $optimized = $this->optimize($src);

                // Fallback: return original on failure
                if ($optimized === $src) {
                    return $originalTag;
                }

                return str_replace($matches[1], $optimized, $originalTag);
            },
            $html
        );
    }

    private function fetchImage(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'TourfectoImageBot/1.0',
        ]);
        $data = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($data === false || $code !== 200) {
            return null;
        }
        return $data;
    }

    private function buildPublicUrl(string $filepath): string
    {
        $relative = str_replace(TOURFECTO_STORAGE, '/storage', $filepath);
        $base = rtrim((string) (getenv('APP_URL') ?: 'https://tourfecto.pro'), '/');
        return $base . $relative;
    }
}
