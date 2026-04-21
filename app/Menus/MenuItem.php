<?php

namespace App\Menus;

class MenuItem
{
    public string $title = '';
    public string $url = '';
    public array $attributes = [];
    public bool $isDropdown = false;
    public int $orderValue = 999;
    /** @var MenuItem[] */
    public array $children = [];

    public function order(int $n): static
    {
        $this->orderValue = $n;
        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getIcon(): string
    {
        $icon = $this->attributes['icon'] ?? '';
        return $icon ? '<i class="' . e($icon) . '"></i>' : '';
    }

    public function getAttributes(): string
    {
        $skip = ['icon', 'active'];
        $parts = [];
        foreach ($this->attributes as $k => $v) {
            if (in_array($k, $skip)) {
                continue;
            }
            if ($v === false || $v === null) {
                continue;
            }
            $parts[] = $k . '="' . e((string) $v) . '"';
        }
        return implode(' ', $parts);
    }

    public function isActive(): bool
    {
        return (bool) ($this->attributes['active'] ?? false);
    }

    public function hasActiveOnChild(): bool
    {
        foreach ($this->children as $child) {
            if ($child->isActive() || $child->hasActiveOnChild()) {
                return true;
            }
        }
        return false;
    }

    /** @return MenuItem[] */
    public function getChilds(): array
    {
        return $this->children;
    }
}
