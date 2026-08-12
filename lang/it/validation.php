<?php

/*
 * Traduzioni italiane dei soli messaggi di validazione effettivamente usati dai
 * form dell'applicazione. Il fallback e `en`: una regola non tradotta qui rende
 * il messaggio inglese di serie, mai la chiave grezza.
 *
 * Quando una spec introduce nuove regole, aggiungere qui il messaggio
 * corrispondente nella stessa spec.
 */

return [
    'accepted' => 'Il campo :attribute deve essere accettato.',
    'boolean' => 'Il campo :attribute deve essere vero o falso.',
    'confirmed' => 'La conferma del campo :attribute non corrisponde.',
    'current_password' => 'La password inserita non e corretta.',
    'email' => 'Il campo :attribute deve essere un indirizzo email valido.',
    'in' => 'Il valore selezionato per :attribute non e valido.',
    'max' => [
        'array' => 'Il campo :attribute non puo contenere piu di :max elementi.',
        'file' => 'Il campo :attribute non puo superare :max kilobyte.',
        'numeric' => 'Il campo :attribute non puo essere superiore a :max.',
        'string' => 'Il campo :attribute non puo superare :max caratteri.',
    ],
    'min' => [
        'array' => 'Il campo :attribute deve contenere almeno :min elementi.',
        'file' => 'Il campo :attribute deve essere almeno :min kilobyte.',
        'numeric' => 'Il campo :attribute deve essere almeno :min.',
        'string' => 'Il campo :attribute deve contenere almeno :min caratteri.',
    ],
    'required' => 'Il campo :attribute e obbligatorio.',
    'string' => 'Il campo :attribute deve essere una stringa.',
    'unique' => 'Il valore del campo :attribute e gia in uso.',
    'url' => 'Il campo :attribute deve essere un indirizzo valido.',
    'uuid' => 'Il campo :attribute deve essere un UUID valido.',

    /*
     * Regola App\Rules\AssignableUser: chi ricopre un ruolo deve poter accedere.
     */
    'assignable_user' => [
        'missing' => 'La persona selezionata non esiste piu.',
        'inactive' => ':name e disattivato e non puo ricoprire un ruolo: riattivalo dalla gestione dei membri oppure scegli un\'altra persona.',
    ],

    /*
     * Requisiti della regola Password::defaults() registrata in AppServiceProvider.
     */
    'password' => [
        'letters' => 'Il campo :attribute deve contenere almeno una lettera.',
        'mixed' => 'Il campo :attribute deve contenere almeno una lettera maiuscola e una minuscola.',
        'numbers' => 'Il campo :attribute deve contenere almeno un numero.',
        'symbols' => 'Il campo :attribute deve contenere almeno un simbolo.',
        'uncompromised' => 'Il campo :attribute risulta in una violazione di dati nota. Scegline un altro.',
    ],

    /*
     * Nomi leggibili dei campi, usati dentro i messaggi al posto di ":attribute".
     */
    'attributes' => [
        'code' => 'codice di verifica',
        'email' => 'email',
        'is_active' => 'stato attivo',
        'level' => 'livello',
        'name' => 'nome',
        'password' => 'password',
        'password_confirmation' => 'conferma della password',
        'recovery_code' => 'codice di recupero',
    ],
];
