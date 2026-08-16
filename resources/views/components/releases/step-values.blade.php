@props(['fields'])

{{--
    Informazioni fornite alla chiusura di uno step, in sola lettura.

    Vive in **una copia sola** perche le schermate che le mostrano sono due — la
    chiusura dello step (US-005) e il dettaglio della release (US-008) — e dentro
    questo blocco vive la verifica dello schema del collegamento: due copie
    sarebbero due posti in cui dimenticare di correggerla.

    Lista di definizione e non tabella: a 375 px due colonne di testo lungo
    obbligherebbero allo scorrimento orizzontale.
--}}
<dl class="space-y-4">
    @foreach ($fields as $field)
        <div>
            <dt class="text-sm font-medium text-zinc-800 dark:text-white">{{ $field->label }}</dt>

            <dd class="mt-0.5 text-sm break-words text-zinc-500 dark:text-white/70">
                @if ($field->value === null)
                    {{ __('releases.step_value_not_provided') }}
                @elseif ($field->type === App\Enums\FieldType::Confirmation)
                    <span class="inline-flex items-center gap-1.5">
                        <flux:icon name="check-circle" variant="mini" class="size-4 shrink-0" />
                        {{ __('releases.step_value_confirmed') }}
                    </span>
                @elseif ($field->type === App\Enums\FieldType::Link && Illuminate\Support\Str::startsWith($field->value, ['http://', 'https://']))
                    {{--
                        Lo schema viene verificato **anche** qui, dove il valore
                        diventa un collegamento cliccabile: `WellFormedLink` lo
                        garantisce in scrittura, ma una riga arrivata da un import o
                        da una correzione a mano sul database non passa da quella
                        regola, e un `javascript:` reso come href sarebbe una
                        superficie offerta proprio a chi consulta lo storico. Un
                        valore non conforme resta leggibile come testo.

                        `rel` esplicito: i browser recenti implicano `noopener` su
                        `target="_blank"`, ma dichiararlo non dipende dalla versione
                        di chi legge.
                    --}}
                    <flux:link :href="$field->value" external rel="noopener noreferrer">{{ $field->value }}</flux:link>
                @else
                    {{ $field->value }}
                @endif
            </dd>
        </div>
    @endforeach
</dl>
