<?php

namespace Dev\ThumbnailGenerator\Providers;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

use Dev\Base\Facades\DashboardMenu;
use Dev\Base\Facades\PanelSectionManager;
use Dev\Base\PanelSections\PanelSectionItem;
use Dev\Base\Supports\ServiceProvider;
use Dev\Setting\PanelSections\SettingCommonPanelSection;
use Dev\Kernel\Traits\LoadAndPublishDataTrait;
use Dev\Media\AppMedia;
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
                $app->make(UploadsManager::class),
                $app->make(ThumbnailService::class)
            );
        });

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
            ->setNamespace('libs/thumbnail-generator')
            ->loadRoutes()
            ->loadAndPublishTranslations()
            ->loadMigrations()
            ->loadAndPublishViews()
            ->loadHelpers();

        // Ensure core helpers are loaded before using add_filter
        $this->app->booted(function () {
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
        $allowedMimeTypes = explode(',', AppMedia::getConfig('allowed_mime_types'));
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
        return $template . view('libs/thumbnail-generator::settings')->render();
    }
}
