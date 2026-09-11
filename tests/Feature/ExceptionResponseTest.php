<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ExceptionResponseTest extends TestCase
{
    public function test_production_json_errors_do_not_expose_exception_details(): void
    {
        config(['app.debug' => false]);
        Route::get('/test-error', function () {
            throw new \RuntimeException('Database credentials and query details must stay private');
        });
        $this->getJson('/test-error')->assertStatus(500)->assertExactJson([
            'error' => true, 'message' => 'Internal Server Error',
        ]);
    }
}
