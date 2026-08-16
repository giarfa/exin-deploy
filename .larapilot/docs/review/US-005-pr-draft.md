# US-005: Chiusura di uno step con validazione e avanzamento sequenziale

**Verso:** develop
**Spec:** .larapilot/specs/US-005.yaml — **Piano:** .larapilot/plans/US-005-plan.yaml
**Requisiti:** FR-010, FR-011, FR-012, FR-016 (scrittura degli eventi) — MoSCoW: Must

## Contenuto
- TASK-00 Integrazione di US-004 in develop e branch di lavoro
- TASK-01 Regola di validazione `WellFormedLink` con motivo esplicito
- TASK-02 Regole di chiusura e normalizzazione del valore sullo snapshot
- TASK-03 Test: regole per tipo, link malformato e normalizzazione
- TASK-04 Eccezioni di dominio della chiusura e `ReleaseStepPolicy`
- TASK-05 Action `CloseStep`: transazione unica, compare-and-swap, avanzamento
- TASK-06 Action `SaveStepValues`: salvataggio senza chiusura
- TASK-07 Registrazione del tentativo non autorizzato (registro + log)
- TASK-08 Test: chiusura valida, rifiuti e invariante dello step attivo
- TASK-09 Test: doppio invio concorrente, transazione unica, isolamento snapshot
- TASK-10 Rotta e schermata di chiusura dello step
- TASK-11 Ponte di ingresso dalla catena della release avviata
- TASK-12 Test: autorizzazione, tracciamento del rifiuto, flusso dall'interfaccia
- TASK-13 Documentazione: diagrammi Mermaid, nota architetturale, CHANGELOG

## Note
- `git_mode: GITFLOW`: nessun push automatico, nessun remote configurato
- `develop` includeva solo fino a US-003: il merge di US-004 e parte di TASK-00
- La rotta di chiusura non ha `->can()` sul middleware, in deroga dichiarata: il
  tentativo non autorizzato deve essere registrato dal codice applicativo
- Ultimo step della catena: rifiutato con `ReleaseCompletionIsNotAvailableYet`,
  la conclusione della release e US-006 (FR-017)
- Solo componenti Flux free
