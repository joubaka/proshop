<?php

namespace App\Menus;

class MenuBuilder
{
    /** @var MenuItem[] */
    protected array $items = [];

    public function url(string $url, string $title, array $attributes = []): MenuItem
    {
        $item = new MenuItem();
        $item->url = $url;
        $item->title = $title;
        $item->attributes = $attributes;
        $item->isDropdown = false;
        $this->items[] = $item;
        return $item;
    }

    public function dropdown(string $title, \Closure $callback, array $attributes = []): MenuItem
    {
        $sub = new MenuBuilder();
        $callback($sub);

        $item = new MenuItem();
        $item->title = $title;
        $item->attributes = $attributes;
        $item->isDropdown = true;
        $item->children = $sub->getSortedItems();
        $this->items[] = $item;
        return $item;
    }

    /** @return MenuItem[] */
    public function getSortedItems(): array
    {
        $items = $this->items;
        usort($items, fn($a, $b) => $a->orderValue <=> $b->orderValue);
        return $items;
    }
}
