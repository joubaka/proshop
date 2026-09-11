<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     *
     * @return void
     */
    public function testBasicTest()
    {
        // The public layout reads system settings; never use the local shop database.
        \Illuminate\Support\Facades\Schema::create('system', function ($table) {
            $table->string('key');
            $table->text('value')->nullable();
        });
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
