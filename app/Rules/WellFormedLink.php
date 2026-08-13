<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Il valore fornito a un campo di tipo link deve essere un indirizzo scritto per
 * intero, e il rifiuto dice **cosa correggere**.
 *
 * `url:http,https` rifiuterebbe con un messaggio unico ("non e un indirizzo
 * valido"), mentre il mockup della chiusura fissa il contratto opposto:
 * "Indirizzo non valido: manca lo schema (https://) e contiene uno spazio". Chi
 * compila incolla un indirizzo copiato da una pipeline o da una chat, e i modi in
 * cui quell'incollatura si rompe sono pochi e riconoscibili: senza nominarli,
 * l'unica strada che resta e indovinare.
 *
 * Piu difetti sullo stesso valore vengono elencati **insieme**, e non uno per
 * tentativo: un secondo rifiuto dopo la prima correzione e la stessa informazione
 * data nel momento peggiore.
 *
 * **La raggiungibilita dell'indirizzo non viene verificata**, e non e una
 * semplificazione: sarebbe una chiamata di rete dentro una validazione — quindi
 * una richiesta HTTP che attende un server di terzi mentre l'utente guarda il
 * pulsante — e soprattutto i report a cui questi campi rimandano vivono su
 * strumenti interni che il server applicativo non raggiunge. Un indirizzo valido
 * verrebbe rifiutato come rotto.
 *
 * La regola tace sui valori vuoti: l'obbligatorieta la decidono `required` e
 * `nullable` in `ReleaseStepField::closingRules()`, e un secondo messaggio sullo
 * stesso campo direbbe due volte la stessa cosa in modi diversi.
 */
class WellFormedLink implements ValidationRule
{
    /**
     * Schemi ammessi: un indirizzo che si apre dal browser di chi legge il
     * rilascio. `ftp://` o `file://` non lo sono, e `javascript:` sarebbe una
     * superficie di attacco offerta a chi consulta lo storico.
     *
     * @var list<string>
     */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return;
        }

        if (! is_string($value)) {
            $fail('validation.well_formed_link.not_a_string')->translate();

            return;
        }

        $defects = $this->defectsOf(trim($value));

        if ($defects === []) {
            return;
        }

        $fail('validation.well_formed_link.message')->translate([
            // `Arr::join` e non `implode`: in italiano l'ultimo elemento si lega
            // con "e", e "manca lo schema, contiene uno spazio" si legge come un
            // elenco troncato invece che come due difetti.
            'defects' => Arr::join($defects, ', ', ' '.__('validation.well_formed_link.and').' '),
        ]);
    }

    /**
     * Difetti riconosciuti nel valore, gia tradotti e nell'ordine in cui si
     * correggono.
     *
     * @return list<string>
     */
    private function defectsOf(string $value): array
    {
        $defects = [];

        // `parse_url()` non viene usato per lo schema: su un valore malformato
        // torna `false` e porterebbe via anche l'informazione che serve dire.
        $scheme = preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):\/\//', $value, $matches) === 1
            ? strtolower($matches[1])
            : null;

        if ($scheme === null) {
            $defects[] = __('validation.well_formed_link.missing_scheme');
        } elseif (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            $defects[] = __('validation.well_formed_link.unsupported_scheme', ['scheme' => $scheme]);
        }

        if (preg_match('/\s/u', $value) === 1) {
            $defects[] = __('validation.well_formed_link.contains_whitespace');
        }

        $host = $this->hostOf($value, $scheme);

        if ($host === '') {
            $defects[] = __('validation.well_formed_link.missing_host');
        } elseif (! $this->isPlausibleHost($host)) {
            $defects[] = __('validation.well_formed_link.malformed_host', ['host' => $host]);
        }

        return $defects;
    }

    /**
     * Parte del valore che nomina il sito, anche quando lo schema manca: senza
     * schema il nome del sito e comunque il primo segmento, ed e cio che chi ha
     * incollato l'indirizzo si aspetta di leggere nel messaggio.
     */
    private function hostOf(string $value, ?string $scheme): string
    {
        $remainder = $scheme === null
            ? $value
            : substr($value, strlen($scheme) + 3);

        // Autorita: tutto fino al primo separatore di percorso, query o frammento.
        $authority = preg_split('/[\/?#]/', $remainder, 2)[0] ?? '';

        // Le credenziali nell'indirizzo non sono il nome del sito; la porta
        // nemmeno. Nominarle nel messaggio direbbe il problema sbagliato.
        if (str_contains($authority, '@')) {
            $authority = substr($authority, strrpos($authority, '@') + 1);
        }

        // Il letterale IPv6 (`[::1]`) conserva i due punti che lo compongono: solo
        // fuori dalle parentesi i due punti separano la porta.
        if (str_starts_with($authority, '[')) {
            $closing = strpos($authority, ']');

            if ($closing !== false) {
                $authority = substr($authority, 0, $closing + 1);
            }
        } elseif (str_contains($authority, ':')) {
            $authority = strstr($authority, ':', true) ?: $authority;
        }

        return trim($authority);
    }

    /**
     * Il nome del sito e plausibile: lettere, cifre, punti e trattini, con inizio
     * e fine alfanumerici, oppure un letterale IPv6.
     *
     * Non e una verifica di esistenza — quella richiederebbe la rete — ma il
     * rifiuto di cio che nessun browser aprirebbe.
     */
    private function isPlausibleHost(string $host): bool
    {
        if (preg_match('/^\[[0-9a-fA-F:]+\]$/', $host) === 1) {
            return true;
        }

        return preg_match('/^[\p{L}\p{N}]([\p{L}\p{N}\-.]*[\p{L}\p{N}])?$/u', $host) === 1;
    }
}
