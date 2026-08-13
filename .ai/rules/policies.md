---
paths:
  - app/Policies/ReleasePolicy.php
---

# Policies

## Lettura aperta a ogni membro (view e viewAny), scrittura no
`ReleasePolicy::view()` e `viewAny()` concedono entrambe a **ogni utente autenticato**, anche a chi e estraneo alla catena: sapere quali rilasci sono aperti e dove sono fermi non e un privilegio ma la funzione stessa dello strumento, che non invia notifiche (rischio accettato n.1 del PRD).

Le due non si sono allineate per uniformita. `viewAny` e stata tenuta negata da US-008 e aperta da US-009, quando la schermata che la applica e arrivata: aprirla prima avrebbe dato un'autorizzazione senza una pagina su cui valutarla. Chi le trova uguali oggi non deve dedurne che una Policy vada allineata all'altra per simmetria.

Cosa resta chiuso: `create` (avviare una release) ai soli amministratori tramite il filtro `before()`; `delete` a **chiunque**, amministratori inclusi — una release **e** lo storico di un rilascio, e il registro delle transizioni non servirebbe a nulla se la riga a cui si riferisce potesse sparire.

Elenco e dettaglio restano comunque in sola lettura: compilare e chiudere sono decise da `ReleaseStepPolicy` (responsabile dello step attivo o amministratore), piu restrittiva di proposito.
