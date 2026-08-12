---
paths:
  - composer.json
---

# General

## Starter Kit Livewire installato a mano; SQLite in WAL e migrazioni portabili
Il PRD indica `Admin panel: Starter Kit (livewire)`, ma gli Starter Kit ufficiali si applicano solo a `laravel new`: questo repo esiste già. La variante si ottiene con `livewire/livewire ^4.4` + `livewire/flux ^2.16` + `laravel/fortify ^1.38` (compatibilità con Laravel 13.24 / PHP 8.4 verificata via dry-run). Usare solo i componenti Flux FREE: Flux Pro richiede licenza a pagamento non acquistata.

Database SQLite per scelta esplicita, con rischio di concorrenza in scrittura accettato: abilitare WAL e `busy_timeout`, tenere le transazioni brevi. Usare solo Eloquent e migrazioni portabili — nessuna funzione SQLite-specifica — perché la migrazione a PostgreSQL/MySQL deve restare un cambio di configurazione.

`larastan/larastan` è in require-dev ma non installato in vendor/: serve `composer install` prima che `php artisan larapilot:quality` sia eseguibile.
