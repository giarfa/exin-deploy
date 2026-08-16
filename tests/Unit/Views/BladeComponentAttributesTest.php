<?php

namespace Tests\Unit\Views;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Guardia sull'intera cartella delle viste: nessuna direttiva Blade dentro
 * l'attributo di un **componente**.
 *
 * Negli elementi HTML nativi le direttive vengono compilate come ovunque. Nelle
 * attribute bag dei componenti (`<x-…>`, `<flux:…>`, `<livewire:…>`) no: il
 * compilatore dei tag traduce l'interpolazione `{{ }}` in concatenazione PHP, ma
 * lascia le direttive come testo — che raggiunge il browser cosi com'e e vi
 * muore. Il difetto e silenzioso in PHP, visibile solo in console, e questo
 * progetto lo ha gia scritto due volte: nei filtri dell'elenco dei rilasci
 * (`wire:click` con `@js()`) e nel comando di scambio della sfida 2FA (`x-text`
 * con `@js()`), entrambi corretti da US-012.
 *
 * Le forme corrette stanno in `.ai/rules/views.md`.
 */
class BladeComponentAttributesTest extends TestCase
{
    /**
     * Le direttive che, dentro l'attributo di un componente, arrivano intatte al
     * browser. `@js` e quella che serve davvero — le altre due sono la stessa
     * trappola scritta in altro modo.
     *
     * @var list<string>
     */
    private const FORBIDDEN_DIRECTIVES = ['@js', '@json', '@class'];

    /**
     * Ogni vista e un caso a se: il fallimento nomina il file, non "le viste".
     *
     * @return iterable<string, array{string}>
     */
    public static function bladeViews(): iterable
    {
        $root = dirname(__DIR__, 3).'/resources/views';

        $files = Finder::create()->files()->in($root)->name('*.blade.php')->sortByName();

        foreach ($files as $file) {
            $relative = 'resources/views/'.str_replace('\\', '/', $file->getRelativePathname());

            yield $relative => [$file->getRealPath()];
        }
    }

    #[DataProvider('bladeViews')]
    public function test_a_view_carries_no_blade_directive_inside_a_component_attribute(string $path): void
    {
        $source = (string) file_get_contents($path);
        $findings = $this->directivesInComponentAttributes($this->withoutBladeComments($source));

        $this->assertSame([], $findings, implode("\n", array_map(
            fn (array $finding): string => sprintf(
                '%s:%d — `%s(` dentro l\'attributo di un componente: nelle attribute bag le direttive non vengono compilate. Vedi .ai/rules/views.md.',
                basename($path),
                $finding['line'],
                $finding['directive'],
            ),
            $findings,
        )));
    }

    /**
     * La guardia deve reggere le forme legittime, altrimenti il primo che la
     * incontra la disattiva invece di correggere il codice: `@js()` in un
     * elemento HTML nativo, in testo libero, o dentro un commento Blade — dove
     * questo progetto lo cita di proposito per spiegare il divieto.
     */
    public function test_the_guard_leaves_the_legitimate_forms_alone(): void
    {
        $legitimate = <<<'BLADE'
            {{-- Nota: la forma <flux:button wire:click="@js($x)"> e quella vietata. --}}
            <div x-data="@js($state)">nativo: qui la direttiva viene compilata</div>
            <flux:button wire:click="$set('filtro', '{{ $value }}')">Testo con @js() in chiaro</flux:button>
            BLADE;

        $this->assertSame([], $this->directivesInComponentAttributes($this->withoutBladeComments($legitimate)));
    }

    /**
     * ...e deve cadere sulle due forme reali che US-012 ha corretto, altrimenti
     * e una guardia che sorveglia il vuoto.
     */
    public function test_the_guard_catches_both_shapes_that_shipped_broken(): void
    {
        $index = '<flux:button size="sm" wire:click="$set(\'statusFilter\', @js($filter[\'value\']))">x</flux:button>';
        $challenge = '<flux:link as="button" x-text="recovery ? @js(__(\'a\')) : @js(__(\'b\'))" />';

        $this->assertCount(1, $this->directivesInComponentAttributes($index));
        $this->assertCount(2, $this->directivesInComponentAttributes($challenge));
    }

    /**
     * I commenti Blade spariscono prima di ogni altra compilazione, quindi non
     * possono contenere un difetto — ma la loro lunghezza va conservata, o i
     * numeri di riga riportati dal fallimento indicherebbero il punto sbagliato.
     */
    private function withoutBladeComments(string $source): string
    {
        return (string) preg_replace_callback(
            '/\{\{--.*?--\}\}/s',
            fn (array $match): string => preg_replace('/[^\n]/', ' ', $match[0]) ?? '',
            $source,
        );
    }

    /**
     * Direttive trovate nella regione degli attributi dei tag di componente.
     *
     * La regione non si delimita con una sola espressione regolare: un `>` puo
     * comparire dentro il valore di un attributo (`x-show="a > b"`), e fermarsi
     * li taglierebbe la ricerca a meta. Si percorre il testo tenendo conto degli
     * apici, fino al primo `>` fuori da una stringa.
     *
     * @return list<array{directive: string, line: int}>
     */
    private function directivesInComponentAttributes(string $source): array
    {
        $findings = [];

        preg_match_all('/<(?:x-|flux:|livewire:)[\w.:-]+/', $source, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$tag, $position]) {
            $start = $position + strlen($tag);
            $end = $this->endOfAttributeRegion($source, $start);
            $region = substr($source, $start, $end - $start);

            foreach (self::FORBIDDEN_DIRECTIVES as $directive) {
                $offset = 0;

                while (($found = strpos($region, $directive.'(', $offset)) !== false) {
                    $findings[] = [
                        'directive' => $directive,
                        'line' => substr_count($source, "\n", 0, $start + $found) + 1,
                    ];

                    $offset = $found + 1;
                }
            }
        }

        return $findings;
    }

    /**
     * Posizione del `>` che chiude il tag, ignorando quelli dentro un valore.
     */
    private function endOfAttributeRegion(string $source, int $start): int
    {
        $length = strlen($source);
        $quote = null;

        for ($i = $start; $i < $length; $i++) {
            $character = $source[$i];

            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;

                continue;
            }

            if ($character === '>') {
                return $i;
            }
        }

        return $length;
    }
}
