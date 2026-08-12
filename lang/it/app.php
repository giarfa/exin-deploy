<?php

return [
    'logout' => 'Esci',

    /*
     * Navigazione. Le sezioni non ancora implementate restano visibili e marcate
     * come previste: la struttura del prodotto deve essere leggibile da subito.
     */
    'nav_operational' => 'Operativo',
    'nav_configuration' => 'Configurazione',
    'nav_my_steps' => 'I miei step',
    'nav_releases' => 'Release',
    'nav_projects' => 'Progetti',
    'nav_templates' => 'Template di workflow',
    'nav_roles' => 'Ruoli',
    'nav_members' => 'Membri del team',
    'nav_planned' => 'in arrivo',

    /*
     * Tema. La preferenza e persistita da Flux in localStorage.
     */
    'theme_light' => 'Chiaro',
    'theme_dark' => 'Scuro',
    'theme_system' => 'Sistema',

    /*
     * Home autenticata: e un segnaposto. La schermata di ingresso definitiva e
     * la vista operativa "i miei step" (US-007), che sostituira questo contenuto.
     */
    'home_heading' => 'Benvenuto',
    'home_placeholder' => 'La schermata di ingresso definitiva sara "I miei step": elencherà gli step di rilascio che attendono te, su tutti i progetti.',

    /*
     * Impostazioni di sicurezza dell'account.
     */
    'security_heading' => 'Sicurezza dell\'account',
    'two_factor_heading' => 'Verifica in due passaggi',
    'two_factor_intro' => 'Aggiunge un codice temporaneo generato dal telefono al momento dell\'accesso.',
    'two_factor_disabled' => 'La verifica in due passaggi non e attiva su questo account.',
    'two_factor_enable' => 'Attiva la verifica in due passaggi',
    'two_factor_disable' => 'Disattiva la verifica in due passaggi',
    'two_factor_scan' => 'Inquadra questo codice con la tua app di autenticazione, poi inserisci il codice generato per confermare.',
    'two_factor_secret' => 'Chiave di configurazione manuale',
    'two_factor_confirm' => 'Conferma e attiva',
    'two_factor_active' => 'La verifica in due passaggi e attiva su questo account.',
    'two_factor_recovery_heading' => 'Codici di recupero',
    'two_factor_recovery_intro' => 'Conservali in un posto sicuro: permettono di accedere se perdi il telefono. Ogni codice si usa una volta sola.',
    'two_factor_recovery_regenerate' => 'Rigenera i codici di recupero',
];
