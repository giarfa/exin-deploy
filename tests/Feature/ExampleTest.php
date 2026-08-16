<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * La home di serie restituiva 200 a chiunque. Ora nessuna rotta applicativa
     * e pubblica: l'anonimo viene rediretto all'accesso.
     */
    public function test_the_home_page_requires_authentication(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_the_home_page_is_reachable_once_authenticated(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk();
    }
}
