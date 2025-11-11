<?php

namespace Platform\ThumbnailGenerator\Facades;

use Platform\ThumbnailGenerator\ThumbnailMedia;
use Illuminate\Support\Facades\Facade;

class ThumbnailMediaFacade extends Facade
{
    /**
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return ThumbnailMedia::class;
    }
}
