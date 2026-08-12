<?php

return [
    'heading' => 'Template di workflow',
    'description' => 'La forma riutilizzabile del processo di rilascio: una sequenza di step, ciascuno con un ruolo responsabile e le informazioni da fornire.',

    'create_action' => 'Aggiungi un template',
    'create_heading' => 'Nuovo template',
    'edit_heading' => 'Modifica template',

    'name' => 'Nome',
    'name_help' => 'Come il team chiama questo processo: Rilascio standard, Rilascio urgente, ...',
    'template_description' => 'Descrizione',
    'description_help' => 'Quando si usa questo processo invece di un altro. Facoltativa.',
    'status' => 'Stato',
    'steps' => 'Step',
    'projects' => 'Progetti',
    'actions' => 'Azioni',

    'save' => 'Salva',
    'cancel' => 'Annulla',
    'edit' => 'Modifica',
    'activate' => 'Riattiva',
    'deactivate' => 'Disattiva',

    'active' => 'Attivo',
    'inactive' => 'Disattivato',

    'default' => 'Predefinito',
    'set_default' => 'Rendi predefinito',
    'default_explained' => 'Il template predefinito e proposto alla creazione di un nuovo progetto, e resta sostituibile.',
    'default_requires_active' => 'Un template disattivato non puo diventare predefinito: riattivalo prima.',

    'steps_count' => ':count step|:count step',
    'projects_count' => 'nessun progetto|:count progetto|:count progetti',
    'manage_steps' => 'Step',

    'unusable_inactive' => 'Template disattivato: non e utilizzabile per avviare una release.',
    'unusable_without_steps' => 'Template senza step: non e utilizzabile per avviare una release. Aggiungi almeno uno step.',

    'confirm_deactivate' => 'Disattivare :name? Non sara piu proponibile sui progetti, ma resta leggibile dove e gia associato.',
    'confirm_deactivate_default' => 'Disattivare :name? E il template predefinito: perdera anche questo ruolo, e i nuovi progetti nasceranno senza template finche non ne indichi un altro.',
    'confirm_activate' => 'Riattivare :name?',

    'no_deletion_note' => 'I template non si cancellano: si disattivano, perche progetti e release vi si appoggiano.',
    'empty' => 'Nessun template di workflow. Creane uno per descrivere il processo di rilascio del team.',
];
