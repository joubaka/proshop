<?php

namespace App\Http;

/**
 * Stub class — nwidart/laravel-menus package removed.
 * This presenter was for the AdminLTE sidebar menu but is no longer used.
 */
class AdminlteCustomPresenter
{
    public function getOpenTagWrapper(): string
    {
        return PHP_EOL . '<ul class="sidebar-menu tree" data-widget="tree">' . PHP_EOL;
    }

    public function getCloseTagWrapper(): string
    {
        return PHP_EOL . '</ul>' . PHP_EOL;
    }

    public function getMenuWithoutDropdownWrapper($item): string
    {
        return '';
    }

    public function getActiveState($item, $state = ' class="active"'): ?string
    {
        return null;
    }

    public function getActiveStateOnChild($item, $state = 'active'): ?string
    {
        return null;
    }

    public function getDividerWrapper(): string
    {
        return '<li class="divider"></li>';
    }

    public function getHeaderWrapper($item): string
    {
        return '<li class="header">' . $item->title . '</li>';
    }

    public function getMenuWithDropDownWrapper($item): string
    {
        return '';
    }

    public function getMultiLevelDropdownWrapper($item): string
    {
        return '';
    }
}
