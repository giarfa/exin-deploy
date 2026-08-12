<?php

namespace Tests\Unit;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Smoke test: l'applicazione si avvia in ambiente di test.
     *
     * Sostituisce l'`assertTrue(true)` dello scheletro Laravel, che Larastan
     * segnala correttamente come tautologia (`method.alreadyNarrowedType`).
     */
    public function test_application_boots_in_testing_environment(): void
    {
        $this->assertSame('testing', $this->app->environment());
    }
}
