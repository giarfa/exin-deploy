<?php

return [
    'heading' => 'Progetti',
    'description' => 'I progetti su cui il team rilascia. Ognuno contiene le proprie release e il proprio storico.',

    'create_action' => 'Aggiungi un progetto',
    'create_heading' => 'Nuovo progetto',
    'edit_heading' => 'Modifica progetto',

    'name' => 'Nome',
    'slug' => 'Identificativo',
    'slug_help' => 'Solo minuscole, numeri e trattini. Proposto dal nome, modificabile.',
    'project_description' => 'Descrizione',
    'status' => 'Stato',
    'assignments' => 'Responsabili',
    'actions' => 'Azioni',

    'save' => 'Salva',
    'cancel' => 'Annulla',
    'edit' => 'Modifica',
    'activate' => 'Riattiva',
    'deactivate' => 'Disattiva',

    'active' => 'Attivo',
    'inactive' => 'Disattivato',

    'assignments_count' => ':count ruolo assegnato|:count ruoli assegnati',
    'manage_assignments' => 'Responsabili',

    'confirm_deactivate' => 'Disattivare :name? Non si potranno avviare nuove release, ma lo storico resta consultabile.',
    'confirm_activate' => 'Riattivare :name?',

    'created_with_defaults' => 'Progetto creato. I responsabili sono stati precompilati dalla mappatura predefinita del team.',
    'created_with_gaps' => 'Progetto creato, ma :count ruolo non e stato precompilato perche il ruolo o la persona predefinita risultano disattivati.|Progetto creato, ma :count ruoli non sono stati precompilati perche il ruolo o la persona predefinita risultano disattivati.',

    'no_deletion_note' => 'I progetti non si cancellano: si disattivano, perche contengono lo storico dei rilasci.',
    'empty' => 'Nessun progetto. Creane uno per iniziare a tracciare i rilasci.',

    /*
     * Pagina dei responsabili di un progetto.
     */
    'assignments_heading' => 'Responsabili di :project',
    'assignments_description' => 'Chi ricopre ciascun ruolo su questo progetto. E questa mappatura che, all\'avvio di una release, trasforma il ruolo di ogni step in una persona.',
    'back_to_projects' => 'Torna ai progetti',

    'role' => 'Ruolo',
    'person' => 'Persona',
    'unassigned' => 'Non assegnato',
    'unassigned_option' => '— Nessuno —',
    'inactive_role_note' => 'Ruolo disattivato: resta elencato perche ha gia un responsabile su questo progetto.',
    'inactive_person_note' => 'Disattivato: non puo piu accedere. Assegna un\'altra persona.',
    'assignment_saved' => 'Responsabile aggiornato.',
    'assignment_removed' => 'Responsabile rimosso.',
    'no_roles' => 'Nessun ruolo funzionale attivo: crea prima il catalogo dei ruoli.',
    'defaults_note' => 'La mappatura predefinita del team vale solo alla creazione di un progetto: modificarla qui non la cambia, e cambiarla li non tocca questo progetto.',
];
