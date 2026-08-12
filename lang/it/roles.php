<?php

return [
    'heading' => 'Ruoli funzionali',
    'description' => 'Le responsabilita del processo di rilascio, indipendenti dalle persone e dai progetti.',

    'create_action' => 'Aggiungi un ruolo',
    'create_heading' => 'Nuovo ruolo',
    'edit_heading' => 'Modifica ruolo',

    'name' => 'Nome',
    'name_help' => 'Come lo chiama il team: Dev Lead, QA, DevOps, ...',
    'role_description' => 'Descrizione',
    'description_help' => 'A cosa risponde chi ricopre questo ruolo. Facoltativa.',
    'status' => 'Stato',
    'usage' => 'Utilizzo',
    'actions' => 'Azioni',

    'save' => 'Salva',
    'cancel' => 'Annulla',
    'edit' => 'Modifica',
    'delete' => 'Elimina',
    'activate' => 'Riattiva',
    'deactivate' => 'Disattiva',

    'active' => 'Attivo',
    'inactive' => 'Disattivato',

    'unused' => 'Non ancora usato',
    'used_projects' => ':count progetto|:count progetti',
    'used_templates' => ':count step di template|:count step di template',
    'used_default' => 'mappatura predefinita',

    'confirm_deactivate' => 'Disattivare :name? Non sara piu proponibile nelle nuove assegnazioni, ma resta leggibile dove e gia stato usato.',
    'confirm_activate' => 'Riattivare :name?',
    'confirm_delete' => 'Eliminare definitivamente :name? L\'operazione e possibile solo perche il ruolo non e usato da nessuna parte.',

    'deleted' => 'Ruolo eliminato.',
    'delete_refused' => 'Non puoi eliminare :name perche e gia usato: :usage. Puoi disattivarlo, cosi non sara piu proponibile ma resta leggibile dove e stato usato.',

    'empty' => 'Nessun ruolo definito. I template di workflow assegnano gli step ai ruoli, quindi il catalogo va popolato prima di configurare un processo.',
];
