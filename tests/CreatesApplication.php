<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        if (!is_dir(__DIR__.'/.cache')) {
            mkdir(__DIR__.'/.cache', 0755, true);
        }
        $app = require __DIR__.'/../bootstrap/app.php';

        // Do not load server secrets. phpunit.xml also isolates the config-cache path.
        $app->useEnvironmentPath(__DIR__);
        $app->loadEnvironmentFrom('.env.testing');

        $app->make(Kernel::class)->bootstrap();

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('database.connections.sqlite.url', null);

        return $app;
    }
}
