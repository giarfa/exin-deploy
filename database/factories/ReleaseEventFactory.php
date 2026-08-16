<?php

namespace Database\Factories;

use App\Enums\ReleaseEventAction;
use App\Models\Release;
use App\Models\ReleaseEvent;
use App\Models\ReleaseStep;
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

    /*
     * Gli stati che seguono portano i payload che le Action scrivono **davvero**
     * (`CloseStep`, `RecordUnauthorizedStepAttempt`), chiave per chiave. Uno stato
     * con un payload inventato produrrebbe test verdi su una resa che nessun evento
     * reale attiva: il registro mostrerebbe righe corrette in prova e righe mute in
     * produzione.
     */

    /**
     * Chiusura di uno step.
     */
    public function stepCompleted(ReleaseStep $step): static
    {
        return $this->state(fn (array $attributes): array => [
            'release_id' => $step->release_id,
            'release_step_id' => $step->id,
            'action' => ReleaseEventAction::StepCompleted,
            'payload' => [
                'position' => $step->position,
                'step' => $step->name,
                'fields_filled' => $step->fields()->count(),
            ],
        ]);
    }

    /**
     * Attivazione dello step successivo: il flusso che passa a qualcun altro.
     */
    public function stepActivated(ReleaseStep $step): static
    {
        return $this->state(fn (array $attributes): array => [
            'release_id' => $step->release_id,
            'release_step_id' => $step->id,
            'action' => ReleaseEventAction::StepActivated,
            'payload' => [
                'position' => $step->position,
                'step' => $step->name,
                'responsible' => $step->assignedUser->name,
            ],
        ]);
    }

    /**
     * Conclusione della release, avvenuta chiudendo l'ultimo step della catena.
     */
    public function releaseCompleted(ReleaseStep $lastStep): static
    {
        return $this->state(fn (array $attributes): array => [
            'release_id' => $lastStep->release_id,
            'release_step_id' => $lastStep->id,
            'action' => ReleaseEventAction::ReleaseCompleted,
            'payload' => [
                'label' => $lastStep->release->label,
                'step' => $lastStep->name,
                'position' => $lastStep->position,
            ],
        ]);
    }

    /**
     * Tentativo non autorizzato su uno step: la voce riservata agli amministratori.
     */
    public function unauthorizedAttempt(ReleaseStep $step, string $ability = 'close'): static
    {
        return $this->state(fn (array $attributes): array => [
            'release_id' => $step->release_id,
            'release_step_id' => $step->id,
            'action' => ReleaseEventAction::UnauthorizedAttempt,
            'payload' => [
                'ability' => $ability,
                'position' => $step->position,
                'step' => $step->name,
            ],
        ]);
    }
}
