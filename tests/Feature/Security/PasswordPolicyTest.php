<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Verifica che `Password::defaults()` registrato in AppServiceProvider sia
 * effettivamente applicato da ogni validazione che usa `Password::default()`,
 * incluse le action di Fortify per reset e aggiornamento password.
 */
class PasswordPolicyTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function weakPasswords(): array
    {
        return [
            'troppo corta' => ['Ab1!', 'meno di 8 caratteri'],
            'senza maiuscole' => ['rilascio-2026!', 'nessuna maiuscola'],
            'senza numeri' => ['Rilascio-Test!', 'nessuna cifra'],
            'senza simboli' => ['Rilascio2026', 'nessun simbolo'],
        ];
    }

    /**
     * @param  string  $password  Password che deve essere rifiutata
     * @param  string  $reason  Motivo atteso del rifiuto, usato nel messaggio di errore
     */
    #[DataProvider('weakPasswords')]
    public function test_it_rejects_a_weak_password(string $password, string $reason): void
    {
        $validator = Validator::make(
            ['password' => $password],
            ['password' => ['required', 'string', Password::default()]]
        );

        $this->assertTrue(
            $validator->fails(),
            "La password [{$password}] doveva essere rifiutata: {$reason}."
        );
    }

    public function test_it_accepts_a_compliant_password(): void
    {
        $validator = Validator::make(
            ['password' => 'Rilascio-2026!'],
            ['password' => ['required', 'string', Password::default()]]
        );

        $this->assertFalse($validator->fails(), $validator->errors()->first('password') ?: '');
    }
}
