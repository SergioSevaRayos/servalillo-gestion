<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Los tests no dependen de los assets compilados por Vite: sin esto, en un clon
        // recién hecho (sin `npm run build`) todas las vistas lanzan "Vite manifest not found".
        $this->withoutVite();
    }
}
