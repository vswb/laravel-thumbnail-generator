<?php

namespace Platform\ThumbnailGenerator;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

use Exception;

use Platform\Media\Http\Resources\FileResource;
use Platform\Media\Models\MediaFile;
use Platform\Media\RvMedia as AppMedia;

use function apps_cache_get;
use function apps_cache_store;

class ThumbnailMedia extends AppMedia
{
    /**
     * Flag để tránh infinite loop khi url() được gọi từ nhiều nơi
     * Khi flag này được set, sẽ skip logic resize và chỉ return Storage::url()
     */
    protected static $skipResizeLogic = false;

    /**
     * Track depth của recursive calls để tránh infinite loop
     * Khi depth > 1, có nghĩa là đang trong recursive call
     */
    protected static $urlCallDepth = 0;
    /**
     * @param string|null $url
     * @param null $size
     * @param bool $relativePath
     * @param null $default
     * @return Application|UrlGenerator|string|string[]|null
     */
    public function getImageUrl(
        $url,
        $size = null,
        $relativePath = false,
        $default = null
    ) {
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

        if ($url == $this->getDefaultImage()) {
            return url($url);
        }

        if (
            $size &&
            array_key_exists($size, $this->getSizes()) &&
            $this->canGenerateThumbnails($this->getMimeType($this->getRealPath($url)))
        ) {
            $url = str_replace(
                File::name($url) . '.' . File::extension($url),
                File::name($url) . '-' . $this->getSize($size) . '.' . File::extension($url),
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

    /**
     * @param string|null $path
     * @return string
     */
    public function url(?string $path): string
    {
        // Tăng depth để track recursive calls
        self::$urlCallDepth++;
        
        try {
            $path = $path ? trim($path) : $path;

            // Handle null or empty path
            if (empty($path)) {
                return Storage::url('');
            }

            // Return external URLs as-is
            if (Str::contains($path, 'https://') || Str::contains($path, 'http://')) {
                return $path;
            }

            // Tách path và query ngay từ đầu để xử lý nhất quán
            [$purePath, $query] = array_pad(explode('?', $path, 2), 2, null);
            
            // Kiểm tra xem path đã có /resize/ chưa để tránh loop - return ngay
            // Nếu đã có /resize/, chỉ cần return relative path trực tiếp (không dùng url() helper)
            if (Str::contains($purePath, '/resize/') || Str::contains($purePath, 'resize/')) {
                // Path đã là resize endpoint, return relative path trực tiếp với query params
                // Không dùng url() helper để tránh redirect loop
                $finalPath = '/' . ltrim($purePath, '/') . ($query ? ('?' . $query) : '');
                return $finalPath;
            }

            // Nếu đang skip resize logic (tránh infinite loop), chỉ return Storage::url()
            if (self::$skipResizeLogic) {
                return Storage::url($purePath . ($query ? ('?' . $query) : ''));
            }

            // QUAN TRỌNG: Kiểm tra recursive call depth
            // Nếu depth > 1, có nghĩa là đang trong recursive call, skip logic resize
            if (self::$urlCallDepth > 1) {
                return Storage::url($purePath . ($query ? ('?' . $query) : ''));
            }

            // QUAN TRỌNG: Kiểm tra xem app đã booted chưa
            // Nếu chưa booted (đang trong quá trình register/boot), skip logic resize để tránh loop
            // Khi rebind AppMedia, có thể có code gọi url() trong quá trình khởi tạo
            try {
                $app = app();
                if ($app && !$app->isBooted()) {
                    // App chưa booted, chỉ return Storage::url() để tránh loop
                    return Storage::url($purePath . ($query ? ('?' . $query) : ''));
                }
            } catch (\Exception $e) {
                // Nếu không thể kiểm tra, tiếp tục xử lý
            }

        // Kiểm tra xem có đang trong quá trình xử lý resize request không
        // Nếu có, skip logic resize để tránh loop
        try {
            $request = request();
            if ($request) {
                // Kiểm tra nhiều pattern để chắc chắn
                $path = $request->path();
                $uri = $request->getRequestUri();
                
                // Nếu đang xử lý resize request, không tạo resize URL nữa
                if (strpos($path, 'resize/') === 0 || strpos($uri, '/resize/') !== false) {
                    return Storage::url($purePath . ($query ? ('?' . $query) : ''));
                }
            }
        } catch (\Exception $e) {
            // Nếu không thể lấy request (ví dụ: trong console), skip check này
        }

        // Prefer .webp if exists for jpg/jpeg/png (better compression & performance)
        if (!empty($purePath)) {
            $ext = strtolower(pathinfo($purePath, PATHINFO_EXTENSION));

            if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                $webpPath = substr($purePath, 0, -strlen($ext)) . 'webp';

                if (Storage::exists($webpPath)) {
                    $purePath = $webpPath;
                }
            }
        }

        // DigitalOcean Spaces CDN support
        if (config('filesystems.default') === 'do_spaces' && (int)setting('media_do_spaces_cdn_enabled')) {
            $customDomain = setting('media_do_spaces_cdn_custom_domain');
            $finalPath = $purePath . ($query ? ('?' . $query) : '');

            if ($customDomain) {
                return $customDomain . '/' . ltrim($finalPath, '/');
            }

            return str_replace('.digitaloceanspaces.com', '.cdn.digitaloceanspaces.com', Storage::url($finalPath));
        }

        // Nếu path có query params (từ getImageUrl), redirect đến resize endpoint
        // Ví dụ: storage/news/image.jpg?w=300&h=200 → /resize/storage/news/image.jpg?w=300&h=200
        if ($query !== null) {
            // Chỉ thay thế nếu path bắt đầu bằng storage/
            if (Str::startsWith($purePath, 'storage/') || Str::startsWith($purePath, '/storage/')) {
                // Kiểm tra lại xem có đang trong resize request không (double check)
                try {
                    $request = request();
                    if ($request) {
                        $currentPath = $request->path();
                        $currentUri = $request->getRequestUri();
                        // Nếu đang xử lý resize request, không tạo resize URL nữa
                        if (strpos($currentPath, 'resize/') === 0 || strpos($currentUri, '/resize/') !== false) {
                            return Storage::url($purePath . '?' . $query);
                        }
                    }
                } catch (\Exception $e) {
                    // Nếu không thể lấy request, tiếp tục xử lý
                }
                
                // Normalize path: đảm bảo có leading slash
                $normalizedPath = ltrim($purePath, '/');
                
                // Tạo resize path: thay storage/ thành resize/storage/
                $resizePath = 'resize/' . $normalizedPath;
                
                // Return relative path trực tiếp (không dùng url() helper để tránh redirect loop)
                // url() helper có thể trigger route matching và gây redirect loop
                return '/' . $resizePath . '?' . $query;
            }
        }

        // Return URL không có query hoặc đã xử lý query ở trên
        return Storage::url($purePath . ($query ? ('?' . $query) : ''));
        } finally {
            // Giảm depth sau khi xử lý xong
            self::$urlCallDepth--;
        }
    }

    /**
     * @param UploadedFile $fileUpload
     * @param int $folderId
     * @param string|null $folderSlug
     * @param bool $skipValidation
     * @return JsonResponse|array
     */
    public function handleUpload(
        $fileUpload,
        $folderId = 0,
        $folderSlug = null,
        $skipValidation = false
    ): array {
        $request = request();

        if ($request->input('path')) {
            $folderId = $this->handleTargetFolder($folderId, $request->input('path'));
        }

        if (!$fileUpload) {
            return [
                'error'   => true,
                'message' => trans('core/media::media.can_not_detect_file_type'),
            ];
        }

        $allowedMimeTypes = $this->getConfig('allowed_mime_types');

        if (!$this->isChunkUploadEnabled()) {
            $request->merge(['uploaded_file' => $fileUpload]);

            if (!$skipValidation) {
                $validator = Validator::make($request->all(), [
                    'uploaded_file' => 'required|mimes:' . $allowedMimeTypes,
                ]);

                if ($validator->fails()) {
                    return [
                        'error'   => true,
                        'message' => $validator->getMessageBag()->first(),
                    ];
                }
            }

            $request->offsetUnset('uploaded_file');


            $maxSize = apply_filters('handle_filter_value_maxsize', $this->getServerConfigMaxUploadFileSize(), $fileUpload->getClientOriginalExtension());

            if ($fileUpload->getSize() / 1024 > (int)$maxSize) {
                return [
                    'error'   => true,
                    'message' => trans('core/media::media.file_too_big', ['size' => human_file_size($maxSize)]),
                ];
            }
        }

        try {
            $file = $this->fileRepository->getModel();

            $fileExtension = $fileUpload->getClientOriginalExtension();

            if (!$skipValidation && !in_array(strtolower($fileExtension), explode(',', $allowedMimeTypes))) {
                return [
                    'error'   => true,
                    'message' => trans('core/media::media.can_not_detect_file_type'),
                ];
            }

            if ($folderId == 0 && !empty($folderSlug)) {
                $folder = $this->folderRepository->getFirstBy(['slug' => $folderSlug]);

                if (!$folder) {
                    $folder = $this->folderRepository->createOrUpdate([
                        'user_id'   => Auth::check() ? Auth::id() : 0,
                        'name'      => $this->folderRepository->createName($folderSlug, 0),
                        'slug'      => $this->folderRepository->createSlug($folderSlug, 0),
                        'parent_id' => 0,
                    ]);
                }

                $folderId = $folder->id;
            }

            $file->name = $this->fileRepository->createName(
                File::name($fileUpload->getClientOriginalName()),
                $folderId
            );

            $folderPath = $this->folderRepository->getFullPath($folderId);

            $fileName = $this->fileRepository->createSlug(
                $file->name,
                $fileExtension,
                Storage::path($folderPath)
            );

            $filePath = $fileName;

            if ($folderPath) {
                $filePath = $folderPath . '/' . $filePath;
            }

            $content = File::get($fileUpload->getRealPath());

            $this->uploadManager->saveFile($filePath, $content, $fileUpload);

            $data = $this->uploadManager->fileDetails($filePath);

            if (!$skipValidation && empty($data['mime_type'])) {
                return [
                    'error'   => true,
                    'message' => trans('core/media::media.can_not_detect_file_type'),
                ];
            }

            $file->url = $data['url'];
            $file->size = $data['size'];
            $file->mime_type = $data['mime_type'];
            $file->folder_id = $folderId;
            $file->user_id = Auth::check() ? Auth::id() : 0;
            $file->options = $request->input('options', []);
            $file = $this->fileRepository->createOrUpdate($file);

            if ($file instanceof MediaFile) {
                $this->generateThumbnails($file);

                // Convert to WebP if applicable
                // Wrap in try-catch to ensure upload doesn't fail if WebP conversion fails
                try {
                    $this->convertToWebP($file);
                } catch (Exception $e) {
                    // Log error but don't fail upload
                    Log::error('WebP conversion failed in ThumbnailMedia', [
                        'file_id' => $file->id ?? null,
                        'file_url' => $file->url ?? null,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            return [
                'error' => false,
                'data'  => new FileResource($file),
            ];
        } catch (Exception $exception) {
            return [
                'error'   => true,
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function deleteThumbnails(MediaFile $file): bool
    {
        $parentDeleted = parent::deleteThumbnails($file);
        $physicalDeleted = $this->purgePhysicalThumbnails($file);

        return $parentDeleted || $physicalDeleted;
    }

    /**
     * Xóa thumbnails vật lý trong public/resize/ được tạo bởi PublicController
     * 
     * Hỗ trợ CẢ HAI cấu trúc cache:
     * - NEW (no mtime): resize/{width}x{height}/{subPath}/{normalized}-{hash}.{ext}
     * - OLD (with mtime): resize/{width}x{height}/{mtime}/{subPath}/{normalized}-{hash}.{ext}
     * 
     * Pattern matching giống với PublicController::getCachedFilePath()
     */
    protected function purgePhysicalThumbnails(MediaFile $file): bool
    {
        $slug = ltrim((string) $file->url, '/');

        if ($slug === '') {
            return false;
        }

        $basePath = public_path('resize');

        if (! File::isDirectory($basePath)) {
            return false;
        }

        // Tạo pattern giống với PublicController::getCachedFilePath()
        $fileName = pathinfo($slug, PATHINFO_FILENAME);
        $normalized = Str::slug($fileName);
        if ($normalized === '') {
            $normalized = 'thumbnail';
        }

        // Hash giống với PublicController (md5 của full slug, lấy 12 ký tự đầu)
        $hash = substr(md5($slug), 0, 12);
        $pattern = $normalized . '-' . $hash;

        // Lấy subPath để tìm trong đúng thư mục con
        $subPath = pathinfo($slug, PATHINFO_DIRNAME);
        $relativeDir = (!blank($subPath) && $subPath !== '.') ? $subPath : null;

        $deleted = false;

        // Duyệt qua tất cả các thư mục size (ví dụ: resize/300x200, resize/500x300)
        foreach (File::directories($basePath) as $sizeDirectory) {

            // 1. Check NEW structure (no mtime): resize/{width}x{height}/{subPath}/
            $targetDirectory = $sizeDirectory;
            if ($relativeDir) {
                $targetDirectory .= DIRECTORY_SEPARATOR . $relativeDir;
            }

            // Không cần File::isDirectory() vì glob trả [] nếu directory không tồn tại
            $files = File::glob($targetDirectory . DIRECTORY_SEPARATOR . $pattern . '.*') ?: [];
            foreach ($files as $path) {
                if (File::delete($path)) {
                    $deleted = true;
                }
            }

            // Cleanup empty directories (NEW structure) - chỉ khi có file deleted
            if (!empty($files)) {
                $this->cleanupEmptyDirectoriesNew($targetDirectory, $sizeDirectory, $basePath);
            }

            // 2. Check OLD structure (with mtime): resize/{width}x{height}/{mtime}/{subPath}/
            // Backward compatibility với cache cũ có mtime folder
            foreach (File::directories($sizeDirectory) as $mtimeDirectory) {
                // Skip nếu không phải timestamp (mtime folder phải là số)
                if (!is_numeric(basename($mtimeDirectory))) {
                    continue;
                }

                $oldTargetDirectory = $mtimeDirectory;
                if ($relativeDir) {
                    $oldTargetDirectory .= DIRECTORY_SEPARATOR . $relativeDir;
                }

                // Không cần File::isDirectory() vì glob trả [] nếu directory không tồn tại
                $files = File::glob($oldTargetDirectory . DIRECTORY_SEPARATOR . $pattern . '.*') ?: [];
                foreach ($files as $path) {
                    if (File::delete($path)) {
                        $deleted = true;
                    }
                }

                // Cleanup empty directories (OLD structure) - chỉ khi có file deleted
                if (!empty($files)) {
                    $this->cleanupEmptyDirectoriesOld($oldTargetDirectory, $mtimeDirectory, $sizeDirectory, $basePath);
                }

                // Xóa mtime directory nếu rỗng (dùng @ để suppress warning khi không tồn tại)
                @rmdir($mtimeDirectory);
            }

            // Xóa size directory nếu rỗng (dùng @ để suppress warning khi không tồn tại)
            @rmdir($sizeDirectory);
        }

        // Xóa thư mục resize nếu rỗng
        if ($this->isDirectoryEmpty($basePath)) {
            File::deleteDirectory($basePath);
        }

        return $deleted;
    }

    /**
     * Cleanup empty directories cho cấu trúc NEW (không có mtime folder)
     * Ví dụ: resize/300x200/storage/news/ -> resize/300x200/storage/ -> resize/300x200/
     */
    protected function cleanupEmptyDirectoriesNew(string $start, string $sizeDir, string $basePath): void
    {
        $directories = [$start];

        // Duyệt ngược từ $start đến $sizeDir
        $current = dirname($start);
        while ($current !== $sizeDir && Str::startsWith($current, $sizeDir)) {
            $directories[] = $current;
            $current = dirname($current);
        }

        $directories[] = $sizeDir;

        // Xóa các thư mục rỗng từ trong ra ngoài
        foreach ($directories as $dir) {
            if ($dir === $basePath) {
                continue;
            }

            // Dùng @rmdir thay vì isDirectoryEmpty + deleteDirectory (nhanh hơn)
            @rmdir($dir);
        }
    }

    /**
     * Cleanup empty directories cho cấu trúc OLD (có mtime folder)
     * Ví dụ: resize/300x200/123456/storage/news/ -> resize/300x200/123456/storage/ -> resize/300x200/123456/
     */
    protected function cleanupEmptyDirectoriesOld(string $start, string $mtimeDir, string $sizeDir, string $basePath): void
    {
        $directories = [$start];

        // Duyệt ngược từ $start đến $mtimeDir
        $current = dirname($start);
        while ($current !== $mtimeDir && Str::startsWith($current, $mtimeDir)) {
            $directories[] = $current;
            $current = dirname($current);
        }

        $directories[] = $mtimeDir;
        $directories[] = $sizeDir;

        // Xóa các thư mục rỗng từ trong ra ngoài
        foreach ($directories as $dir) {
            if ($dir === $basePath) {
                continue;
            }

            // Dùng @rmdir thay vì isDirectoryEmpty + deleteDirectory (nhanh hơn)
            @rmdir($dir);
        }
    }

    /**
     * Check if directory is empty (optimized with iterator instead of scandir)
     * 
     * @param string $directory
     * @return bool
     */
    protected function isDirectoryEmpty(string $directory): bool
    {
        if (! File::isDirectory($directory)) {
            return true;
        }

        // Dùng iterator thay vì scandir() - nhanh hơn vì không cần load toàn bộ directory
        $iterator = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);
        return !$iterator->valid();
    }
}
