<?php

namespace Database\Seeders;

use App\Enums\UserLevel;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Dataset dimostrativo del team.
 *
 * Qui vivono soltanto i membri: lo scenario completo (progetti, template di
 * workflow, release a meta catena e release conclusa) appartiene a US-011.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Password condivisa dei soli account dimostrativi in sviluppo.
     */
    private const DEMO_PASSWORD = 'Rilascio-2026!';

    /**
     * Membri fissi del team, con nomi coerenti con i mockup della superficie operativa.
     *
     * @var list<array{name: string, email: string, level: UserLevel, is_active: bool}>
     */
    private const TEAM = [
        [
            'name' => 'Francesco Giarola',
            'email' => 'f.giarola@gruppoexcellence.com',
            'level' => UserLevel::Admin,
            'is_active' => true,
        ],
        [
            'name' => 'Luca Serra',
            'email' => 'l.serra@gruppoexcellence.com',
            'level' => UserLevel::Member,
            'is_active' => true,
        ],
        [
            'name' => 'Marta Bellini',
            'email' => 'm.bellini@gruppoexcellence.com',
            'level' => UserLevel::Member,
            'is_active' => true,
        ],
        [
            'name' => 'Davide Rossi',
            'email' => 'd.rossi@gruppoexcellence.com',
            'level' => UserLevel::Member,
            'is_active' => true,
        ],
        [
            'name' => 'Chiara Fumagalli',
            'email' => 'c.fumagalli@gruppoexcellence.com',
            'level' => UserLevel::Member,
            'is_active' => true,
        ],
        [
            'name' => 'Paolo Venturi',
            'email' => 'p.venturi@gruppoexcellence.com',
            'level' => UserLevel::Member,
            'is_active' => false,
        ],
    ];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        foreach (self::TEAM as $member) {
            User::factory()->create([
                ...$member,
                'password' => Hash::make(self::DEMO_PASSWORD),
            ]);
        }
    }
}
