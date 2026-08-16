---
paths:
  - 'resources/views/**'
---

# Views

## Nessuna direttiva Blade dentro l'attributo di un componente
Negli attributi di un **componente** (`<x-…>`, `<flux:…>`, `<livewire:…>`) l'interpolazione `{{ }}` viene compilata — il compilatore dei tag la traduce in concatenazione PHP — ma le direttive **no**: `@js()`, `@json()`, `@class()` restano testo e raggiungono il browser cosi come sono, dove muoiono in console. Negli elementi HTML nativi vengono compilate normalmente, ed e per questo che la trappola non si vede.

Il progetto ci e caduto due volte, entrambe corrette da US-012: `wire:click="$set('statusFilter', @js($filter['value']))"` sui filtri di `resources/views/components/releases/⚡index.blade.php` (i bottoni non producevano alcuna richiesta Livewire) e `x-text="recovery ? @js(...) : @js(...)"` su `<flux:link>` in `resources/views/auth/two-factor-challenge.blade.php` (il comando restava senza testo).

Le tre forme corrette, in ordine di preferenza:
1. **Vocabolario chiuso e privo di apici** — interpolazione diretta: `wire:click="$set('campo', '{{ $valore }}')"`.
2. **Valore arbitrario** — attributo legato, con la stringa costruita in PHP: `:wire:click="'$set(\'campo\', '.Js::from($valore).')'"`.
3. **Testo con apici destinato a un'espressione Alpine** — nessuna stringa JavaScript: elementi alternati con `x-show`, come nella sfida 2FA. Inseguire l'escaping di un apice ridecodificato dal browser e la strada che si rompe di nuovo.

`tests/Unit/Views/BladeComponentAttributesTest.php` scandisce tutto `resources/views` e fallisce riportando file e riga. Non disattivarlo: i commenti Blade e gli elementi nativi sono gia esclusi.

Nota adiacente: `x-cloak` non nasconde nulla da solo. La regola `[x-cloak] { display: none !important; }` sta in `resources/css/app.css` — ne Tailwind ne Flux la portano.
