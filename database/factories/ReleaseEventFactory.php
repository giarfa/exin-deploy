<?php

namespace Database\Factories;

use App\Enums\ReleaseEventAction;
use App\Models\Release;
use App\Models\ReleaseEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReleaseEvent>
 */
class ReleaseEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'release_id' => Release::factory(),
            'user_id' => User::factory()->admin(),
            'action' => ReleaseEventAction::ReleaseStarted,
            'payload' => [
                'label' => 'v2.4.0',
                'template' => 'Rilascio standard',
                'steps' => 5,
            ],
        ];
    }

    /**
     * Evento riferito a un singolo step, come la sua chiusura o attivazione.
     */
    public function forStep(string $releaseStepId, ReleaseEventAction $action = ReleaseEventAction::StepCompleted): static
    {
        return $this->state(fn (array $attributes): array => [
            'release_step_id' => $releaseStepId,
            'action' => $action,
            'payload' => null,
        ]);
    }
}
