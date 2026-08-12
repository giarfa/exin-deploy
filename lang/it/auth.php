<?php

return [
    /*
     * Messaggi standard di autenticazione Laravel.
     */
    'failed' => 'Queste credenziali non risultano corrette.',
    'password' => 'La password inserita non e corretta.',
    'throttle' => 'Troppi tentativi di accesso. Riprova fra :seconds secondi.',

    /*
     * Un utente disattivato non e un tentativo di intrusione: e un caso legittimo,
     * quindi merita un messaggio distinto da quello delle credenziali errate.
     */
    'inactive' => 'Questo account e disattivato. Rivolgiti a un amministratore per riattivarlo.',

    /*
     * Prodotto e testi comuni.
     */
    'brand' => 'Exin Deploy',
    'tagline' => 'Orchestrazione dei rilasci in produzione',
    'skip_to_content' => 'Salta al contenuto',

    /*
     * Accesso.
     */
    'login_heading' => 'Accedi',
    'login_description' => 'Inserisci le credenziali del tuo account aziendale.',
    'email' => 'Email',
    'password_label' => 'Password',
    'remember_me' => 'Ricordami su questo dispositivo',
    'forgot_password' => 'Password dimenticata?',
    'login_action' => 'Accedi',
    'no_public_registration' => 'Gli account sono creati da un amministratore: non e prevista la registrazione autonoma.',

    /*
     * Recupero password.
     */
    'forgot_heading' => 'Recupera la password',
    'forgot_description' => 'Ti inviamo un link per impostare una nuova password.',
    'forgot_action' => 'Invia il link di recupero',
    'back_to_login' => 'Torna all\'accesso',
    'reset_heading' => 'Nuova password',
    'reset_description' => 'Scegli una password che non hai mai usato altrove.',
    'password_confirmation' => 'Conferma la password',
    'reset_action' => 'Imposta la nuova password',
    'password_requirements' => 'Almeno 8 caratteri, con maiuscole, minuscole, numeri e simboli.',

    /*
     * Conferma password (richiesta prima delle operazioni sensibili).
     */
    'confirm_heading' => 'Conferma la password',
    'confirm_description' => 'Per proseguire con questa operazione conferma la tua password.',
    'confirm_action' => 'Conferma',

    /*
     * Verifica in due passaggi.
     */
    'two_factor_heading' => 'Verifica in due passaggi',
    'two_factor_description' => 'Inserisci il codice generato dalla tua app di autenticazione.',
    'two_factor_code' => 'Codice di verifica',
    'two_factor_recovery_code' => 'Codice di recupero',
    'two_factor_use_recovery' => 'Usa un codice di recupero',
    'two_factor_use_code' => 'Usa il codice dell\'app di autenticazione',
    'two_factor_action' => 'Verifica',
];
