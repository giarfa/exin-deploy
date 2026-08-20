#!/usr/bin/env php
<?php

/**
 * decisioni.php — integrity gate for Larapilot decision tables.
 *
 * Runs OUTSIDE the agent session (CI, pre-push hook, or by hand). Its only job is to
 * make one specific failure mode detectable: an agent filling `undecided` cells to keep
 * the pipeline moving. Every check below is deterministic and exit-code driven, so it
 * cannot be satisfied by writing prose.
 *
 *   php bin/decisioni.php                       # check every table
 *   php bin/decisioni.php --spec=US-012         # one spec
 *   php bin/decisioni.php --base=develop        # branch-immutability check
 *   php bin/decisioni.php --json                # larapilot/v1-shaped envelope
 *
 * Exit codes follow the Larapilot CLI contract: 0 ok · 1 process error · 2 findings.
 */

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

const STATES = ['decided', 'decided-null', 'undecided', 'out-of-scope'];

$root = getcwd();

foreach ([$root . '/vendor/autoload.php', $root . '/../vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

if (! class_exists(Yaml::class)) {
    fwrite(STDERR, "decisioni: symfony/yaml not found — run from the project root.\n");
    exit(1);
}

$opts = getopt('', ['spec::', 'base::', 'json', 'dir::', 'backlog::']) ?: [];
$json = array_key_exists('json', $opts);
$only = $opts['spec'] ?? null;
$base = $opts['base'] ?? null;
$dir = rtrim($opts['dir'] ?? '.larapilot/research/decisions', '/');
$backlogPath = $opts['backlog'] ?? '.larapilot/backlog.yaml';

/** @var list<array{spec:string,site:string,check:string,detail:string}> $findings */
$findings = [];

$fail = static function (string $spec, string $site, string $check, string $detail) use (&$findings): void {
    $findings[] = compact('spec', 'site', 'check', 'detail');
};

$normalize = static fn (string $s): string => preg_replace('/\s+/u', ' ', mb_strtolower(trim($s))) ?? '';

$git = static function (string $args): array {
    exec('git ' . $args . ' 2>/dev/null', $out, $code);

    return [$code === 0, array_values(array_filter($out, static fn ($l) => trim($l) !== ''))];
};

// ---------------------------------------------------------------------------
// Check 1 — branch immutability.
//
// The table is filled by /larapilot-feature, with a human present. plan and implement
// may only read it. So any change to it on a feature branch is, by construction, an
// agent fill: there was no human in those skills to fill it.
// ---------------------------------------------------------------------------
if ($base !== null && is_dir($dir)) {
    [$ok, $changed] = $git(sprintf('diff --name-only %s...HEAD -- %s', escapeshellarg($base), escapeshellarg($dir)));

    if (! $ok) {
        $fail('-', $dir, 'branch-immutability', sprintf('cannot diff against %s — is it fetched?', $base));
    }

    foreach ($changed as $file) {
        $fail('-', $file, 'branch-immutability', sprintf(
            'decision table modified on this branch. Cells are filled in /larapilot-feature, '
            . 'with a human present; a change here means the agent decided. Revert, then re-run '
            . 'the feature skill for the open cells.'
        ));
    }
}

$files = $only !== null
    ? array_filter([sprintf('%s/%s.yaml', $dir, $only)], 'is_file')
    : (glob($dir . '/*.yaml') ?: []);

if ($files === []) {
    $payload = ['schema' => 'larapilot/v1', 'kind' => 'decision_check', 'data' => [
        'ok' => $findings === [], 'tables' => 0, 'findings' => $findings, 'notes' => [],
    ]];
    echo $json ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n" : "decisioni: no tables found in {$dir}\n";
    exit($findings === [] ? 0 : 2);
}

$backlog = is_file($backlogPath) ? (Yaml::parseFile($backlogPath) ?: []) : [];
$bodies = [];

foreach (($backlog['specs'] ?? $backlog) as $spec) {
    if (is_array($spec) && isset($spec['code'], $spec['body'])) {
        $bodies[(string) $spec['code']] = (string) $spec['body'];
    }
}

[, $branchFiles] = $base !== null ? $git(sprintf('diff --name-only %s...HEAD', escapeshellarg($base))) : [true, []];

$notes = [];

/** @var array<string, array<string, true>> $treeCache */
$treeCache = [];

/**
 * Tree as it stood when a decision table was written, memoized per anchor commit.
 *
 * Re-enumeration must compare against that tree and not the working tree: files created
 * by the implementation legitimately match the symbols and are not dropped sites.
 *
 * The anchor is the last commit that touched the table itself, never the tip of --base.
 * Branch immutability means the table cannot have moved since enumeration, so that commit
 * *is* enumeration time — and it stays the right answer after the feature branch merges,
 * when --base has absorbed the implementation and can no longer answer the question.
 * Reading the tip instead made every file a spec created look, one merge later, like a
 * site dropped from the table: a finding on a decision that was in fact ratified.
 *
 * Returns null when the table is not committed yet. That is not enumeration time we can
 * anchor, and it is also the one moment when there is nothing to catch — /larapilot-feature
 * has just written the table and no implementation exists to have dropped a site.
 */
$enumerationTree = static function (string $tableFile) use ($git, &$treeCache): ?array {
    [$ok, $out] = $git(sprintf('log -1 --format=%%H -- %s', escapeshellarg($tableFile)));
    $anchor = $ok ? trim((string) ($out[0] ?? '')) : '';

    if ($anchor === '') {
        return null;
    }

    if (! array_key_exists($anchor, $treeCache)) {
        [$treeOk, $tracked] = $git(sprintf('ls-tree -r --name-only %s', escapeshellarg($anchor)));

        if (! $treeOk) {
            return null;
        }

        $treeCache[$anchor] = array_fill_keys($tracked, true);
    }

    return $treeCache[$anchor];
};

foreach ($files as $file) {
    $table = Yaml::parseFile($file);

    if (! is_array($table) || ! isset($table['cells']) || ! is_array($table['cells'])) {
        $fail(basename($file, '.yaml'), $file, 'schema', 'missing or malformed `cells` list');
        continue;
    }

    $spec = (string) ($table['spec'] ?? basename($file, '.yaml'));
    $counts = array_fill_keys(STATES, 0);

    foreach ($table['cells'] as $i => $cell) {
        $site = (string) ($cell['site'] ?? sprintf('cells[%d]', $i));
        $state = (string) ($cell['state'] ?? '');

        if (! in_array($state, STATES, true)) {
            $fail($spec, $site, 'schema', sprintf('state `%s` not in {%s}', $state, implode(', ', STATES)));
            continue;
        }

        $counts[$state]++;

        // -------------------------------------------------------------------
        // Check 2 — provenance.
        //
        // A decided cell must carry the human's own words verbatim. This does not make
        // forgery impossible; it makes it an explicit act rather than a silent default,
        // which is the difference that matters for drift.
        // -------------------------------------------------------------------
        if (in_array($state, ['decided', 'decided-null'], true)) {
            $by = (string) ($cell['decided_by'] ?? '');
            $text = trim((string) ($cell['human_text'] ?? ''));

            if (! in_array($by, ['human', 'proposal'], true)) {
                $fail($spec, $site, 'provenance', 'decided_by must be `human` or `proposal`');
            }

            if ($text === '') {
                $fail($spec, $site, 'provenance', 'decided cell without `human_text` — no record that a human answered');
            }

            if ($by === 'proposal' && ($cell['ratified'] ?? false) !== true) {
                $fail($spec, $site, 'provenance', 'decided_by: proposal requires `ratified: true`');
            }

            // A proposal echoed back as if it were the human's own answer.
            foreach ((array) ($cell['proposals'] ?? []) as $proposal) {
                if ($by === 'human' && $text !== '' && $normalize((string) $proposal) === $normalize($text)) {
                    $fail($spec, $site, 'provenance', 'human_text is verbatim one of the proposals — record it as decided_by: proposal + ratified');
                }
            }
        }

        // -------------------------------------------------------------------
        // Check 3 — out-of-scope integrity.
        //
        // out-of-scope is the legal escape hatch: it lets the agent keep moving without
        // deciding. It is only honest if the site really goes untouched, so verify it.
        // -------------------------------------------------------------------
        if ($state === 'out-of-scope') {
            if (trim((string) ($cell['scope_note'] ?? '')) === '') {
                $fail($spec, $site, 'out-of-scope', 'requires `scope_note` naming what was cut from the spec');
            }

            $path = strtok($site, ':');

            foreach ($branchFiles as $touched) {
                if ($path !== false && $path !== '' && str_contains($touched, $path)) {
                    $fail($spec, $site, 'out-of-scope', sprintf('site declared out of scope but %s changed on this branch', $touched));
                }
            }
        }

        if ($state === 'undecided' && ($cell['proposals'] ?? []) !== [] && ! isset($cell['proposals_shown_after_oracle'])) {
            $fail($spec, $site, 'anchoring', 'proposals present on an undecided cell without `proposals_shown_after_oracle: true` — 1.a may have been collapsed into 1.b');
        }
    }

    // -----------------------------------------------------------------------
    // Check 4 — counts vs. spec body.
    //
    // The spec body is written once by spec-add and lives in a different file under
    // different write paths. Two encodings of the same fact must agree; a table edited
    // after the spec was persisted shows up here for free.
    // -----------------------------------------------------------------------
    $body = $bodies[$spec] ?? null;

    if ($body === null) {
        $fail($spec, $backlogPath, 'counts', 'spec not found in backlog — table has no counterpart to cross-check');
    } elseif (preg_match('/(\d+)\s+decided\s*,\s*(\d+)\s+decided-null\s*,\s*(\d+)\s+undecided/i', $body, $m)) {
        $declared = ['decided' => (int) $m[1], 'decided-null' => (int) $m[2], 'undecided' => (int) $m[3]];

        foreach ($declared as $state => $n) {
            if ($counts[$state] !== $n) {
                $fail($spec, $file, 'counts', sprintf(
                    'spec body declares %d `%s`, table holds %d — the table changed after the spec was persisted',
                    $n,
                    $state,
                    $counts[$state]
                ));
            }
        }
    } else {
        $fail($spec, $backlogPath, 'counts', 'spec body carries no `Decision table:` counts line to cross-check');
    }

    // -----------------------------------------------------------------------
    // Check 5 — re-enumeration (anti-shrink).
    //
    // Re-run the grep the table claims to be built from. Fewer rows than live hits means
    // sites were dropped — the one failure the whole method is built to prevent.
    // -----------------------------------------------------------------------
    $symbols = (array) ($table['symbols'] ?? []);
    $scope = (array) ($table['scope'] ?? ['app', 'routes', 'resources', 'database', 'tests', 'lang']);
    $scope = array_values(array_filter($scope, static fn ($p) => is_string($p) && is_dir($p)));
    $enumeratedTree = $enumerationTree($file);

    if ($enumeratedTree === null) {
        $notes[] = sprintf(
            'drop detection skipped for %s: the decision table is not committed, so enumeration time has no anchor',
            $spec
        );
    }

    if ($symbols === []) {
        $fail($spec, $file, 're-enumeration', 'no `symbols` recorded — the enumeration cannot be reproduced, so it cannot be trusted');
    } elseif ($scope !== [] && $enumeratedTree !== null) {
        $hits = [];

        foreach ($symbols as $symbol) {
            exec(sprintf(
                'grep -rniIl -- %s %s 2>/dev/null',
                escapeshellarg((string) $symbol),
                implode(' ', array_map('escapeshellarg', $scope))
            ), $out);

            foreach ($out as $line) {
                $hits[trim($line)] = true;
            }

            $out = [];
        }

        $covered = [];

        foreach ($table['cells'] as $cell) {
            $path = strtok((string) ($cell['site'] ?? ''), ':');

            if ($path !== false && $path !== '') {
                $covered[$path] = true;
            }
        }

        foreach (array_keys($hits) as $hit) {
            if (isset($covered[$hit])) {
                continue;
            }

            // Present now but absent from the enumeration-time tree = created by the
            // implementation, not a dropped site.
            if (! isset($enumeratedTree[$hit])) {
                continue;
            }

            $fail($spec, $hit, 're-enumeration', 'file existed at enumeration time and matches a recorded symbol, but has no cell — site dropped from the table');
        }
    }
}

$payload = ['schema' => 'larapilot/v1', 'kind' => 'decision_check', 'data' => [
    'ok' => $findings === [],
    'tables' => count($files),
    'findings' => $findings,
    'notes' => $notes,
]];

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} elseif ($findings === []) {
    printf("decisioni: OK — %d table(s), no findings.\n", count($files));

    foreach ($notes as $note) {
        printf("  note: %s\n", $note);
    }
} else {
    printf("decisioni: %d finding(s) across %d table(s)\n\n", count($findings), count($files));

    foreach ($findings as $f) {
        printf("  [%s] %s · %s\n      %s\n", $f['check'], $f['spec'], $f['site'], $f['detail']);
    }

    echo "\nA finding is not a formatting nit: each one names a decision nobody ratified.\n";
}

exit($findings === [] ? 0 : 2);
