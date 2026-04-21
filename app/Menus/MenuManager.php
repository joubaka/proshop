<?php

namespace App\Menus;

class MenuManager
{
    /** @var array<string, MenuBuilder> */
    protected array $menus = [];

    /** @var array<string, string> */
    protected array $presenters = [];

    public function create(string $name, \Closure $callback): void
    {
        $builder = new MenuBuilder();
        $callback($builder);
        $this->menus[$name] = $builder;
    }

    public function instance(string $name): ?MenuBuilder
    {
        return $this->menus[$name] ?? null;
    }

    public function render(string $name, string $presenter = 'default'): string
    {
        $builder = $this->menus[$name] ?? null;
        if (!$builder) {
            return '';
        }

        $presenterClass = $this->presenters[$presenter] ?? null;
        if ($presenterClass && class_exists($presenterClass)) {
            $p = new $presenterClass($builder->getSortedItems());
            return $p->render();
        }

        // Default: AdminlteCustomPresenter
        $p = new \App\Http\AdminlteCustomPresenter($builder->getSortedItems());
        return $p->render();
    }

    public function registerPresenter(string $name, string $class): void
    {
        $this->presenters[$name] = $class;
    }
}
