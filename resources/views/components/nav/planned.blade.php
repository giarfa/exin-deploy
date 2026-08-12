@props(['icon' => null])

{{--
    Voce di navigazione di una sezione non ancora implementata.

    Resta visibile e non cliccabile: la struttura del prodotto deve essere
    leggibile da subito, ma non deve promettere una pagina che non esiste.
    `aria-disabled` invece di `disabled` perche non e un controllo di form:
    e annunciata come non disponibile senza uscire dall'ordine di tabulazione.
--}}
<div class="flex h-10 items-center gap-3 rounded-lg px-3 text-sm text-zinc-400 dark:text-zinc-500"
     aria-disabled="true">
    @if ($icon)
        <flux:icon :name="$icon" variant="outline" class="size-5 shrink-0 opacity-60" />
    @endif

    <span class="truncate">{{ $slot }}</span>

    <flux:badge size="sm" color="zinc" class="ms-auto shrink-0">{{ __('app.nav_planned') }}</flux:badge>
</div>
