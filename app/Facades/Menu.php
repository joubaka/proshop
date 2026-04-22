<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \App\Services\MenuBuilder create(string $name, callable $callback)
 * @method static string render(string $name, string $template = 'adminltecustom')
 * @method static \App\Services\MenuBuilder|null instance(string $name)
 *
 * @see \App\Services\MenuManager
 */
class Menu extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \App\Services\MenuManager::class;
    }
}
