# US-001: Accesso allo strumento e gestione dei membri del team

**Verso:** develop
**Spec:** .larapilot/specs/US-001.yaml — **Piano:** .larapilot/plans/US-001-plan.yaml
**Requisiti:** FR-001 (MoSCoW: Must)

## Contenuto
- TASK-00 Inizializzazione repository, branch main/develop e branch di lavoro
- TASK-01 Installazione Livewire 4, Flux, Fortify + composer install (Larastan)
- TASK-02 Baseline sicurezza: Argon2id, Password::defaults(), SQLite in WAL
- TASK-03 User con UUID, enum UserLevel, is_active + factory e seeder
- TASK-04 Test: modello, enum, hashing, regole password
- TASK-05 Fortify: login, reset password, 2FA TOTP, blocco utenti disattivati
- TASK-06 Test: flussi di autenticazione
- TASK-07 Shell applicativa Starter Kit + robots.txt e noindex
- TASK-08 Gestione membri con UserPolicy
- TASK-09 Test: autorizzazione a livello di rotta
- TASK-10 README, nota architetturale, CHANGELOG

## Note
- git_mode GITFLOW: nessun push automatico, nessun remote configurato
- Passkey Fortify disattivate: fuori perimetro PRD
- Solo componenti Flux free
