---
paths:
  - app/Policies/ReleasePolicy.php
  - app/Policies/ReleaseEventPolicy.php
---

# Policies

## Lettura aperta a ogni membro (view e viewAny), scrittura no
`ReleasePolicy::view()` e `viewAny()` concedono entrambe a **ogni utente autenticato**, anche a chi e estraneo alla catena: sapere quali rilasci sono aperti e dove sono fermi non e un privilegio ma la funzione stessa dello strumento, che non invia notifiche (rischio accettato n.1 del PRD).

Le due non si sono allineate per uniformita. `viewAny` e stata tenuta negata da US-008 e aperta da US-009, quando la schermata che la applica e arrivata: aprirla prima avrebbe dato un'autorizzazione senza una pagina su cui valutarla. Chi le trova uguali oggi non deve dedurne che una Policy vada allineata all'altra per simmetria.

Cosa resta chiuso: `create` (avviare una release) ai soli amministratori tramite il filtro `before()`; `delete` a **chiunque**, amministratori inclusi — una release **e** lo storico di un rilascio, e il registro delle transizioni non servirebbe a nulla se la riga a cui si riferisce potesse sparire.

Elenco e dettaglio restano comunque in sola lettura: compilare e chiudere sono decise da `ReleaseStepPolicy` (responsabile dello step attivo o amministratore), piu restrittiva di proposito.

## Policy e scope del registro decidono la stessa cosa: vanno cambiati insieme
La visibilita dei tentativi non autorizzati e scritta **due volte**: `ReleaseEventPolicy::view()` decide sul singolo evento, `ReleaseEvent::visibleTo()` la esprime in query. Non e una duplicazione da eliminare — filtrare a valle significherebbe leggere righe che chi guarda non puo vedere, e il loro numero e a sua volta informazione di sicurezza — ma le due devono restare allineate. `ReleaseEventPolicyTest::test_the_policy_and_the_query_scope_agree_row_by_row` le confronta riga per riga per entrambi i livelli: toccarne una sola fa fallire la suite invece di aprire una fuga.

`update` e `delete` stanno fra le `NOT_FILTERED`: senza, il filtro `before()` le concederebbe a un amministratore proprio dove il vincolo vale anche per lui. Non sono ridondanti rispetto a `ReleaseEventIsAppendOnly` — il modello rifiuta le scritture che passano da lui, la Policy nega l'intenzione prima che una schermata possa offrirla.

Alla schermata del registro non si aggiungono metodi pubblici senza aggiornare l'elenco atteso in `ReleaseEventAppendOnlyTest`: in Livewire ogni metodo pubblico e un'azione invocabile dal browser, quindi l'elenco e la superficie di scrittura della pagina.
