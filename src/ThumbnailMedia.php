<?php

namespace Dev\ThumbnailGenerator;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Dev\Media\AppMedia;
use Dev\Media\Models\MediaFile;

class ThumbnailMedia extends AppMedia
{
 
    /**
     * Xóa thumbnails vật lý trong public/resize/ được tạo bởi PublicController
     * Pattern file: resize/{width}x{height}/{subPath}/{normalized}-{hash}.{ext}
     * 
     * @param MediaFile $file
     * @return bool
     */
    protected function purgePhysicalThumbnails(MediaFile $file): bool
    {
        // Lấy URL từ file (ví dụ: "storage/news/image.jpg")
        $slug = ltrim((string) $file->url, '/');

        if ($slug === '') {
            return false;
        }

        $basePath = public_path('resize');

        if (! File::isDirectory($basePath)) {
            return false;
        }

        // Tạo pattern giống với PublicController::getCachedFilePath()
        // Pattern: {normalized}-{hash}
        $fileName = pathinfo($slug, PATHINFO_FILENAME);
        $normalized = Str::slug($fileName);
        if ($normalized === '') {
            $normalized = 'thumbnail';
        }

        // Hash giống với PublicController (md5 của full slug, lấy 12 ký tự đầu)
        $hash = substr(md5($slug), 0, 12);
        $pattern = $normalized . '-' . $hash;

        // Lấy subPath để tìm trong đúng thư mục con
        // Ví dụ: "storage/news/image.jpg" -> subPath = "storage/news"
        $subPath = pathinfo($slug, PATHINFO_DIRNAME);
        $relativeDir = (!blank($subPath) && $subPath !== '.') ? $subPath : null;

        $deleted = false;

        // Duyệt qua tất cả các thư mục size (ví dụ: resize/300x200, resize/500x300, ...)
        foreach (File::directories($basePath) as $sizeDirectory) {
            $targetDirectory = $sizeDirectory;

            // Thêm subPath nếu có (ví dụ: resize/300x200/storage/news)
            if ($relativeDir) {
                $targetDirectory .= DIRECTORY_SEPARATOR . $relativeDir;
            }

            if (! File::isDirectory($targetDirectory)) {
                continue;
            }

            // Tìm tất cả files khớp pattern: {normalized}-{hash}.*
            // Ví dụ: image-abc123def456.webp, image-abc123def456.jpg, ...
            $files = File::glob($targetDirectory . DIRECTORY_SEPARATOR . $pattern . '.*') ?: [];

            foreach ($files as $path) {
                if (File::delete($path)) {
                    $deleted = true;
                }
            }

            // Cleanup empty directories sau khi xóa files
            $this->cleanupEmptyDirectories($targetDirectory, $sizeDirectory, $basePath);

            // Xóa thư mục size nếu rỗng (ví dụ: resize/300x200)
            if ($this->isDirectoryEmpty($sizeDirectory)) {
                File::deleteDirectory($sizeDirectory);
            }
        }

        // Xóa thư mục resize nếu rỗng
        if ($this->isDirectoryEmpty($basePath)) {
            File::deleteDirectory($basePath);
        }

        return $deleted;
    }

    protected function cleanupEmptyDirectories(string $start, string $sizeDir, string $basePath): void
    {
        $directories = [$start];

        $current = dirname($start);
        while ($current !== $sizeDir && Str::startsWith($current, $sizeDir)) {
            $directories[] = $current;
            $current = dirname($current);
        }

        $directories[] = $sizeDir;

        foreach ($directories as $dir) {
            if ($dir === $basePath) {
                continue;
            }

            if (File::isDirectory($dir) && $this->isDirectoryEmpty($dir)) {
                File::deleteDirectory($dir);
            }
        }
    }

    protected function isDirectoryEmpty(string $directory): bool
    {
        if (! File::isDirectory($directory)) {
            return true;
        }

        return count(scandir($directory)) <= 2;
    }
}
