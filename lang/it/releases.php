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
    'started_open_detail' => 'Apri il dettaglio della release',

    /*
     * Gli stati della catena non hanno chiavi qui: le etichette vivono su
     * `App\Enums\ReleaseStepStatus::label()`, dove sta anche il vocabolario.
     * Duplicarle darebbe due fonti per la stessa parola, destinate a divergere.
     */

    'duplicate_label' => 'Esiste gia una release con etichetta :label su questo progetto.',

    /*
     * Chiusura di uno step (US-005). I rifiuti sono distinti perche si risolvono in
     * modi diversi: il primo non si risolve, il secondo si aspetta, il terzo si
     * legge. Le chiavi arrivano dalle eccezioni di dominio, che le portano con se.
     */
    'closing_blocked_release_completed' => 'La release e conclusa: non ci sono piu step da chiudere.',
    'closing_blocked_step_blocked' => 'Questo step non e ancora aperto: si attende la chiusura di quelli che lo precedono.',
    'closing_blocked_step_completed' => 'Questo step e gia stato chiuso: i valori forniti sono in sola lettura.',
    'closing_already_closed' => 'Lo step e stato chiuso da un altro invio: l\'avanzamento e avvenuto una sola volta, e il flusso e gia passato al responsabile successivo.',
    'closing_already_concluded' => 'La release e stata conclusa da un altro invio: la conclusione e avvenuta una sola volta, e il rilascio risulta consegnato.',

    /*
     * Schermata di chiusura. Il tono e quello del mockup: "Chiudi lo step e
     * prosegui" dice cosa accade dopo, "Salva" direbbe soltanto cosa fa il
     * pulsante.
     */
    'step_back_home' => 'Torna ai miei step',
    'step_back_release' => 'Vedi la release completa',
    'step_context' => ':project · :release · step :position di :total',
    'step_you_are_responsible' => 'sei il responsabile come :role',
    'step_responsible_is' => 'responsabile: :name, come :role',

    'step_instructions_heading' => 'Istruzioni del processo',
    /*
     * A chi passa il flusso: e la mitigazione progettuale dell'assenza di
     * notifiche (rischio accettato n.1 del PRD). Chi chiude sa chi avvisare a voce.
     */
    'step_hands_over_to' => 'Alla chiusura di questo step il flusso passa a :name — :step.',
    'step_hands_over_last' => 'Questo e l\'ultimo step della catena: alla sua chiusura la release risulta consegnata e conclusa.',

    'step_required_marker' => ' (obbligatorio)',
    'step_optional_hint' => 'Campo opzionale: la sua assenza non impedisce la chiusura.',

    'step_errors_heading' => 'Non e stato possibile chiudere lo step',
    'step_errors_intro' => '{1} Un\'informazione richiesta non e valida:|[2,*] :count informazioni richieste non sono valide:',

    'step_close_action' => 'Chiudi lo step e prosegui',
    'step_save_action' => 'Salva senza chiudere',
    'step_closing_is_final' => 'La chiusura e definitiva: riaprire uno step non e previsto in questa versione dello strumento, e ogni transizione resta nel registro.',

    'step_saved_notice' => 'Bozza salvata. Lo step resta aperto e in carico a te: puoi riprendere quando vuoi.',
    'step_closed_heading' => 'Step chiuso',
    'step_closed_handed_over' => 'Il flusso e passato a :name — :step. Avvisalo: lo strumento non invia notifiche.',

    /*
     * Conclusione della release (US-006). L'annuncio dice che il rilascio e
     * consegnato e che lo storico resta: non promette nulla su una riapertura, che
     * e FR-019 e resta fuori perimetro.
     */
    'step_release_completed_heading' => 'Rilascio consegnato',
    'step_release_completed_announced' => 'La release :release e conclusa: consegnata il :date. Non ci sono altri step da chiudere e lo storico resta consultabile.',
    'step_release_completed_notice' => 'Il rilascio e concluso: la release :release e stata consegnata il :date. L\'intero rilascio e in sola lettura.',

    'step_completed_heading' => 'Step completato',
    'step_completed_explained' => 'Chiuso da :name il :date. Le informazioni fornite sono in sola lettura.',
    'step_blocked_heading' => 'Questo step non e ancora aperto',
    'step_blocked_waiting' => 'Si attende la chiusura dello step :position — :step, in carico a :name.',
    'step_blocked_waiting_unknown' => 'Si attende la chiusura degli step che lo precedono.',

    'step_values_heading' => 'Informazioni fornite',
    'step_value_not_provided' => 'Non fornito',
    'step_value_confirmed' => 'Confermato',

    'step_open_action' => 'Apri e compila',

    /*
     * Dettaglio della release (US-008). Schermata di **sola lettura**: non chiede
     * nulla a chi la apre e non ha azioni proprie — risponde a "dove siamo e chi
     * stiamo aspettando" senza interrompere nessuno.
     *
     * Le etichette degli stati non hanno chiavi qui: vivono su
     * `App\Enums\ReleaseStepStatus::label()` e `ReleaseStatus::label()`, dove sta il
     * vocabolario. Il titolo delle informazioni fornite riusa
     * `step_values_heading`: e lo stesso blocco, reso dallo stesso componente.
     */
    /*
     * Elenco delle release (US-009). Prefisso `index_` come sulle altre schermate
     * di collezione.
     *
     * Le etichette degli stati non hanno chiavi qui: vivono su
     * `App\Enums\ReleaseStatus::label()`, dove sta il vocabolario. Duplicarle
     * darebbe due fonti per la stessa parola, destinate a divergere.
     */
    'index_heading' => 'Release',
    'index_counter' => ':in_progress, :completed.',
    // Condizioni esplicite: senza `{0}` la prima forma renderebbe anche per zero,
    // cioe "1 rilascio in corso" su un elenco vuoto.
    'index_counter_in_progress' => '{0} nessun rilascio in corso|{1} un rilascio in corso|[2,*] :count rilasci in corso',
    'index_counter_completed' => '{0} nessuno concluso|{1} uno concluso|[2,*] :count conclusi',

    'index_filter_all' => 'Tutte',
    'index_filter_project' => 'Progetto',
    'index_filter_project_all' => 'Tutti i progetti',

    'index_section_in_progress' => 'In corso',
    'index_section_completed' => 'Concluse',

    'index_caption_in_progress' => 'Release in corso, con step corrente e responsabile in attesa.',
    'index_caption_completed' => 'Release concluse, con data e autore della consegna in produzione.',

    'index_column_project' => 'Progetto',
    'index_column_label' => 'Etichetta',
    'index_column_status' => 'Stato',
    'index_column_current_step' => 'Step corrente',
    'index_column_waiting_on' => 'In attesa di',
    'index_column_started_at' => 'Avviata',
    'index_column_delivered_by' => 'Consegnata da',
    'index_column_completed_at' => 'Conclusa',
    'index_column_duration' => 'Durata',

    'index_current_step' => ':position di :total — :step',
    'index_waiting_on' => ':name, da :duration',
    // Una release in corso ha sempre uno step attivo per invariante: queste due
    // righe esistono perche una schermata che tacesse davanti a un dato incoerente
    // lascerebbe senza sapere cosa si sta guardando.
    'index_without_active_step' => 'Nessuno step attivo',
    'index_waiting_on_nobody' => 'Nessun responsabile in attesa',
    'index_delivered_by_unknown' => 'Autore non registrato',

    'index_empty_in_progress_heading' => 'Nessun rilascio in corso',
    'index_empty_in_progress_explained' => 'Compariranno qui non appena una release verra avviata su un progetto.',
    'index_empty_completed_heading' => 'Nessun rilascio concluso',
    'index_empty_completed_explained' => 'Lo storico si popola alla chiusura dell\'ultimo step di una release.',
    // Dice quale filtro produce il vuoto: senza, resta da indovinare se non ci sia
    // nulla o se sia il filtro a nasconderlo.
    'index_empty_filtered' => 'Nessun risultato per il progetto :project. Togli il filtro per vedere gli altri progetti.',

    'detail_summary_in_progress' => 'Rilascio in corso — step :position di :total, in attesa di :name.',
    // Una release in corso ha sempre uno step attivo per invariante: questa riga
    // esiste perche una pagina che tacesse davanti a un dato incoerente lascerebbe
    // senza sapere cosa sta guardando.
    'detail_summary_without_active_step' => 'Rilascio in corso — nessuno step risulta attivo.',
    'detail_summary_completed' => 'Rilascio consegnato il :date.',

    'detail_chain_heading' => 'Catena degli step',
    'detail_step_owner' => ':role — :name',
    'detail_step_closed_at' => 'Chiuso da :name il :date',
    'detail_step_active_since' => 'Attivo da :duration',
    'detail_step_required_fields' => '{0} nessuna informazione richiesta|{1} un\'informazione richiesta|[2,*] :count informazioni richieste',
    'detail_step_open_reserved' => 'Il comando e visibile solo al responsabile dello step o a un amministratore.',
    'detail_step_unlocks_after' => 'Si sblocca alla chiusura dello step :position.',
    'detail_step_unlocks_last' => 'Ultimo step: la sua chiusura conclude il rilascio.',

    'detail_meta_heading' => 'Dati della release',
    'detail_meta_project' => 'Progetto',
    'detail_meta_label' => 'Etichetta',
    'detail_meta_status' => 'Stato',
    'detail_meta_template' => 'Template di origine',
    'detail_meta_started_by' => 'Avviata da',
    'detail_meta_started_at' => 'Avviata il',
    'detail_meta_completed_at' => 'Conclusa il',
    'detail_meta_completed_steps' => 'Step completati',
    'detail_meta_completed_steps_value' => ':completed di :total',
    'detail_template_frozen' => 'Il template e congelato all\'avvio: modifiche successive a :template non alterano questa release.',
];
