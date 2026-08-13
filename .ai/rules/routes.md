---
paths:
  - routes/web.php
---

# Routes

## La rotta /step/{releaseStep} non deve avere un ->can()
Tutte le rotte di questo progetto che hanno qualcosa da autorizzare portano doppia protezione (middleware `->can()` + Gate nel componente). La rotta di chiusura step e l'unica **deroga**, ed e voluta: il middleware rifiuterebbe prima che il codice applicativo possa registrare il tentativo non autorizzato nel log e nel registro delle transizioni, che e un criterio di accettazione di US-005 (FR-012).

Non confonderla con `/` (`my-steps.index`), che pure e senza `->can()`: li non c'e alcuna ability da valutare — la schermata proietta soltanto cio che e assegnato a chi guarda, e `auth` e la sola precondizione. Aggiungere un `->can()` a `/` non romperebbe nulla, sarebbe solo senza oggetto; aggiungerlo a `/step/{releaseStep}` spegne il tracciamento.

Il controllo non e piu debole: `authorizeOrRecord()` in `⚡step.blade.php` registra e poi fa `abort(403)`, al montaggio e su ogni azione Livewire.

Aggiungendo il `->can()` fallisce `ReleaseStepPolicyTest::test_a_denied_read_is_logged_but_does_not_fill_the_register`. Vedi vincolo permanente 12 nel README.
