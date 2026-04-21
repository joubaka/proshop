<?php

namespace App\Charts;

/**
 * Stub class — Charts package removed. Methods are no-ops so existing
 * controller code compiles without the consoletvs/charts package.
 */
class CommonChart
{
    protected $_labels = [];
    protected $_datasets = [];
    protected $_options = [];

    public function labels(array $labels): static
    {
        $this->_labels = $labels;
        return $this;
    }

    public function dataset(string $name, string $type, array $data): static
    {
        $this->_datasets[] = compact('name', 'type', 'data');
        return $this;
    }

    public function options(array $options): static
    {
        $this->_options = $options;
        return $this;
    }

    public function container(): string
    {
        return '';
    }

    public function script(): string
    {
        return '';
    }
}
