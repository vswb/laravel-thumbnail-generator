<?php

namespace Dev\ThumbnailGenerator;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Dev\Media\AppMedia;
use Dev\Media\Models\MediaFile;
class ThumbnailMedia extends AppMedia
{
    /**
     * @param string|null $url
     * @param null $size
     * @param bool $relativePath
     * @param null $default
     * @return Application|UrlGenerator|string|string[]|null
     */
    public function getImageUrl(
        ?string $url,
        $size = null,
        bool $relativePath = false,
        $default = null
    ): ?string {
        $url = trim($url);

        if (empty($url)) {
            return $default;
        }

        if (empty($size) || $url == '__value__') {
            if ($relativePath) {
                return $url;
            }

            return $this->url($url);
        }

        if ($url == $this->getDefaultImage(false, $size)) {
            return url($url);
        }

        if (
            $size &&
            array_key_exists($size, $this->getSizes()) &&
            $this->canGenerateThumbnails($this->getMimeType($this->getRealPath($url)))
        ) {
            // Cache file name và extension để tránh gọi nhiều lần
            $fileName = File::name($url);
            $fileExtension = File::extension($url);
            $url = str_replace(
                $fileName . '.' . $fileExtension,
                $fileName . '-' . $this->getSize($size) . '.' . $fileExtension,
                $url
            );
        }

        preg_match_all('/(.*[0-9|auto])x(.*[0-9|auto])/m', $size, $matches, PREG_SET_ORDER, 0);
        if ($size && $this->canGenerateThumbnails($this->getMimeType($this->getRealPath($url))) && isset($matches[0]) && count($matches[0]) > 0) {
            $matches = Arr::first($matches);

            $query = '';
            if (isset($matches[1]) && $matches[1] != 'auto') {
                $query .= "w={$matches[1]}";
            }
            if (isset($matches[2]) && $matches[2] != 'auto') {
                if (!blank($query)) {
                    $query .= "&";
                }
                $query .= "h={$matches[2]}";
            }

            if (!blank($query)) {
                $url .= "?{$query}";
            }
        }

        if ($relativePath) {
            return $url;
        }

        if ($url == '__image__') {
            return $this->url($default);
        }

        return $this->url($url);
    }

    public function url(?string $path): string
    {
        $path = $path ? trim($path) : $path;

        if (Str::contains($path, ['http://', 'https://'])) {
            return $path;
        }

        /* Prefer .webp if exists for jpg/jpeg/png */
        // Chỉ check WebP với local storage để tránh chậm với cloud storage
        if (!empty($path) && !$this->isUsingCloud()) {
            [$purePath, $query] = array_pad(explode('?', $path, 2), 2, null);
            $ext = strtolower(pathinfo($purePath, PATHINFO_EXTENSION));

            if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                $webpPath = substr($purePath, 0, -strlen($ext)) . 'webp';

                if (Storage::exists($webpPath)) {
                    $path = $webpPath . ($query ? ('?' . $query) : '');
                }
            }
        }
        $doSpacesEnabled = $this->getMediaDriver() === 'do_spaces' && (int) setting('media_do_spaces_cdn_enabled');
        if ($doSpacesEnabled) {
            $customDomain = setting('media_do_spaces_cdn_custom_domain');

            if ($customDomain) {
                return $customDomain . '/' . ltrim($path, '/');
            }

            return str_replace('.digitaloceanspaces.com', '.cdn.digitaloceanspaces.com', Storage::url($path));
        } else {
            if ($this->getMediaDriver() === 'backblaze' && (int) setting('media_backblaze_cdn_enabled')) {
                $customDomain = setting('media_backblaze_cdn_custom_domain');
                $currentEndpoint = setting('media_backblaze_endpoint');
                if ($customDomain) {
                    return $customDomain . '/' . ltrim($path, '/');
                }

                return str_replace($currentEndpoint, $customDomain, Storage::url($path));
            }
        }

        // Nếu path có query params (từ getImageUrl), redirect đến resize endpoint
        // Ví dụ: storage/news/image.jpg?w=300&h=200 → /resize/storage/news/image.jpg?w=300&h=200
        if (str_contains($path, '?')) {
            // Tách path và query để xử lý riêng
            [$purePath, $query] = array_pad(explode('?', $path, 2), 2, null);

            // Kiểm tra xem path đã có /resize/ chưa để tránh loop
            if (Str::contains($purePath, '/resize/')) {
                // Đã có /resize/, chỉ cần return Storage::url với query
                return Storage::url($path);
            }

            // Chỉ thay thế nếu path bắt đầu bằng storage/
            if (Str::startsWith($purePath, 'storage/') || Str::startsWith($purePath, '/storage/')) {
                $resizePath = str_replace(['storage/', '/storage/'], ['resize/storage/', '/resize/storage/'], $purePath);
                $resizeUrl = Storage::url($resizePath);

                // Thêm query params vào URL
                return $resizeUrl . ($query ? ('?' . $query) : '');
            }
        }

        return Storage::url($path);
    }

    /**
     * @param UploadedFile $fileUpload
     * @param int $folderId
     * @param string|null $folderSlug
     * @param bool $skipValidation
     * @param string $visibility
     * @return JsonResponse|array
     */
    public function handleUpload(
        ?UploadedFile $fileUpload,
        int|string|null $folderId = 0,
        ?string $folderSlug = null,
        bool $skipValidation = false,
        string $visibility = 'public'
    ): array {
        // Gọi parent để sử dụng tất cả tính năng mới của AppMedia:
        // - WebP conversion tự động (media_convert_image_to_webp)
        // - Resize hình tự động (media_reduce_large_image_size, media_image_max_width/height)
        // - Validation đầy đủ
        // - Events và hooks
        // - Error handling
        return parent::handleUpload($fileUpload, $folderId, $folderSlug, $skipValidation, $visibility);
    } // you can override this method to add your own logic

    /**
     * Override để xóa cả thumbnails trong public/resize/ (từ PublicController)
     * Đảm bảo logic gốc (xóa thumbnails trong storage) vẫn được thực thi
     * 
     * @param MediaFile $file
     * @return bool
     */
    public function deleteThumbnails(MediaFile $file): bool
    {
        // 1. Gọi parent để xóa thumbnails trong storage (logic gốc từ AppMedia)
        // Xóa các file theo pattern: filename-{size}.ext trong storage
        $parentDeleted = parent::deleteThumbnails($file);

        // 2. Xóa thumbnails vật lý trong public/resize/ (logic mới từ ThumbnailGenerator)
        // Xóa các file theo pattern: resize/{width}x{height}/{subPath}/{normalized}-{hash}.ext
        $physicalDeleted = $this->purgePhysicalThumbnails($file);

        // Trả về true nếu có ít nhất một loại thumbnail được xóa
        return $parentDeleted || $physicalDeleted;
    }

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
