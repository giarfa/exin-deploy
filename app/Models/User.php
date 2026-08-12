<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserLevel;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * Membro del team abilitato ad accedere allo strumento.
 *
 * I membri non vengono mai cancellati: si disattivano con `is_active`, perche la
 * loro traccia sui rilasci passati deve restare leggibile nel registro (FR-016).
 *
 * Gli attributi con cast sono dichiarati qui perche l'analisi statica deduce il
 * tipo dalla colonna del database (`varchar`, `integer`) e non dal cast: queste
 * annotazioni descrivono il tipo effettivo a runtime.
 *
 * @property UserLevel $level
 * @property bool $is_active
 */
#[Fillable(['name', 'email', 'password', 'level', 'is_active'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'level' => UserLevel::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Indica se il membro puo configurare il sistema e intervenire su qualsiasi release.
     */
    public function isAdministrator(): bool
    {
        return $this->level === UserLevel::Admin;
    }
}
