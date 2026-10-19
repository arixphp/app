<?php

declare(strict_types=1);

namespace Arix\Facades;

use Arix\Runtime\Facade;
use Arix\Storage\Storage as StorageService;

class Storage extends Facade
{
    protected static function getKeyName(): string
    {
        return StorageService::class;
    }
}
