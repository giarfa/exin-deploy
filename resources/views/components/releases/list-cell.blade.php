@props(['label', 'heading' => false])

{{--
    Cella dell'elenco delle release.

    Esiste per una ragione sola: sotto 1024 px la tabella diventa una pila di card,
    e l'etichetta di colonna — che sopra sta nell'intestazione — deve ricomparire
    accanto al valore. La rende il pseudo-elemento con `attr(data-label)`, cosi che
    l'etichetta viva in un attributo e non in un secondo albero DOM: duplicare il
    contenuto per breakpoint lo farebbe leggere due volte a uno screen reader.

    L'etichetta arriva dallo stesso array che genera l'intestazione, quindi le due
    non possono divergere.

    `heading` marca la prima cella, quella che porta il collegamento al dettaglio:
    a blocco e senza etichetta ripetuta, perche il nome del progetto e il titolo
    della card e non un campo fra gli altri.
--}}
@php
    $classes = $heading
        ? 'px-3 py-3 font-medium max-lg:block max-lg:px-0 max-lg:py-0'
        : 'px-3 py-3 max-lg:flex max-lg:justify-between max-lg:gap-4 max-lg:px-0 max-lg:py-0'
            .' max-lg:before:shrink-0 max-lg:before:text-zinc-500 max-lg:before:content-[attr(data-label)]'
            .' dark:max-lg:before:text-white/70';
@endphp

<td data-label="{{ $label }}" {{ $attributes->class($classes) }}>{{ $slot }}</td>
