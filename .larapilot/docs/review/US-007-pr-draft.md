# US-007: Vista operativa: i miei step

**Verso:** develop
**Spec:** .larapilot/specs/US-007.yaml — **Piano:** .larapilot/plans/US-007-plan.yaml
**Requisiti:** FR-013 — MoSCoW: Must

## Contenuto
- TASK-00 Integrazione di US-006 in develop e branch di lavoro
- TASK-01 Seam di lettura sui modelli: step attivo della release e istante di attivazione
- TASK-02 Pagina «I miei step» come schermata di ingresso
- TASK-03 Test: cosa compare, cosa no, e cosa viene annunciato
- TASK-04 Test: budget di query su step in attesa e release coinvolte
- TASK-05 CHANGELOG e documentazione della schermata di ingresso

## Note
- `git_mode: GITFLOW`: nessun push automatico, nessun remote configurato
- `develop` includeva solo fino a US-005: il merge di US-006 (approvata) e parte di TASK-00
- Spec di **sola lettura**: nessuna migrazione, nessuna colonna, nessuna Action nuova
- L'istante di attivazione di uno step e **derivato**, non memorizzato: `completed_at`
  dello step precedente, con ripiego su `release.started_at` sul primo della catena.
  Alternativa scartata (colonna `activated_at`) motivata nel corpo del piano
- Il filtro della schermata e **sull'assegnazione**, non sulla Policy: un amministratore
  non vede qui gli step altrui
- `Release::activeStep()` e `ReleaseStep::withActivationInstant()` nascono qui ma sono
  seam condivisi: US-008 (dettaglio) e US-009 (elenco) li riusano
- Fino a US-011 il seeder non produce release: dopo `migrate:fresh --seed` la schermata
  mostra lo stato vuoto, ed e corretto
- Solo componenti Flux free
