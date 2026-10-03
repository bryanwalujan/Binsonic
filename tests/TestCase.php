<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'binsonic_terra_testing') {
            throw new \RuntimeException('Tes wajib memakai database MySQL khusus binsonic_terra_testing.');
        }

        return $app;
    }
}
