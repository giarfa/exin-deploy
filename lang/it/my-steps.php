<?php

/*
 * Vista operativa "i miei step": la schermata di ingresso.
 *
 * Tono diretto e operativo, seconda persona singolare, come da sezione Copy del
 * README del mockup: il turno si dichiara come fatto ("Tocca a te"), le azioni
 * descrivono la conseguenza ("Apri e compila"), gli stati vuoti spiegano cosa
 * accadra e non solo cosa manca.
 */
return [
    'heading' => 'I miei step',

    /*
     * Contatore in `aria-live="polite"`: cambia dopo un aggiornamento Livewire
     * senza ricaricare la pagina, e chi usa uno screen reader deve sentirlo.
     * Condizioni esplicite anche su `{0}`: senza, la prima forma renderebbe pure
     * per zero, cioe "1 step" su una schermata vuota.
     */
    'counter' => '{0} Nessuno step attende te.|{1} Uno step attende te, su :projects.|[2,*] :count step attendono te, su :projects.',
    'counter_projects' => '{1} un progetto|[2,*] :count progetti',

    'steps_section' => 'Step che attendono te',

    'status_your_turn' => 'Tocca a te',

    /*
     * Riga di contesto della card. "come :role" legge il ruolo **congelato**
     * all'avvio della release: rinominare il ruolo non riscrive cio che la
     * schermata mostra di un rilascio gia in corso.
     */
    'step_position' => 'Step :position di :total',
    'step_as_role' => 'come :role',
    'step_open_since' => 'aperto da :duration',
    'step_open_action' => 'Apri e compila',

    'empty_heading' => 'Nessuno step ti sta aspettando',
    'empty_explained' => 'Quando un collega chiude il proprio step e tocca a te, lo trovi qui.',

    /*
     * Blocco delle release in attesa: e la mitigazione del rischio accettato n.1
     * del PRD (assenza di notifiche, FR-025 fuori perimetro). Dice **chi**
     * trattiene il flusso, **su quale step** e **da quanto**, cosi che chiunque
     * possa sollecitare invece di scoprire il blocco a valle.
     */
    'waiting_section' => 'Release in corso su cui sei coinvolto',
    'waiting_row' => 'in attesa di :name su «:step» da :duration',
    'waiting_no_notifications' => 'Lo strumento non invia notifiche: se una di queste e ferma da troppo, sollecita a voce.',
];
