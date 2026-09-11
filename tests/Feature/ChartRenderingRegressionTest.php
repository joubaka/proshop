<?php

namespace Tests\Feature;

class ChartRenderingRegressionTest extends \Tests\TestCase
{
    public function test_chart_labels_cannot_escape_inline_script_and_title_uses_v4_options(): void
    {
        $chart = (new \App\Charts\CommonChart)->labels(['</script><script>alert(1)</script>'])->title('Sales')->dataset('Totals', 'column', [10]);
        $script = $chart->script();
        $this->assertSame(1, substr_count($script, '</script>'));
        $this->assertStringContainsString('\\u003C', $script);
        $this->assertStringContainsString('"plugins":{"title":{"display":true,"text":"Sales"}}', $script);
        $this->assertStringContainsString('"type":"bar"', $script);
    }
}
