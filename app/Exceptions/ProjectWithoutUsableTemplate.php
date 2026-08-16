<?php

namespace App\Exceptions;

use App\Models\Project;
use RuntimeException;

/**
 * Non si avvia una release su un progetto il cui processo non e utilizzabile.
 *
 * Il motivo e distinto e non generico — nessun template associato, template
 * disattivato, template senza step — perche i tre casi si risolvono in tre modi
 * diversi, e un messaggio unico costringerebbe chi avvia a indovinare quale dei
 * tre sta bloccando.
 *
 * La chiave di traduzione del motivo arriva da `WorkflowTemplate::unusableReason()`:
 * la regola e definita una volta sola, dove vive.
 */
class ProjectWithoutUsableTemplate extends RuntimeException
{
    private function __construct(string $message, public readonly string $reasonKey)
    {
        parent::__construct($message);
    }

    public static function missing(Project $project): self
    {
        return new self(
            "Il progetto [{$project->name}] non ha un processo di rilascio associato.",
            'releases.blocked_without_template'
        );
    }

    public static function unusable(Project $project, string $reasonKey): self
    {
        return new self(
            "Il processo di rilascio del progetto [{$project->name}] non e utilizzabile.",
            $reasonKey
        );
    }
}
