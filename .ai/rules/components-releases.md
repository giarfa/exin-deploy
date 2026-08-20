---
paths:
  - 'resources/views/components/releases/**'
---

# Components Releases

## Il dettaglio della release e l'unico percorso che legge workflow_templates
`releases/⚡show.blade.php` legge `release->workflowTemplate->name` per mostrare il **template di origine**: e l'unica deroga alla regola "l'esecuzione legge solo lo snapshot" (`.ai/rules/app.md`), ed e voluta — il criterio di accettazione di US-008 chiede da dove la release e nata. Il nome mostrato e quello **attuale**, e la nota accanto lo dichiara: catena, ordine, campi e ruoli arrivano tutti da `release_steps` / `release_step_fields`. Il template non e cancellabile finche una release lo referenzia (`restrictOnDelete`), quindi la lettura non puo rompersi.

Le altre tre tabelle (`step_definitions`, `field_definitions`, `project_role_assignments`) restano vietate, e `SnapshotIsolationTest::test_the_release_detail_reads_the_template_only_for_its_name` lo verifica sulla pagina resa, non su una query scritta nel test.

Alternativa scartata: congelare `template_name` su `releases` — una colonna in piu per un dato che nessun percorso di esecuzione interroga.

Nel componente, due vincoli di lettura non ovvi: `withActivationInstant()` **ridefinisce la select**, quindi va applicato prima di ogni altra aggiunta; e la relazione inversa verso la release va popolata a mano sugli step caricati (`setRelation`), altrimenti il primo della catena — che ripiega su `release.started_at` — la ricarica con una query propria.

## L'elenco delle release: una sola tabella responsive, due ordinamenti, nessuna paginazione
Le due sezioni (in corso / concluse) hanno colonne **e ordinamenti** diversi di proposito: le aperte per `started_at desc`, lo storico per `completed_at desc`. Uniformarli sembra una pulizia e invece inverte lo storico quando due release si incrociano (avviata prima, consegnata dopo) — caso coperto da `ReleaseIndexScreenTest::test_the_history_is_ordered_by_delivery_and_not_by_start`.

Nessuna paginazione e nessun limite di data: lo storico e consultabile a tempo indeterminato per criterio di accettazione (FR-015). Un `where` sull'ultimo anno messo "per velocita" fa sparire i rilasci vecchi senza che nulla lo dichiari. La soglia per introdurre la paginazione e l'ordine delle centinaia di righe.

Una sola tabella semantica per sezione, impilata in card sotto 1024 px con utility `max-lg:` e l'etichetta di colonna resa da `content-[attr(data-label)]` sulla cella (`x-releases.list-cell`). Non duplicare il contenuto in due alberi DOM (screen reader lo leggerebbe due volte) e non aggiungere `overflow-x-auto`: lo scorrimento orizzontale e escluso a ogni larghezza.

I filtri stanno in `#[Url]` e arrivano quindi da input non fidato: `?stato=` fuori vocabolario deve mostrare tutto (`ReleaseStatus::tryFrom`), non far fallire il cast dentro la query.

## Il modulo di avvio ricalcola le precondizioni su un clone, non su una copia della regola
`releases/⚡start.blade.php` deve mostrare il riepilogo delle precondizioni sulla mappatura **effettiva** — quella di progetto con gli override della release sovrapposti. La regola non viene riscritta nel componente: si costruisce la collezione delle assegnazioni effettive (riga reale di progetto, oppure istanza `ProjectRoleAssignment` **non persistita** con `role_id`, `user_id` e relazione `user` popolata via `setRelation`) e la si sovrappone con `setRelation('assignments', …)` a un **clone** del progetto. Su quel clone si chiamano gli stessi `Project::uncoveredRoles()`, `::inactiveResponsibles()` e `::startBlocker()`, che restano invariati.

Due trappole, entrambe volute: le istanze non vanno **mai** salvate — l'override e un effetto one-shot sulla singola release, e `StartReleaseOverrideTest::test_an_override_writes_nothing_on_the_two_mapping_tables` lo verifica riga per riga sulle due tabelle di mappatura — e il clone serve perche `$this->project` deve continuare a descrivere lo stato **persistito**, che e quello che la pagina dei responsabili mostrera dopo l'avvio. Sovrapporre la mappatura effettiva direttamente su `$this->project` sembra piu semplice e fa mentire ogni altra lettura della stessa pagina.

Terza trappola, meno visibile: `$primedDefaults` registra il valore da cui **ogni select e partito**, e il carico inviato alla Action tiene le sole scelte diverse da quello. Confrontare invece con la mappatura di **adesso** trasforma un preselezionato mai toccato in un override, appena qualcun altro rimuove quell'assegnazione fra apertura del modulo e invio: la release partirebbe congelando una persona che nessuno ha piu indicato, invece di essere rifiutata per ruolo scoperto. Il caso e fissato da `StartReleaseScreenTest::test_a_refusal_arriving_after_the_summary_names_the_role_that_blocked_it`.

Conseguenza in `projects/⚡index.blade.php`: il comando di avvio e disabilitato solo per i blocchi che nessuna scelta di persona risolve — progetto disattivato, processo assente o inutilizzabile — e la condizione e espressa **in positivo** su quelle due. Riclassificare la stringa restituita da `startBlocker()` legherebbe la vista a un testo traducibile.
