<?php

namespace App\Services;

/**
 * Minimal replacement for lavary/laravel-menu.
 * Provides Menu::create() and Menu::render() compatible with the AdminLTE sidebar.
 */

class MenuItem
{
    protected int $order = 999;
    protected ?MenuBuilder $children = null;
    public array $attrs = [];

    public function __construct(
        protected string $url,
        protected string $title,
        array $attrs = [],
        ?MenuBuilder $children = null
    ) {
        $this->attrs    = $attrs;
        $this->children = $children;
    }

    public function order(int $order): static
    {
        $this->order = $order;
        return $this;
    }

    public function getOrder(): int
    {
        return $this->order;
    }

    public function render(): string
    {
        $icon   = $this->attrs['icon'] ?? '';
        $active = !empty($this->attrs['active']) ? ' active' : '';
        $id     = isset($this->attrs['id']) ? ' id="' . e($this->attrs['id']) . '"' : '';

        if ($this->children !== null) {
            $subItems = $this->children->getItems();
            if (empty($subItems)) {
                return '';
            }
            $subActive = '';
            foreach ($subItems as $s) {
                if (!empty($s->attrs['active'])) {
                    $subActive = ' active';
                    break;
                }
            }
            $html  = "<li class=\"treeview{$subActive}\"{$id}>";
            $html .= '<a href="#">';
            if ($icon) {
                $html .= "<i class=\"{$icon}\"></i> ";
            }
            $html .= '<span>' . e($this->title) . '</span>';
            $html .= '<span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span>';
            $html .= '</a>';
            $html .= '<ul class="treeview-menu">';
            foreach ($subItems as $sub) {
                $html .= $sub->render();
            }
            $html .= '</ul>';
            $html .= '</li>';
        } else {
            $html  = "<li class=\"{$active}\"{$id}>";
            $html .= '<a href="' . $this->url . '">';
            if ($icon) {
                $html .= "<i class=\"{$icon}\"></i> ";
            }
            $html .= '<span>' . e($this->title) . '</span>';
            $html .= '</a>';
            $html .= '</li>';
        }

        return $html;
    }
}

class MenuBuilder
{
    /** @var MenuItem[] */
    protected array $items = [];

    public function url(string $url, string $title, array $attrs = []): MenuItem
    {
        $item = new MenuItem($url, $title, $attrs);
        $this->items[] = $item;
        return $item;
    }

    public function dropdown(string $title, callable $callback, array $attrs = []): MenuItem
    {
        $sub = new MenuBuilder();
        $callback($sub);
        $item = new MenuItem('#', $title, $attrs, $sub);
        $this->items[] = $item;
        return $item;
    }

    /** @return MenuItem[] */
    public function getItems(): array
    {
        usort($this->items, fn ($a, $b) => $a->getOrder() <=> $b->getOrder());
        return $this->items;
    }

    public function render(): string
    {
        $html = '<ul class="sidebar-menu" data-widget="tree">';
        foreach ($this->getItems() as $item) {
            $html .= $item->render();
        }
        $html .= '</ul>';
        return $html;
    }
}

class MenuManager
{
    /** @var MenuBuilder[] */
    protected array $menus = [];

    public function create(string $name, callable $callback): MenuBuilder
    {
        $builder = new MenuBuilder();
        $callback($builder);
        $this->menus[$name] = $builder;
        return $builder;
    }

    public function render(string $name, string $template = 'adminltecustom'): string
    {
        if (!isset($this->menus[$name])) {
            return '';
        }
        return $this->menus[$name]->render();
    }

    public function instance(string $name): ?MenuBuilder
    {
        return $this->menus[$name] ?? null;
    }
}
