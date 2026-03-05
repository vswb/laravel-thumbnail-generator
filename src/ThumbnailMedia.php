<?php
/**
 * (c) Copyright 2026 VISUAL WEBER COMPANY LIMITED. All rights reserved.
 * Distributed by: VISUAL WEBER CO., LTD.
 * * [PRODUCT INFORMATION]
 * This software is a proprietary product developed by Visual Weber.
 * All rights to the software and its components are reserved under 
 * Intellectual Property laws.
 * * [TERMS OF USE]
 * Usage is permitted strictly according to the License Agreement 
 * between Visual Weber and the Client.
 * -------------------------------------------------------------------------
 * (c) Bản quyền thuộc về CÔNG TY TNHH VISUAL WEBER 2026. Bảo lưu mọi quyền.
 * Phát hành bởi: Công ty TNHH Visual Weber.
 * * [THÔNG TIN SẢN PHẨM]
 * Phần mềm này là sản phẩm độc quyền được phát triển bởi Visual Weber.
 * Mọi quyền đối với phần mềm và các thành phần cấu thành đều được bảo hộ 
 * theo luật Sở hữu trí tuệ.
 * * [ĐIỀU KHOẢN SỬ DỤNG]
 * Việc sử dụng được giới hạn nghiêm ngặt theo Hợp đồng cung cấp dịch vụ/phần mềm 
 * giữa Visual Weber và Khách hàng.
 */
namespace Dev\ThumbnailGenerator;

use Illuminate\Contracts\Filesystem\FileExistsException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

use Exception;
use Image;
use League\Flysystem\FileNotFoundException;
use Mimey\MimeTypes;
use Throwable;

use Dev\Media\Http\Resources\FileResource;
use Dev\Media\Models\MediaFile;
use Dev\Media\RvMedia as AppMedia;
use Dev\Media\Repositories\Interfaces\MediaFileInterface;
use Dev\Media\Repositories\Interfaces\MediaFolderInterface;
use Dev\Media\Services\ThumbnailService;
use Dev\Media\Services\UploadsManager;

use function apps_cache_get;
use function apps_cache_store;

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
        $url,
        $size = null,
        $relativePath = false,
        $default = null
    ) {
        if (env('ENABLED_WEBP', false) === false) {
            return parent::getImageUrl($url, $size, $relativePath, $default);
        }

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
     * Generate the URL for a given path, with WebP and resize support
     * Hàm này đang làm cho URL bị redirect vòng lặp khi dùng với getImageUrl có query params
     * 
     * Tạm rename để tranh xung đột với parent::url()
     * 
     * @param string|null $path
     * @return string
     */
    public function url_tmp(?string $path): string
    {
        if (env('ENABLED_WEBP', false) === false) {
            return parent::url($path);
        }

        $path = $path ? trim($path) : $path;

        // Handle null or empty path
        if (empty($path)) {
            return Storage::url('');
        }

        // Return external URLs as-is
        if (Str::contains($path, 'https://') || Str::contains($path, 'http://')) {
            return $path;
        }

        // Prefer .webp if exists for jpg/jpeg/png (better compression & performance)
        if (!empty($path)) {
            [$purePath, $query] = array_pad(explode('?', $path, 2), 2, null);
            $ext = strtolower(pathinfo($purePath, PATHINFO_EXTENSION));

            if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                $webpPath = substr($purePath, 0, -strlen($ext)) . 'webp';

                if (Storage::exists($webpPath)) {
                    $path = $webpPath . ($query ? ('?' . $query) : '');
                }
            }
        }

        // DigitalOcean Spaces CDN support
        if (config('filesystems.default') === 'do_spaces' && (int) setting('media_do_spaces_cdn_enabled')) {
            $customDomain = setting('media_do_spaces_cdn_custom_domain');

            if ($customDomain) {
                return $customDomain . '/' . ltrim($path, '/');
            }

            return str_replace('.digitaloceanspaces.com', '.cdn.digitaloceanspaces.com', Storage::url($path));
        }

        // Nếu path có query params (từ getImageUrl), redirect đến resize endpoint
        // Ví dụ: storage/news/image.jpg?w=300&h=200 → /resize/storage/news/image.jpg?w=300&h=200
        if (Str::contains($path, '?')) {
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
     * @return JsonResponse|array
     */
    public function handleUpload(
        $fileUpload,
        $folderId = 0,
        $folderSlug = null,
        $skipValidation = false
    ): array {
        if (env('ENABLED_WEBP', false) === false) {
            return parent::handleUpload($fileUpload, $folderId, $folderSlug, $skipValidation);
        }

        $request = request();

        if ($request->input('path')) {
            $folderId = $this->handleTargetFolder($folderId, $request->input('path'));
        }

        if (!$fileUpload) {
            return [
                'error' => true,
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
                        'error' => true,
                        'message' => $validator->getMessageBag()->first(),
                    ];
                }
            }

            $request->offsetUnset('uploaded_file');


            $maxSize = apply_filters('handle_filter_value_maxsize', $this->getServerConfigMaxUploadFileSize(), $fileUpload->getClientOriginalExtension());

            if ($fileUpload->getSize() / 1024 > (int) $maxSize) {
                return [
                    'error' => true,
                    'message' => trans('core/media::media.file_too_big', ['size' => human_file_size($maxSize)]),
                ];
            }
        }

        // Check image width for image files (applies to both chunk and non-chunk uploads)
        if (!$skipValidation) {
            $mimeType = $fileUpload->getMimeType();
            if ($this->isImage($mimeType) && $this->canGenerateThumbnails($mimeType)) {
                try {
                    $image = Image::make($fileUpload->getRealPath());
                    $width = $image->width();

                    if ($width > 1920) {
                        return [
                            'error' => true,
                            'message' => trans('core/media::media.image_width_too_large', ['max_width' => 1920, 'current_width' => $width]),
                        ];
                    }
                } catch (Exception $e) {
                    // If we can't read the image, continue with upload
                    // This handles cases where the file might be corrupted or not a valid image
                }
            }
        }

        try {
            $file = $this->fileRepository->getModel();

            $fileExtension = $fileUpload->getClientOriginalExtension();

            if (!$skipValidation && !in_array(strtolower($fileExtension), explode(',', $allowedMimeTypes))) {
                return [
                    'error' => true,
                    'message' => trans('core/media::media.can_not_detect_file_type'),
                ];
            }

            if ($folderId == 0 && !empty($folderSlug)) {
                $folder = $this->folderRepository->getFirstBy(['slug' => $folderSlug]);

                if (!$folder) {
                    $folder = $this->folderRepository->createOrUpdate([
                        'user_id' => Auth::check() ? Auth::id() : 0,
                        'name' => $this->folderRepository->createName($folderSlug, 0),
                        'slug' => $this->folderRepository->createSlug($folderSlug, 0),
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
                    'error' => true,
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
                'data' => new FileResource($file),
            ];
        } catch (Exception $exception) {
            return [
                'error' => true,
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
     * @param MediaFile|Model $file
     * @return bool
     */
    public function generateThumbnails(MediaFile $file): bool
    {
        if (!$file->canGenerateThumbnails()) {
            return false;
        }

        $thumbnailPaths = [];

        foreach ($this->getSizes() as $size) {
            $readableSize = explode('x', $size);

            $thumbnailPath = $this->thumbnailService
                ->setImage($this->getRealPath($file->url))
                ->setSize($readableSize[0], $readableSize[1])
                ->setDestinationPath(File::dirname($file->url))
                ->setFileName(File::name($file->url) . '-' . $size . '.' . File::extension($file->url))
                ->save();

            if ($thumbnailPath) {
                $thumbnailPaths[] = $thumbnailPath;
            }
        }

        $this->insertWatermark($file->url);

        // Convert thumbnails to WebP
        // Wrap in try-catch to ensure upload doesn't fail if WebP conversion fails
        try {
            $this->convertThumbnailsToWebP($file, $thumbnailPaths);
        } catch (Exception $e) {
            // Log error but don't fail upload
            Log::error('WebP conversion failed in ThumbnailMedia', [
                'file_id' => $file->id ?? null,
                'file_url' => $file->url ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return true;
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

        if (!File::isDirectory($basePath)) {
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
        if (!File::isDirectory($directory)) {
            return true;
        }

        // Dùng iterator thay vì scandir() - nhanh hơn vì không cần load toàn bộ directory
        $iterator = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);
        return !$iterator->valid();
    }

    /**
     * Convert image to WebP format and save to database
     * @param MediaFile $file
     * @return MediaFile|null
     */
    protected function convertToWebP(MediaFile $file)
    {
        // Log that conversion is being attempted
        Log::info('WebP conversion attempt', [
            'file_id' => $file->id ?? null,
            'file_url' => $file->url ?? null,
            'mime_type' => $file->mime_type ?? null,
        ]);

        // Only convert jpg, jpeg, png images
        $convertibleTypes = ['image/jpeg', 'image/jpg', 'image/png'];
        if (!in_array($file->mime_type, $convertibleTypes)) {
            Log::info('Skipping WebP conversion - not a convertible type', [
                'file_id' => $file->id ?? null,
                'mime_type' => $file->mime_type ?? null
            ]);
            return null;
        }

        // Check if already converted to WebP
        if ($file->mime_type === 'image/webp') {
            Log::info('Skipping WebP conversion - already WebP', [
                'file_id' => $file->id ?? null
            ]);
            return null;
        }

        // Check if WebP already exists
        $webpPath = str_replace('.' . File::extension($file->url), '.webp', $file->url);
        if (Storage::exists($webpPath)) {
            // WebP file exists, just update the record
            Log::info('WebP file already exists, updating record', [
                'file_id' => $file->id ?? null,
                'webp_path' => $webpPath
            ]);
            $webpData = $this->uploadManager->fileDetails($webpPath);
        } else {
            try {
                $imagePath = $this->getRealPath($file->url);

                Log::info('Starting WebP conversion', [
                    'file_id' => $file->id ?? null,
                    'original_path' => $file->url ?? null,
                    'image_path' => $imagePath,
                    'webp_path' => $webpPath,
                    'storage_driver' => config('filesystems.default')
                ]);

                // Check if file exists (for local storage)
                if (config('filesystems.default') === 'local' || config('filesystems.default') === 'public') {
                    if (!File::exists($imagePath)) {
                        Log::warning('Skipping WebP conversion - source file not found', [
                            'file_id' => $file->id ?? null,
                            'image_path' => $imagePath
                        ]);
                        return null;
                    }
                }

                // Create WebP image
                $image = Image::make($imagePath);

                // Encode to WebP with quality 85 (good balance between quality and file size)
                $webpContent = $image->encode('webp', 85);

                if (!$webpContent || empty($webpContent->__toString())) {
                    throw new Exception('WebP encoding returned empty result');
                }

                // Save WebP file
                $this->uploadManager->saveFile($webpPath, $webpContent->__toString());

                Log::info('WebP file saved successfully', [
                    'file_id' => $file->id ?? null,
                    'webp_path' => $webpPath
                ]);

                // Get WebP file details
                $webpData = $this->uploadManager->fileDetails($webpPath);
            } catch (Exception $e) {
                // Log detailed error for debugging
                Log::error('Failed to convert image to WebP', [
                    'file_id' => $file->id ?? null,
                    'file_url' => $file->url ?? null,
                    'mime_type' => $file->mime_type ?? null,
                    'error_message' => $e->getMessage(),
                    'error_trace' => $e->getTraceAsString(),
                    'gd_loaded' => extension_loaded('gd'),
                    'imagick_loaded' => extension_loaded('imagick'),
                ]);

                // Check GD WebP support if available
                if (extension_loaded('gd')) {
                    $gdInfo = gd_info();
                    Log::error('GD info', ['gd_info' => $gdInfo]);
                }

                return null;
            }
        }

        // Use transaction to ensure data consistency
        return DB::transaction(function () use ($file, $webpData, $webpPath) {
            // Store original file info in options
            $originalOptions = $file->options ?? [];
            if (!is_array($originalOptions)) {
                $originalOptions = [];
            }
            $originalOptions['original_file_url'] = $file->url;
            $originalOptions['original_mime_type'] = $file->mime_type;
            $originalOptions['original_size'] = $file->size;
            $originalOptions['converted_to_webp'] = true;
            $originalOptions['converted_at'] = now()->toDateTimeString();

            // Delete original JPG/PNG file from storage
            $originalFilePath = $file->url;
            if (Storage::exists($originalFilePath)) {
                Storage::delete($originalFilePath);
            }

            // Update original record to point to WebP file
            // Ensure name has extension
            $fileName = File::name($file->name);
            $fileExtension = File::extension($file->url);
            if (empty($fileExtension)) {
                // If no extension in name, get from original mime type
                $fileExtension = $file->mime_type === 'image/jpeg' ? 'jpg' : 'png';
            }

            $file->name = $fileName . '.webp';
            $file->url = $webpData['url'];
            $file->size = $webpData['size'];
            $file->mime_type = 'image/webp';
            $file->options = $originalOptions;
            $file = $this->fileRepository->createOrUpdate($file);

            return $file;
        });
    }

    /**
     * Convert thumbnails to WebP format
     * @param MediaFile $file
     * @param array $thumbnailPaths
     * @return void
     */
    protected function convertThumbnailsToWebP(MediaFile $file, array $thumbnailPaths)
    {
        // Only convert if original file is jpg, jpeg, or png (before conversion)
        $convertibleTypes = ['image/jpeg', 'image/jpg', 'image/png'];
        $originalMimeType = $file->mime_type;

        // Check original mime type from options if already converted
        if ($file->mime_type === 'image/webp') {
            $options = $file->options ?? [];
            $originalMimeType = $options['original_mime_type'] ?? 'image/jpeg';
        }

        if (!in_array($originalMimeType, $convertibleTypes)) {
            return;
        }

        $webpThumbnails = [];

        foreach ($thumbnailPaths as $thumbnailPath) {
            try {
                // Generate WebP path for thumbnail
                $webpThumbnailPath = str_replace('.' . File::extension($thumbnailPath), '.webp', $thumbnailPath);

                // Skip if WebP already exists
                if (Storage::exists($webpThumbnailPath)) {
                    $webpThumbnails[] = $webpThumbnailPath;
                    // Delete original JPG thumbnail if WebP exists
                    if (Storage::exists($thumbnailPath)) {
                        Storage::delete($thumbnailPath);
                    }
                    continue;
                }

                // Get thumbnail file path
                $thumbnailFilePath = $this->getRealPath($thumbnailPath);

                // Check if file exists (for local storage)
                if (config('filesystems.default') === 'local' || config('filesystems.default') === 'public') {
                    if (!File::exists($thumbnailFilePath)) {
                        continue;
                    }
                }

                // Create WebP image from thumbnail
                $thumbnailImage = Image::make($thumbnailFilePath);

                // Encode to WebP with quality 85
                $webpContent = $thumbnailImage->encode('webp', 85);

                // Save WebP thumbnail
                $this->uploadManager->saveFile($webpThumbnailPath, $webpContent->__toString());

                // Delete original JPG thumbnail
                if (Storage::exists($thumbnailPath)) {
                    Storage::delete($thumbnailPath);
                }

                $webpThumbnails[] = $webpThumbnailPath;
            } catch (Exception $e) {
                // Log error but continue with other thumbnails
                Log::error('Failed to convert thumbnail to WebP: ' . $thumbnailPath . ' - ' . $e->getMessage());
            }
        }

        // Store WebP thumbnail paths in file options
        if (!empty($webpThumbnails)) {
            // Use transaction to ensure data consistency
            DB::transaction(function () use ($file, $webpThumbnails) {
                // Refresh file to get latest options (including WebP info from convertToWebP)
                $file->refresh();
                $options = $file->options ?? [];
                if (!is_array($options)) {
                    $options = [];
                }
                $options['webp_thumbnails'] = $webpThumbnails;
                $file->options = $options;
                $this->fileRepository->createOrUpdate($file);
            });
        }
    }
}
