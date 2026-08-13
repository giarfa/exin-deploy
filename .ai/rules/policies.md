---
paths:
  - app/Policies/ReleasePolicy.php
---

# Policies

## view aperta a ogni membro, viewAny ancora negata: non e un disallineamento
`ReleasePolicy::view()` concede a **ogni utente autenticato**, anche a chi e estraneo alla catena: sapere dove e fermo un rilascio non e un privilegio ma la funzione stessa dello strumento, che non invia notifiche (rischio accettato n.1 del PRD). `viewAny()` resta invece `false` per i non amministratori.

Le due **non** vanno allineate per uniformita: l'elenco delle release con i suoi filtri e una superficie diversa dal dettaglio, e la sua Policy si decide con la schermata che la usa (US-009). Aprire `viewAny` adesso darebbe un'autorizzazione senza una pagina che la applichi.

`delete` resta negata a chiunque, amministratori inclusi: una release **e** lo storico di un rilascio.

Il dettaglio e comunque in sola lettura: compilare e chiudere sono decise da `ReleaseStepPolicy` (responsabile dello step attivo o amministratore), piu restrittiva di proposito.
