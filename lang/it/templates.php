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
    // Condizioni esplicite: il selettore italiano usa due forme, e senza `{0}`
    // la prima renderebbe per `n == 1` — cioe "nessun progetto" su un template
    // usato da un progetto, che dice il contrario del vero.
    'projects_count' => '{0} nessun progetto|{1} :count progetto|[2,*] :count progetti',
    'manage_steps' => 'Step',

    'unusable_inactive' => 'Template disattivato: non e utilizzabile per avviare una release.',
    'unusable_without_steps' => 'Template senza step: non e utilizzabile per avviare una release. Aggiungi almeno uno step.',

    'confirm_deactivate' => 'Disattivare :name? Non sara piu proponibile sui progetti, ma resta leggibile dove e gia associato.',
    'confirm_deactivate_default' => 'Disattivare :name? E il template predefinito: perdera anche questo ruolo, e i nuovi progetti nasceranno senza template finche non ne indichi un altro.',
    'confirm_activate' => 'Riattivare :name?',

    'no_deletion_note' => 'I template non si cancellano: si disattivano, perche progetti e release vi si appoggiano.',
    'empty' => 'Nessun template di workflow. Creane uno per descrivere il processo di rilascio del team.',

    /*
     * Step di un template.
     */
    'steps_heading' => 'Step di :template',
    'steps_description' => 'La sequenza di passaggi del rilascio. Ogni step ha un ruolo responsabile e le informazioni che chi lo esegue deve fornire per chiuderlo.',
    'back_to_templates' => 'Torna ai template',

    'step_create_action' => 'Aggiungi uno step',
    'step_create_heading' => 'Nuovo step',
    'step_edit_heading' => 'Modifica step',

    'step_name' => 'Nome dello step',
    'step_name_help' => 'Cosa succede in questo passaggio: Verifica funzionale, Consegna in produzione, ...',
    'step_instructions' => 'Istruzioni per chi lo esegue',
    'step_instructions_help' => 'Cosa deve fare concretamente il responsabile. E qui che il processo diventa comprensibile a chi lo eredita.',
    'step_role' => 'Ruolo responsabile',
    'step_role_help' => 'Chi ne risponde viene deciso progetto per progetto, risolvendo il ruolo sulla mappatura dei responsabili.',

    'step_position' => 'Posizione :position',
    'move_up' => 'Sposta :name piu in alto',
    'move_down' => 'Sposta :name piu in basso',
    'moved' => ':name e ora alla posizione :position.',

    'fields_count' => '{0} nessun campo richiesto|{1} :count campo richiesto|[2,*] :count campi richiesti',
    'manage_fields' => 'Campi richiesti',

    'confirm_delete_step' => 'Eliminare lo step :name? Anche i campi richiesti che vi hai definito andranno persi. Le release gia avviate non cambiano: leggono la propria copia.',
    'delete_step' => 'Elimina',

    'steps_empty' => 'Nessuno step: un template senza step non e utilizzabile per avviare una release. Aggiungine almeno uno.',
    'no_roles' => 'Nessun ruolo funzionale attivo: crea prima il catalogo dei ruoli, perche ogni step deve nominare un responsabile.',
    'inactive_role_note' => 'Ruolo disattivato: resta elencato perche uno step di questo template lo usa gia.',

    /*
     * Campi richiesti di uno step.
     */
    'fields_heading' => 'Campi richiesti di :step',
    'fields_description' => 'Le informazioni che il responsabile deve fornire per chiudere lo step.',

    'field_create_action' => 'Aggiungi un campo',
    'field_create_heading' => 'Nuovo campo',
    'field_edit_heading' => 'Modifica campo',

    'field_label' => 'Etichetta',
    'field_label_help' => 'Come viene chiesta l\'informazione: Versione rilasciata, Link alla pipeline, ...',
    'field_type' => 'Tipo',
    'field_type_help' => 'La forma della risposta attesa.',
    'field_required' => 'Obbligatorio',
    'field_required_help' => 'Un campo obbligatorio non compilato impedira di chiudere lo step. Uno facoltativo no.',
    'field_help_text' => 'Testo di aiuto',
    'field_help_text_help' => 'Una riga che spiega al responsabile cosa scrivere. Facoltativa.',

    'required' => 'Obbligatorio',
    'optional' => 'Facoltativo',

    'confirm_delete_field' => 'Eliminare il campo :label?',
    'delete_field' => 'Elimina',

    'fields_empty' => 'Nessun campo: il responsabile potra chiudere lo step senza fornire informazioni. E una configurazione lecita, ma vale la pena che sia una scelta.',
];
