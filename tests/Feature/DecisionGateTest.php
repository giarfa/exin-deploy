<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * `bin/decisioni.php` e il cancello che protegge il metodo delle decisioni, quindi e
 * anche il posto dove un errore fa il danno peggiore: se smette di vedere un sito
 * lasciato cadere, nessuno se ne accorge — un gate verde non si va a controllare.
 *
 * Il caso che ha motivato questi test e reale. Il check anti-shrink deve distinguere
 * "file che c'era quando la tabella e stata scritta" da "file creato
 * dall'implementazione", e usava come discriminante l'albero alla punta di `--base`.
 * Quella approssimazione regge finche si sta sul ramo di feature e scade nell'istante
 * in cui il ramo entra in `develop`: da quel momento ogni file creato dalla spec
 * risulta preesistente e viene segnalato come sito caduto. US-013 lo ha prodotto
 * subito dopo il merge, su una decisione che era stata invece ratificata.
 *
 * I test girano su un repo git costruito sul momento e non su questo. E l'unico modo
 * di riprodurre "dopo il merge": serve controllare la storia, e la storia di questo
 * repo non e materiale di prova.
 */
class DecisionGateTest extends TestCase
{
    private string $repo = '';

    protected function tearDown(): void
    {
        // `rm -rf` e non un cancellatore ricorsivo scritto a mano: dentro il repo di
        // prova c'e un symlink a `vendor` di questo progetto, e un iteratore che lo
        // seguisse cancellerebbe le dipendenze vere. `rm` rimuove il link, non il
        // contenuto.
        if ($this->repo !== '' && str_starts_with($this->repo, sys_get_temp_dir()) && is_dir($this->repo)) {
            Process::run(['rm', '-rf', $this->repo]);
        }

        $this->repo = '';

        parent::tearDown();
    }

    public function test_a_file_created_by_the_implementation_is_not_a_dropped_site_after_the_merge(): void
    {
        // Il secondo commit sta sullo stesso ramo passato a `--base`: e esattamente la
        // situazione dopo il merge, quando la base ha assorbito l'implementazione.
        $repo = $this->repoWithTable(
            atEnumeration: $this->enumerationState(),
            fromImplementation: ['app/NatoDallImplementazione.php' => '<?php // marcatoreDiEnumerazione'],
        );

        $envelope = $this->gate($repo, '--base=develop');

        $this->assertTrue(
            $envelope['data']['ok'],
            'un file creato dalla spec non e un sito caduto, nemmeno dopo il merge: '.json_encode($envelope['data']['findings'])
        );
    }

    public function test_a_file_that_existed_at_enumeration_time_without_a_cell_is_still_reported(): void
    {
        // La proprieta che il fix non deve perdere. Senza questo caso il test sopra si
        // soddisfa anche disattivando il check.
        $repo = $this->repoWithTable(
            atEnumeration: $this->enumerationState() + ['app/Caduto.php' => '<?php // marcatoreDiEnumerazione'],
            fromImplementation: [],
        );

        $envelope = $this->gate($repo, '--base=develop');

        $this->assertFalse($envelope['data']['ok']);
        $this->assertSame('re-enumeration', $envelope['data']['findings'][0]['check']);
        $this->assertSame('app/Caduto.php', $envelope['data']['findings'][0]['site']);
    }

    public function test_an_uncommitted_table_has_no_anchor_and_says_so_instead_of_guessing(): void
    {
        // Tabella scritta da /larapilot-feature e non ancora committata: l'enumerazione
        // non ha un ancoraggio nella storia. Qui il check si astiene e lo dichiara,
        // invece di leggere un albero qualsiasi — ed e anche il momento in cui non c'e
        // nulla da intercettare, perche nessuna implementazione esiste ancora.
        $repo = $this->repoWithTable(
            atEnumeration: ['app/Enumerato.php' => '<?php // marcatoreDiEnumerazione'],
            fromImplementation: [],
        );

        $this->writeFiles($repo, [
            '.larapilot/backlog.yaml' => $this->backlog(),
            '.larapilot/research/decisions/US-900.yaml' => $this->table(),
            'app/Caduto.php' => '<?php // marcatoreDiEnumerazione',
        ]);

        $envelope = $this->gate($repo);

        $this->assertTrue($envelope['data']['ok'], json_encode($envelope['data']['findings']));
        $this->assertStringContainsString(
            'drop detection skipped for US-900',
            implode(' ', $envelope['data']['notes'])
        );
    }

    /**
     * Stato al momento dell'enumerazione: la tabella, il backlog che ne rispecchia i
     * conteggi, e l'unico file che la tabella dichiara di aver visto.
     *
     * @return array<string, string>
     */
    private function enumerationState(): array
    {
        return [
            '.larapilot/backlog.yaml' => $this->backlog(),
            '.larapilot/research/decisions/US-900.yaml' => $this->table(),
            'app/Enumerato.php' => '<?php // marcatoreDiEnumerazione',
        ];
    }

    private function backlog(): string
    {
        return <<<'YAML'
        specs:
          - code: US-900
            body: |
              **Decision table:** .larapilot/research/decisions/US-900.yaml — 1 decided, 0 decided-null, 0 undecided
        YAML;
    }

    private function table(): string
    {
        return <<<'YAML'
        spec: US-900
        enumerated_at: "2026-08-20"
        symbols: ["marcatoreDiEnumerazione"]
        scope: ["app"]
        cells:
          - site: "app/Enumerato.php:1"
            state: decided
            value: "resta invariato"
            human_text: "invariato"
            decided_by: human
            ratified: true
        YAML;
    }

    /**
     * @param  array<string, string>  $atEnumeration
     * @param  array<string, string>  $fromImplementation
     */
    private function repoWithTable(array $atEnumeration, array $fromImplementation): string
    {
        $this->repo = sys_get_temp_dir().'/decisioni-'.bin2hex(random_bytes(6));

        mkdir($this->repo, 0755, true);
        symlink(base_path('vendor'), $this->repo.'/vendor');

        $this->git('init', '-b', 'develop');
        $this->git('config', 'user.email', 'gate@example.test');
        $this->git('config', 'user.name', 'Gate');

        $this->writeFiles($this->repo, $atEnumeration);
        $this->git('add', '-A');
        $this->git('commit', '-m', 'enumerazione');

        if ($fromImplementation !== []) {
            $this->writeFiles($this->repo, $fromImplementation);
            $this->git('add', '-A');
            $this->git('commit', '-m', 'implementazione');
        }

        return $this->repo;
    }

    /**
     * @param  array<string, string>  $files
     */
    private function writeFiles(string $repo, array $files): void
    {
        foreach ($files as $path => $content) {
            $full = $repo.'/'.$path;

            if (! is_dir(dirname($full))) {
                mkdir(dirname($full), 0755, true);
            }

            file_put_contents($full, $content."\n");
        }
    }

    private function git(string ...$args): void
    {
        $result = Process::path($this->repo)->run(array_merge(['git'], $args));

        $this->assertSame(0, $result->exitCode(), 'git '.implode(' ', $args).': '.$result->errorOutput());
    }

    /**
     * @return array{data: array{ok: bool, findings: list<array{spec: string, site: string, check: string, detail: string}>, notes: list<string>}}
     */
    private function gate(string $repo, string ...$args): array
    {
        $result = Process::path($repo)->run(
            array_merge(['php', base_path('bin/decisioni.php'), '--json'], $args)
        );

        $envelope = json_decode($result->output(), true);

        $this->assertIsArray($envelope, 'il gate non ha prodotto un envelope: '.$result->output().$result->errorOutput());

        return $envelope;
    }
}
