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
