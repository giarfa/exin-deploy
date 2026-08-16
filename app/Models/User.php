<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserLevel;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
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
     * Ruoli che il membro ricopre sui singoli progetti.
     *
     * @return HasMany<ProjectRoleAssignment, $this>
     */
    public function projectRoleAssignments(): HasMany
    {
        return $this->hasMany(ProjectRoleAssignment::class);
    }

    /**
     * Ruoli di cui il membro e responsabile predefinito nel team.
     *
     * @return HasMany<DefaultRoleAssignment, $this>
     */
    public function defaultRoleAssignments(): HasMany
    {
        return $this->hasMany(DefaultRoleAssignment::class);
    }

    /**
     * Indica se il membro puo configurare il sistema e intervenire su qualsiasi release.
     */
    public function isAdministrator(): bool
    {
        return $this->level === UserLevel::Admin;
    }

    /**
     * Indica se l'enrolment della verifica in due passaggi e stato avviato,
     * indipendentemente dal fatto che sia poi stato confermato.
     *
     * Diverso da `hasEnabledTwoFactorAuthentication()` di Fortify, che con
     * `confirm` attivo richiede anche la conferma.
     */
    public function hasStartedTwoFactorEnrolment(): bool
    {
        return ! is_null($this->two_factor_secret);
    }

    /**
     * Chiave di configurazione da inserire a mano quando non si puo inquadrare
     * il QR code.
     *
     * Usa l'encrypter di Fortify e non `decrypt()`: se il progetto configurasse
     * un encrypter dedicato, la funzione globale leggerebbe con la chiave sbagliata.
     */
    public function twoFactorSecretForManualEntry(): string
    {
        return Fortify::currentEncrypter()->decrypt($this->two_factor_secret);
    }

    /**
     * Iniziali del nome, usate come ripiego dell'avatar nell'interfaccia.
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->trim()
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))
            ->implode('');
    }
}
