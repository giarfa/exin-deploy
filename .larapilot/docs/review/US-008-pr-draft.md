# US-008: Dettaglio della release con catena e informazioni fornite

**Verso:** develop
**Spec:** .larapilot/specs/US-008.yaml — **Piano:** .larapilot/plans/US-008-plan.yaml
**Requisiti:** FR-014 — MoSCoW: Must

## Contenuto
- TASK-00 Ramo di lavoro e integrazione di US-007 in develop
- TASK-01 Apertura della lettura della release a ogni membro e rotta di dettaglio
- TASK-02 Estrarre i valori forniti in un componente condiviso
- TASK-03 Schermata di dettaglio della release con catena e dati del rilascio
- TASK-04 Vie di accesso al dettaglio dalle schermate esistenti
- TASK-05 Copertura della schermata di dettaglio
- TASK-06 Budget di query del dettaglio della release
- TASK-07 Changelog e nota sulla lettura aperta della release

## Note
- `git_mode: GITFLOW`: nessun push automatico, nessun remote configurato
- `develop` includeva solo fino a US-006: il merge di US-007 (approvata) e parte di TASK-00,
  perche questa spec modifica `my-steps/⚡index.blade.php`, che viveva solo su quel ramo
- Spec di **sola lettura**: nessuna migrazione, nessuna colonna, nessun modello nuovo
- `ReleasePolicy::view()` passa a concessa per **ogni membro autenticato**; `viewAny()` resta
  negata — l'elenco e la sua Policy sono US-009
- Il blocco dei valori forniti viene **estratto** da `⚡step.blade.php` in
  `x-releases.step-values`: la verifica dello schema `http(s)` prima di rendere un valore
  come collegamento deve vivere in una copia sola
- La voce di navigazione "Release" resta marcata in arrivo: promette l'elenco (US-009), non
  il dettaglio. Le vie di accesso sono tre, tutte su schermate esistenti
- Briciole di navigazione in deroga al mockup: "I miei step" invece di "Release", perche
  `releases.index` non esiste e `projects.index` e riservata agli amministratori
- Fino a US-011 il seeder non produce release: dopo `migrate:fresh --seed` il dettaglio non
  ha nulla da mostrare, ed e corretto
- Solo componenti Flux free; soglia responsive unica a 1024 px
