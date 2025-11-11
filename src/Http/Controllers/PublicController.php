<?php

namespace Platform\ThumbnailGenerator\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Platform\Media\Facades\RvMediaFacade as AppMedia;
use Platform\Base\Http\Controllers\BaseController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicController extends BaseController
{
    public function resize($slug, Request $request)
    {
        $original_img_path = public_path($slug);

        if (!file_exists($original_img_path)) {
            $original_img_path = public_path(AppMedia::getConfig('default_image'));
        }

        // Check if webp version exists for jpg/jpeg/png files
        $img_path = $original_img_path;
        $originalExtension = File::extension($original_img_path);

        // Only check for webp if original is jpg, jpeg, or png
        if (in_array(strtolower($originalExtension), ['jpg', 'jpeg', 'png'])) {
            $webpPath = str_replace('.' . $originalExtension, '.webp', $original_img_path);
            if (file_exists($webpPath)) {
                $img_path = $webpPath;
            }
        }

        $extension = strtolower(File::extension($img_path));
        $lastModified = filemtime($img_path);

        // Force output to WebP for all image formats (better compression & performance)
        $forceWebP = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
        $outputExtension = $forceWebP ? 'webp' : $extension;
        $outputMimeType = $forceWebP ? 'image/webp' : 'image/' . $extension;

        // Cache metadata (image size) separately to avoid repeated disk access
        $metaCacheKey = 'thumbnail:meta:' . md5($img_path . (string)$lastModified);
        $metaCacheGroup = 'thumbnail_meta';

        $size = apps_cache_get($metaCacheKey, null, $metaCacheGroup);
        if (!$size) {
            $size = getimagesize($img_path);
            if ($size) {
                apps_cache_store(
                    $metaCacheKey,
                    $size,
                    60 * 60 * 24 * 30,
                    $metaCacheGroup
                );
            }
        }

        if (!$size) {
            abort(404);
        }

        if (blank($request->w)) {
            if ($size[0] > 1920) {
                $size[0] = 1920;
            }
            $request->merge(['w' => $size[0]]);
            if ($request->has('h') && !blank($request->h) && $request->h != $size[1]) {
                $request->merge(['w' => strval($size[0] * $request->h / $size[1])]);
            }
        }

        if (blank($request->h)) {
            $request->merge(['h' => $size[1]]);
            if ($request->has('w') && !blank($request->w) && $request->w != $size[0]) {
                $request->merge(['h' => strval($size[1] * $request->w / $size[0])]);
            }
        }

        $width = max(1, (int) round((float) $request->w));
        $height = max(1, (int) round((float) $request->h));

        $cacheFile = $this->getCachedFilePath($slug, $width, $height, $outputExtension, $lastModified);

        // Check if cache exists and is still valid (not outdated)
        // Use clearstatcache to ensure fresh file stats
        clearstatcache(true, $cacheFile);
        
        if (File::exists($cacheFile)) {
            $cacheModified = filemtime($cacheFile);

            // If source file is newer than cache, regenerate
            if ($lastModified > $cacheModified) {
                File::delete($cacheFile);
                
                // Cleanup old format cache files (jpg/jpeg/png) when regenerating as WebP
                if ($forceWebP && $outputExtension === 'webp') {
                    $this->cleanupOldCacheFiles($slug, $width, $height, $cacheFile);
                }
            } else {
                // Đảm bảo Content-Type đúng với extension của file cache
                $actualExtension = strtolower(File::extension($cacheFile));
                $actualMimeType = $actualExtension === 'webp' ? 'image/webp' : ($actualExtension === 'jpg' || $actualExtension === 'jpeg' ? 'image/jpeg' : 'image/' . $actualExtension);
                return $this->binaryResponse($cacheFile, $actualMimeType, $request);
            }
        } else {
            // Cleanup old format cache files (jpg/jpeg/png) when creating new WebP
            if ($forceWebP && $outputExtension === 'webp') {
                $this->cleanupOldCacheFiles($slug, $width, $height, $cacheFile);
            }
        }

        $lockHandle = $this->acquireFileLock($cacheFile);

        try {
            // Double-check after acquiring lock (another request might have created it)
            clearstatcache(true, $cacheFile);
            if (!File::exists($cacheFile)) {
                $this->generateThumbnailFile($img_path, $cacheFile, $width, $height, $forceWebP);
            }
        } finally {
            $this->releaseFileLock($lockHandle, $cacheFile);
        }

        return $this->binaryResponse($cacheFile, $outputMimeType, $request);
    }

    /**
     * Cleanup old cache files (jpg/jpeg/png/gif) when migrating to WebP
     * Only checks 4 files instead of looping with exists checks
     * 
     * @param string $slug
     * @param int $width
     * @param int $height
     * @param string $currentCacheFile
     * @return void
     */
    protected function cleanupOldCacheFiles(string $slug, int $width, int $height, string $currentCacheFile): void
    {
        $oldExtensions = ['jpg', 'jpeg', 'png', 'gif'];
        
        foreach ($oldExtensions as $oldExt) {
            $oldCacheFile = $this->getCachedFilePath($slug, $width, $height, $oldExt, false);
            
            // Skip if same file or doesn't exist (use @ to suppress warnings for performance)
            if ($oldCacheFile === $currentCacheFile || !@unlink($oldCacheFile)) {
                continue;
            }
        }
    }

    /**
     * Get cached file path without mtime folder for easier file management
     * Cache invalidation handled by ETag (file content hash)
     * 
     * @param string $slug
     * @param int $width
     * @param int $height
     * @param string $extension
     * @param int|false $lastModified (not used, kept for compatibility)
     * @return string
     */
    protected function getCachedFilePath(string $slug, int $width, int $height, string $extension, $lastModified): string
    {
        $safeSlug = ltrim($slug, '/');
        $directory = public_path('resize/' . $width . 'x' . $height);

        // Removed mtime folder for easier file management on FileZilla
        // Cache invalidation now handled by ETag header
        // if ($lastModified) {
        //     $directory .= '/' . $lastModified;
        // }

        $fileName = pathinfo($safeSlug, PATHINFO_FILENAME);
        $subPath = pathinfo($safeSlug, PATHINFO_DIRNAME);

        if (!blank($subPath) && $subPath !== '.') {
            $directory .= '/' . $subPath;
        }

        $normalized = Str::slug($fileName);
        if ($normalized === '') {
            $normalized = 'thumbnail';
        }

        $hash = substr(md5($safeSlug), 0, 12);

        return $directory . '/' . $normalized . '-' . $hash . '.' . $extension;
    }

    /**
     * Generate and save thumbnail file
     * 
     * @param string $sourcePath Source image path
     * @param string $cacheFile Output cache file path
     * @param int $width Target width
     * @param int $height Target height
     * @param bool $outputWebP Force output to WebP format
     * @return void
     */
    protected function generateThumbnailFile(string $sourcePath, string $cacheFile, int $width, int $height, bool $outputWebP): void
    {
        File::ensureDirectoryExists(dirname($cacheFile));

        // Use Intervention Image v2 API
        $driver = 'gd';
        if (extension_loaded('imagick')) {
            $driver = 'imagick';
        }

        $imageManager = new ImageManager(['driver' => $driver]);
        $image = $imageManager->make($sourcePath);

        // Use fit() method which crops and resizes to fit dimensions
        $image->fit($width, $height, null, 'center');

        // Encode image - force WebP for better compression & performance
        if ($outputWebP) {
            $imageData = (string) $image->encode('webp', 85);
        } else {
            $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
            $imageData = (string) $image->encode($extension === 'jpg' ? 'jpeg' : $extension, 90);
        }

        File::put($cacheFile, $imageData);
    }

    /**
     * Acquire a filesystem lock for the generated thumbnail.
     *
     * @param string $cacheFile
     * @param int $waitMilliseconds
     * @return resource|null
     */
    protected function acquireFileLock(string $cacheFile, int $waitMilliseconds = 5000)
    {
        $lockPath = $cacheFile . '.lock';

        File::ensureDirectoryExists(dirname($lockPath));

        $handle = fopen($lockPath, 'c');

        if (!is_resource($handle)) {
            throw new \RuntimeException("Unable to create lock file for thumbnail generation.");
        }

        $waitSeconds = $waitMilliseconds / 1000;
        $start = microtime(true);

        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }

            usleep(100 * 1000);
        } while ((microtime(true) - $start) < $waitSeconds);

        fclose($handle);

        throw new \RuntimeException("Timeout acquiring thumbnail generation lock.");
    }

    /**
     * Release the filesystem lock used during thumbnail generation.
     *
     * @param resource|null $handle
     * @param string $cacheFile
     * @return void
     */
    protected function releaseFileLock($handle, string $cacheFile): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $lockPath = $cacheFile . '.lock';
        if (File::exists($lockPath)) {
            @unlink($lockPath);
        }
    }

    /**
     * Return binary file response with optimal cache headers
     * 
     * @param string $filePath Cache file path
     * @param string $mimeType MIME type
     * @param Request $request Request object
     * @return BinaryFileResponse|Response
     */
    protected function binaryResponse(string $filePath, string $mimeType, Request $request)
    {
        // Get file stats once (avoid multiple disk I/O)
        $fileStats = stat($filePath);
        $lastModified = $fileStats['mtime'];
        $fileSize = $fileStats['size'];

        // Generate ETag from mtime + size (faster than md5_file)
        $etag = md5($filePath . $lastModified . $fileSize);

        // Check if client has valid cache (304 Not Modified)
        $clientEtag = $request->header('If-None-Match');
        $clientModified = $request->header('If-Modified-Since');

        if (
            $clientEtag === '"' . $etag . '"' ||
            ($clientModified && strtotime($clientModified) >= $lastModified)
        ) {
            return response('', 304, [
                'Cache-Control' => 'public, max-age=31536000, immutable',
                'ETag' => '"' . $etag . '"',
                'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
            ]);
        }

        // Return file with optimal cache headers
        $response = response()->file($filePath, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Content-Type' => $mimeType,
            'Content-Length' => $fileSize,
            'ETag' => '"' . $etag . '"',
            'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
        ]);

        return $response;
    }
}
