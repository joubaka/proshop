<?php

namespace App\Charts;

/**
 * Lightweight Chart.js wrapper that replaces the removed consoletvs/charts package.
 * Generates a self-contained <canvas> container and an inline <script> that
 * initialises Chart.js (v2 CDN) with the supplied labels/datasets/options.
 * Requires Chart.js to be present on the page (via CDN or bundled assets).
 */
class CommonChart
{
    protected string $id;
    protected array $labels   = [];
    protected array $datasets = [];
    protected array $options  = [];
    protected ?string $title  = null;

    public function __construct()
    {
        $this->id = 'chart_' . substr(md5(uniqid('', true)), 0, 8);
    }

    public function labels(array $labels): static
    {
        $this->labels = $labels;
        return $this;
    }

    public function dataset(string $name, string $type, array $data): static
    {
        $colors = ['rgba(60,141,188,0.9)', 'rgba(210,214,222,1)', 'rgba(0,192,239,0.9)', 'rgba(0,166,90,0.9)'];
        $idx = count($this->datasets);
        $color = $colors[$idx % count($colors)];
        $this->datasets[] = [
            'label'           => $name,
            'type'            => $type === 'column' ? 'bar' : $type,
            'data'            => array_values($data),
            'backgroundColor' => $color,
            'borderColor'     => $color,
            'borderWidth'     => 1,
        ];
        return $this;
    }

    public function options(array $options): static
    {
        $this->options = $options;
        return $this;
    }

    public function title(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    /** Renders the <canvas> placeholder HTML. */
    public function container(): string
    {
        return '<canvas id="' . e($this->id) . '" style="min-height:250px;height:250px;max-height:250px;max-width:100%;"></canvas>';
    }

    /** Renders the inline <script> that boots Chart.js. */
    public function script(): string
    {
        $config = [
            'type' => 'bar',
            'data' => [
                'labels'   => $this->labels,
                'datasets' => $this->datasets,
            ],
            'options' => array_merge([
                'responsive'          => true,
                'maintainAspectRatio' => false,
            ], $this->options, $this->title ? ['title' => ['display' => true, 'text' => $this->title]] : []),
        ];

        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<HTML
<script>
(function () {
    var ctx = document.getElementById('{$this->id}');
    if (!ctx) { return; }
    new Chart(ctx.getContext('2d'), {$json});
})();
</script>
HTML;
    }
}
