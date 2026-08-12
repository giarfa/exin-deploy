---
paths:
  - 'tests/**'
---

# Tests

## Codici OTP non riutilizzabili nei test; assertRedirect mente sugli errori
Fortify memorizza i codici 2FA consumati in cache (`fortify.2fa_codes.<md5>`) e `verifyKeyNewer` ne rifiuta il riutilizzo. Un test che genera un codice con `Google2FA::getCurrentOtp()`, lo usa per confermare l'enrolment e poi lo riusa per il challenge FALLISCE — ed è comportamento corretto, non un bug. Soluzioni: predisporre lo stato 2FA già confermato scrivendo `two_factor_secret`/`two_factor_confirmed_at` sul modello (vedi `TwoFactorTest::userWithConfirmedTwoFactor()`), oppure `Cache::flush()` tra i passaggi.

Secondo inganno: in questo progetto `session('errors')` è un array, non un `ViewErrorBag`. Quando `assertRedirect()` (o un'altra asserzione sulla risposta) fallisce, Laravel tenta di arricchire il messaggio in `TestResponseAssert::injectResponseContext()` chiamando `->all()` su quell'array e va in errore: il test riporta "Call to a member function all() on array" invece del diff reale. Non è quello il bug — l'asserzione sottostante è fallita. Per capire il motivo vero: leggere `$response->headers->get('Location')` e `json_encode(session('errors'))` a mano.
