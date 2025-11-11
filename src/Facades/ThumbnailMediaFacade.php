<?php

namespace Dev\ThumbnailGenerator\Facades;

use Dev\ThumbnailGenerator\ThumbnailMedia;
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
