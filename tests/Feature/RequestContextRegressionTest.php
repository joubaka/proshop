<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Tests\Support\RegressionTestCase;

class RequestContextRegressionTest extends RegressionTestCase
{
    public function test_mobile_detection_accepts_requests_without_user_agent(): void
    {
        $this->app->instance('request', Request::create('/'));
        $this->assertFalse(isMobile());
    }

    public function test_mobile_detection_uses_the_current_request_header(): void
    {
        $this->app->instance('request', Request::create('/', 'GET', [], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
        ]));
        $this->assertTrue(isMobile());
    }

    public function test_compiled_time_directives_respect_each_business_at_render_time(): void
    {
        date_default_timezone_set('Africa/Johannesburg');
        session()->put('business.date_format', 'd/m/Y');
        session()->put('business.time_format', 12);
        $directives = app('blade.compiler')->getCustomDirectives();
        $time = $directives['format_time']("'2026-09-03 15:45:00'");
        $datetime = $directives['format_datetime']("'2026-09-03 15:45:00'");
        $this->assertSame('03:45 PM', eval('return '.$time.';'));
        session()->put('business.time_format', 24);
        $this->assertSame('15:45', eval('return '.$time.';'));
        $this->assertSame('03/09/2026 15:45', eval('return '.$datetime.';'));
        session()->forget('business.time_format');
        $this->assertSame('15:45', eval('return '.$time.';'));
    }
}
