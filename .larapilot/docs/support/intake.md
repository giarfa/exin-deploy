# Registro delle segnalazioni

Intake normalizzato delle segnalazioni raccolte dopo la consegna di una spec.
Ogni voce nasce da `/larapilot-bug` e cita la spec di destinazione: la cronologia
operativa vive qui, non nel PRD.

## BUG-20260816-filtri-stato-rilasci

- **Segnalata:** 2026-08-16
- **Severità:** High
- **Ambiente:** Locale (`http://exin-deploy.test`, Laravel Herd, Chrome 151)
- **Sintesi:** I filtri di stato "In corso" e "Conclusa" nell'elenco dei rilasci non
  applicano alcun filtro: la schermata resta identica dopo il click.
- **Passi per riprodurre:**
  1. Autenticarsi e aprire `http://exin-deploy.test/rilasci`
  2. Premere il bottone "In corso" (o "Conclusa")
  3. L'elenco non cambia, l'indirizzo non acquisisce `?stato=…`, il bottone non
     assume lo stato premuto
  4. Nella console del browser compare `Livewire Expression Error: Invalid or
     unexpected token — Expression: "$set('statusFilter', @js($filter['value']))"`
- **Atteso / Ottenuto:**
  - Atteso: premendo "In corso" resta la sola sezione dei rilasci in corso, con
    `?stato=in_corso` nell'indirizzo; premendo "Conclusa" resta il solo storico.
  - Ottenuto: nessun filtro applicato, nessuna richiesta Livewire, errore JavaScript
    a ogni click.
- **Causa individuata:** in `resources/views/components/releases/⚡index.blade.php:370`
  l'attributo `wire:click` è passato a un **componente Blade** (`<flux:button>`).
  Nelle attribute bag dei componenti Blade `{{ }}` viene interpolato ma `@js()`
  **non viene compilato**: resta testo. La vista compilata lo mostra letteralmente —
  `'wire:click' => '$set(\'statusFilter\', @js($filter[\'value\']))'` — e il browser
  riceve un'espressione che Livewire non sa valutare.
- **Seconda occorrenza, stessa causa:** `resources/views/auth/two-factor-challenge.blade.php:41`
  usa `@js()` dentro `x-text` su `<flux:link>`; il testo del comando che scambia
  codice TOTP e codice di recupero non viene reso (US-001).
- **Lacuna di copertura:** `tests/Feature/Releases/ReleaseIndexScreenTest.php` esercita
  `->set('statusFilter', …)`, cioè la proprietà lato server, che funziona
  regolarmente. Il cablaggio del bottone reso non è mai stato attraversato da un test.
- **Spec interessate:** US-009 (filtri dell'elenco), US-001 (sfida 2FA) — entrambe `DONE`
- **Instradata a:** `spec-add US-012` — spec di fix nell'epica di manutenzione EP-005
  (`spec-request-changes` non è percorribile: richiede lo stato `REVIEW`)
- **Sicurezza:** no — nessun coinvolgimento di Lars/Oliver; il difetto 2FA riguarda
  l'etichetta di un comando, non il controllo dell'autenticazione a due fattori
- **PRD:** nessun aggiornamento — correzione di implementazione, il comportamento
  atteso è già scritto nei criteri di US-009 e US-001
