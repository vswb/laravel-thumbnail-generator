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
namespace Dev\ThumbnailGenerator\Providers;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;

use Dev\Kernel\Traits\LoadAndPublishDataTrait;
use Dev\Media\RvMedia as AppMedia;
use Dev\Media\Repositories\Interfaces\MediaFileInterface;
use Dev\Media\Repositories\Interfaces\MediaFolderInterface;
use Dev\Media\Services\UploadsManager;
use Dev\Media\Services\ThumbnailService;
use Dev\ThumbnailGenerator\Facades\ThumbnailMediaFacade;
use Dev\ThumbnailGenerator\ThumbnailMedia;

class ThumbnailGeneratorServiceProvider extends ServiceProvider
{
    use LoadAndPublishDataTrait;

    public function register()
    {
        // Bind ThumbnailMedia as singleton so facade can resolve it
        $this->app->singleton(ThumbnailMedia::class, function ($app) {
            return new ThumbnailMedia(
                $app->make(MediaFileInterface::class),
                $app->make(MediaFolderInterface::class),
                $app->make(UploadsManager::class),
                $app->make(ThumbnailService::class)
            );
        });

        /** 
         * @note các em chú ý: đây là cách rebind AppMedia để sử dụng ThumbnailMedia, 
         * thay vì sửa trực tiếp AppMedia core của Platform*/
        $this->app->singleton(AppMedia::class, function ($app) {
            return $app->make(ThumbnailMedia::class);
        });

        if (class_exists('ThumbnailMediaFacade')) {
            AliasLoader::getInstance()->alias('ThumbnailMediaFacade', ThumbnailMediaFacade::class);
        }
    }

    public function boot()
    {
        $this
            ->setNamespace('packages/thumbnail-generator')
            ->loadRoutes()
            ->loadAndPublishTranslations()
            ->loadMigrations()
            ->loadAndPublishViews()
            ->loadHelpers();

        // Ensure core helpers are loaded before using add_filter
        $this->app->booted(function () {
            // Define constant if not exists (for compatibility)
            if (!defined('BASE_FILTER_AFTER_SETTING_CONTENT')) {
                define('BASE_FILTER_AFTER_SETTING_CONTENT', 'base_filter_after_setting_content');
            }
            
            if (function_exists('add_filter')) {
                add_filter('handle_filter_value_maxsize', [$this, 'handleSetMaxFileSize'], 10, 2);
                add_filter(BASE_FILTER_AFTER_SETTING_CONTENT, [$this, 'renderSetting'], 10, 1);
            }
        });
    }

    public function fileSizeConvert($size)
    {
        return $size * 1024;
    }

    public function handleSetMaxFileSize($value, $ext)
    {
        $allowedMimeTypes = explode(',', app(AppMedia::class)->getConfig('allowed_mime_types'));
        if (in_array($ext, $allowedMimeTypes)) {
            $mimeTypes = get_max_mimesizes();
            $mime = $mimeTypes->firstWhere('type', $ext);
            if (!blank($mime)) {
                return $this->fileSizeConvert(Arr::get($mime, 'size', 2));
            }
        }
        return $value;
    }

    public function renderSetting($template)
    {
        return $template . view('packages/thumbnail-generator::settings')->render();
    }
}
