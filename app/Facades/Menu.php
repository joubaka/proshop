<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void create(string $name, \Closure $callback)
 * @method static string render(string $name, string $presenter = 'default')
 * @method static \App\Menus\MenuBuilder|null instance(string $name)
 */
class Menu extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'menu.manager';
    }
}
