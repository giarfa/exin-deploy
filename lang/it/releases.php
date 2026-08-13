<?php

return [
    'heading' => 'Avvia una release su :project',
    'description' => 'Il processo viene copiato cosi com\'e adesso: da questo momento la release ha una forma propria, e modificare il template non la cambia piu.',
    'back_to_projects' => 'Torna ai progetti',

    'label' => 'Etichetta',
    'label_help' => 'Come il team chiama questo rilascio: v2.4.0, 2026.08.1, ... Deve essere unica sul progetto.',

    'start_action' => 'Avvia la release',
    'start_from_project' => 'Avvia una release',

    /*
     * Riepilogo delle precondizioni, mostrato **prima** del tentativo: scoprire
     * che manca un responsabile dopo aver premuto il pulsante e la stessa
     * informazione data nel momento peggiore.
     */
    'preconditions_heading' => 'Prima di avviare',
    'precondition_template' => 'Processo associato: :template',
    // Condizioni esplicite: il selettore italiano usa due forme, e senza `{0}` la
    // prima renderebbe anche per `n == 0` — cioe "1 step" su un template vuoto.
    'precondition_steps' => '{0} nessuno step|{1} :count step da congelare|[2,*] :count step da congelare',
    'precondition_roles_ok' => 'Tutti i ruoli previsti hanno un responsabile',

    'blocked_heading' => 'Non puoi ancora avviare una release',
    'blocked_without_template' => 'Il progetto non ha un processo di rilascio associato. Associane uno dalla pagina dei progetti.',
    'blocked_inactive_project' => 'Il progetto e disattivato e non accoglie nuove release.',
    'blocked_uncovered_roles' => '{1} Questo ruolo non ha un responsabile sul progetto: :roles.|[2,*] Questi ruoli non hanno un responsabile sul progetto: :roles.',
    'blocked_inactive_responsibles' => '{1} Questo responsabile e disattivato: :members. Aggiorna la mappatura del progetto.|[2,*] Questi responsabili sono disattivati: :members. Aggiorna la mappatura del progetto.',
    'blocked_hint_assignments' => 'Assegna i responsabili mancanti',

    /*
     * Conferma dopo l'avvio: la prova visiva che lo snapshot esiste.
     */
    'started_heading' => 'Release :label avviata',
    'started_explained' => 'La catena qui sotto e congelata: modificare il template da adesso in poi non la cambia.',
    'chain_heading' => 'Catena congelata',
    'chain_position' => 'Step :position',
    'responsible' => 'Responsabile',
    'start_another' => 'Avvia un\'altra release',

    /*
     * Gli stati della catena non hanno chiavi qui: le etichette vivono su
     * `App\Enums\ReleaseStepStatus::label()`, dove sta anche il vocabolario.
     * Duplicarle darebbe due fonti per la stessa parola, destinate a divergere.
     */

    'duplicate_label' => 'Esiste gia una release con etichetta :label su questo progetto.',
];
