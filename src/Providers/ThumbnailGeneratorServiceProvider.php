<?php

namespace Platform\ThumbnailGenerator\Providers;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Support\ServiceProvider;

use Platform\Base\Facades\DashboardMenu;
use Platform\Base\Facades\PanelSectionManager;
use Platform\Base\PanelSections\PanelSectionItem;
use Platform\Setting\PanelSections\SettingCommonPanelSection;
use Platform\Kernel\Traits\LoadAndPublishDataTrait;
use Platform\Media\RvMedia as AppMedia;
use Platform\Media\Repositories\Interfaces\MediaFileInterface;
use Platform\Media\Repositories\Interfaces\MediaFolderInterface;
use Platform\Media\Services\UploadsManager;
use Platform\Media\Services\ThumbnailService;
use Platform\ThumbnailGenerator\Facades\ThumbnailMediaFacade;
use Platform\ThumbnailGenerator\ThumbnailMedia;

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
            ->loadHelpers()
        ;

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
