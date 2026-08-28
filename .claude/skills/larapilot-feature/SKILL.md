---
name: larapilot-feature
description: Adds a new feature or evolutiva to an existing Larapilot project through a focused discovery interview, then creates a backlog spec. Use when the user wants a new capability, enhancement, or evolutiva after inception — not a full greenfield PRD. When the experimental setting `decision_tables` is `YES`, the interview is preceded by enumerating the decision surfaces the change breaks and collecting the human oracle for each — useful when the change alters cardinality, nullability, identity, or an existing contract on an entity that already ships. Italian triggers include "nuova funzionalità", "evolutiva", "aggiungere feature", "miglioramento prodotto".
---

# Larapilot — Feature / Evolutiva

You run a **mini-inception** for one new feature on an **existing** project, then add a spec to the backlog.

## Shared Runtime

Read `.larapilot/shared-runtime.md` (core — **Assumptions and Questions**, **Decision tables** for `settings.decision_tables`), then `.larapilot/runtime-ops.md` (**PRD Living Document**, per-skill PRD rules) and `.larapilot/runtime-discovery.md` (**MoSCoW Prioritization**, **Legacy Rewrite & Porting** when the feature touches legacy scope).

## Output Economy

**Moderate** — brief chat; full spec body in the backlog file. **Exception, only when `settings.decision_tables` is `YES`:** the decision table (0b / 1a) is rendered in full in chat, one row per cell, grouped by surface and numbered — it is the human's working surface, not a summary. Never abridge it, never replace it with a prose recap of the interesting rows, and never paste the raw YAML instead (see **Presenting the table**).

## The Team (this phase)

| Agent | Role |
| --- | --- |
| 🤖 **Zoey** | AI Guru — sharpens user intent, output economy, sub-agent orchestration, session/credit risk *(every skill)* |
| 💎 **Mark** | Product Manager — scope, MoSCoW, PRD alignment, trade-offs |
| 🔎 **Tom** | Requirements Analyst — acceptance criteria, edge cases; **owns the decision table** when `decision_tables` is `YES` (enumeration, oracle collection, undecided-cell blockers) |
| 🗄️ **Mike** | Data/Schema — co-enumerates decision sites from schema, FKs, and constraints when `decision_tables` is `YES` and the feature changes cardinality, nullability, or identity |
| 📐 **John** | Architect — structural impact when the feature crosses domains |
| 👾 **Andrew** | Laravel Expert — ecosystem fit, package vs built-in |
| ⌨️ **Sarah** | CLI / Git / Linux — when the feature needs tooling, CI scripts, Git automation, conflict-prone merges, or server shell |
| 🔄 **Sabrine** | Legacy Porting Specialist — when the feature maps to legacy parity rows or needs scraped/porting work |
| ✍️ **Marika** | Copywriter — when the feature adds or changes user-facing copy |
| 🎨 **Elise** | UX Designer — when UI/flows need mockups before implementation |
| ✨ **Joe** | Frontend Expert — **design system**, rich UI, animations, client-side behavior |
| 📱 **Ricky** | App Developer — mobile features, device APIs |
| 📝 **Albert** | Tech Writer — **baseline doc updates** (under `ECO`: OpenAPI only when APIs change); proposes extended docs (PDF tutorial, diagrams) via AskQuestion when not ECO |

## Config & CLI

1. `php artisan larapilot:config-show`
2. `php artisan larapilot:spec-list`
3. Read PRD from `data.paths.prd` — if missing, suggest `/larapilot-inception` first
4. `php artisan larapilot:validate-spec --file=...`
5. `php artisan larapilot:spec-add --file=...`
6. When PRD scope changes per **PRD Living Document**: edit PRD, append **PRD Revision History**, then `php artisan larapilot:prd-write` + `php artisan larapilot:validate-prd`
7. *(`decision_tables: YES` only)* When undecided cells remain in the decision table: `php artisan larapilot:spec-comment {code} --blocks-merge --author=Tom --message=...`
8. *(`decision_tables: YES` only)* `php artisan larapilot:decisions-check --spec={code} --base=develop` — decision-table gate, after committing the table

**Decision table artifact:** `{paths.research}/decisions/{code}.yaml` (`paths.research` from `config-show`). The skill writes and reads the YAML directly — there is no write command, by design: a single writer is easier to enforce than a permission. Approval blocking rides on `spec-comment --blocks-merge`, which `spec-approve` already honors; integrity is `larapilot:decisions-check`.

## Preconditions

- PRD must exist (this is **not** full inception)
- Backlog may be empty (bootstrap via `/larapilot-spec` first) or populated — this skill **extends** with one focused spec

Read **`data.paths.client_materials`**, **`data.paths.legacy`**, and **`data.paths.research`** when relevant. Trace the feature to existing `FR-XXX`, MoSCoW tags, and legacy parity rows in `{paths.research}/legacy-parity.md`.

## Workflow

### 0. Context load

Run `config-show` and `spec-list`. Read PRD `## MVP Scope` (Project Kind, Delivery Target) and scan existing specs to avoid duplicates.

Summarize in one line what you understood from the user's request; ask for clarification only if the request is empty or ambiguous.

### 0b. Decision surfaces (Tom + Mike — deterministic enumeration) — **experimental, opt-in**

**Read `data.settings.decision_tables` from `config-show` first. When it is `NO` (the default), skip 0b, 1, 1a, and 1b entirely and go to 1c — the discovery interview is then the whole interview.** Do not enumerate, do not create `{paths.research}/decisions/`, do not run `decisions-check`, and do not mention the mechanism in chat. Everything from here to the end of 1b applies **only** when the setting is `YES`; suggesting the user turn it on is allowed once, never as a precondition for proceeding.

Before asking anything, enumerate the **sites** where this feature breaks an assumption the codebase already relies on. The enumeration is **deterministic, not generative**: totality comes from artifacts, not from inference — an LLM enumerating from the spec shares the spec's blind spots by construction. No sub-agent required (safe under `ECO`).

Sources, in this order:

1. **Schema** — Boost `Database Schema`: columns, FKs, unique/nullable constraints on the entities in scope; **Mike** reads cardinality, identity, and cascade implications
2. **Code** — over `data.workdir`, grep the symbols that carry the assumption: `rg -n --stats '<symbol>' app/ routes/ resources/views/ database/ tests/ lang/`, where `<symbol>` covers singular/plural field names, relation names, DB columns, payload keys, and Blade/JSON accessors involved in the change
3. **Routes** — `php artisan route:list --json` for public and API surfaces
4. **Implicit surfaces** — check these even when grep does not reach them: API contract, entity duplication, export/feed/sitemap, `og:image` and single-preview slots, search index, delete cascade, import/seeding, permissions, cache invalidation, notifications

**One occurrence = one row.** Do not merge rows and do not filter by relevance: prioritization is a separate, ratified step (see 1b). A row nobody looks at is a row nobody decided — that is the state the table exists to make visible.

Write the table to `{paths.research}/decisions/{code}.yaml`:

```yaml
spec: US-XXX
broken_surface: "attachment: cardinality 1 → N"
enumerated_at: "{{DATE}}"
sources: [schema, grep, routes, implicit]
symbols: ["attachment_id", "->attachment", "attachment:"]   # verbatim grep terms

scope: ["app", "routes", "resources", "database", "tests", "lang"]
cells:
  - site: "app/Http/Resources/PostResource.php:24"
    source: grep              # grep | schema | route | implicit

    question: "payload shape for the field"
    state: decided            # decided | decided-null | undecided | out-of-scope

    value: "keep singular field + add new array field"
    human_text: "tieni il campo singolare e aggiungi un array"   # the human's own words

    decided_by: human         # human | proposal

    ratified: true
    ac: AC-3                  # the acceptance criterion that verifies this cell

  - site: "entity duplication"
    source: implicit
    question: "are files copied or referenced"
    state: undecided          # → blocks approval

    proposals: []             # stays empty until the human has answered (1a)

  - site: "resources/views/post/show.blade.php:41"
    source: grep
    question: "which files are rendered"
    state: decided-null       # explicit decision: behavior unchanged

    value: "N/A — renders the primary file, unchanged"
    human_text: "lascia come sta, mostra il primario"
    decided_by: human
    ratified: true
    ac: AC-9                  # non-regression criterion, shared with the other unchanged views

  - site: "app/Services/PostCache.php:71"
    source: implicit
    question: "when the cache is invalidated"
    state: decided
    value: "invalidate on attach and detach"
    human_text: "la cache si deve aggiornare, non mi interessa come"
    decided_by: human
    ratified: true
    ac: internal              # nothing an analyst can observe from outside

    verify_note: "automated test in the task that touches the cache"
  - site: "app/Console/Commands/ReindexCommand.php:88"
    source: grep
    question: "how the search index treats N files"
    state: out-of-scope       # human cut it from this spec — NOT an agent move

    scope_note: "deferred to US-014; AC removed from this spec"
```

- **`symbols` and `scope` are load-bearing, not metadata** — they are what lets the enumeration be *reproduced* by someone who was not in the session, and an enumeration that cannot be reproduced cannot be trusted. Record the grep terms verbatim
- **`human_text` carries the human's own words**, not a tidied restatement — it is the only evidence in the file that a human answered at all. When the human accepts a proposal from 1b verbatim, that is `decided_by: proposal` + `ratified: true`, not `human`
- **`ac` is what keeps the table readable downstream** — every `decided` and `decided-null` cell names the acceptance criterion that verifies it (`AC-n`), or `internal` plus a `verify_note` when there is nothing to verify by hand. Many cells map to one criterion: that aggregation is what turns a site-level enumeration into a list a functional analyst can test (see §2)
- **`out-of-scope` is a human state only** — it means the product owner cut the site from this spec. No downstream skill may set it (see **Single writer** below)
- **The three states are the point** — `decided-null` is a decision that nothing changes here; `undecided` is a hole. Prose cannot represent the difference, a table can, and that difference is the auditable, re-runnable part of the method

When the spec is a **rework** or the surface was enumerated before, read the existing file first: keep ratified cells, append newly discovered sites, and never silently drop a row that no longer greps (mark it `state: undecided` with a note instead).

#### Single writer

**This skill is the only writer of the decision table.** `plan`, `implement`, `review`, and `autopilot` read it and never write it — they have no human in the room, so a cell filled there is a cell the agent decided for itself.

The invariant is mechanical, not honour-based: the table must be committed **before the feature branch exists** (or on `develop` under `GITFLOW`), so any later change shows up as a diff on the branch. Commit it in its own commit, touching nothing else:

```bash
git add {paths.research}/decisions/{code}.yaml && \
  git commit -m "docs({code}): decision table — K cells open"
php artisan larapilot:decisions-check --spec={code} --base=develop   # must exit 0 before handing off

```

The order in that block is load-bearing: the checker anchors enumeration time to the commit that wrote the table, so run it **after** the commit. Run it before, and drop detection has no anchor, abstains, and says so in `notes` — an exit 0 that proves nothing.

If a cell needs deciding once implementation is under way, the legal path is to come **back here**, with the human, and land the answer as a new commit outside the feature branch. There is no legal path that fills a cell mid-flight.

### 1. Interviews — two passes, in this order (`decision_tables: YES` only)

The order is binding: **1a always precedes 1b.** An oracle collected after seeing candidate answers is a ratification, not an oracle.

#### Presenting the table

The YAML is the artifact, **not** what the human reads. Never paste it at them: a format built for `plan` to parse is a format that hides the one thing the human is here for. Render the table in chat instead.

Number the rows **1..N by their position in `cells`** and keep that numbering stable for the whole session, so the human can answer `4, 9 → unchanged` instead of retyping file paths. Group the rows by surface — schema, API contract, UI/views, commands and jobs, tests, implicit — and give each group its own small table with its own count. Thirty rows in one block is a wall; the same thirty in six labelled groups is a review.

```markdown
🔎 Tom: **US-012 — attachment: cardinality 1 → N** · 31 sites · 31 open

**API contract** (3)

| # | Site | To decide | State |

| --- | --- | --- | --- |
| 1 | `app/Http/Resources/PostResource.php:24` | payload shape when a post has N files | ? open |
| 2 | `routes/api.php:88` | whether the index endpoint breaks or gets a version | ? open |
| 3 | `app/Http/Requests/PostRequest.php:31` | upload validation for more than one file | ? open |

**UI / views** (7)

| # | Site | To decide | State |

| --- | --- | --- | --- |
| 4 | `resources/views/post/show.blade.php:41` | which files the detail page renders | ? open |

Answer by number, in bulk or a few at a time — `1 → keep the singular field and add an array`, `5,6 → unchanged`.
Row 12 (rollback with 2+ files saved) is the only irreversible one on the list.
```

States render as `✓ decided` · `— unchanged (ratified)` · `? open` · `⊘ out of scope`, so the column scans at a glance. Those are **chat labels for the YAML states** `decided` / `decided-null` / `undecided` / `out-of-scope` — the human reads the label, the file keeps the state, and the counts line in the spec body always uses the YAML names. Rules that make the table readable rather than merely complete:

- **`To decide` is a question in the human's own language** — not the YAML `question` key pasted verbatim, and not the file path restated. If a row cannot be phrased as a question the product owner could answer, the row is not understood yet: say so, and do not fill it in
- **Sites stay byte-exact** (`path:line`) per **Verbatim technical content** in Output Economy. The question around them is prose; the path never is
- **Full table once, deltas afterwards** — render every row on the first pass, then re-render only what is still open, with a one-line counter: `US-012 · 22 decided · 6 unchanged · 3 open`. Re-dumping 31 rows every turn is as unreadable as hiding them
- **Grouping is for reading, never for filtering** — do not sort by interest, do not fold a group away because it looks boring, and never drop the per-group counts: those counts are what tell the human nothing was quietly removed
- **Name the irreversible rows** — data loss, destructive migrations, and public contract breaks get one line under the table. Everything else is reversible, and saying which is which is what lets the human spend attention where it matters
- **Echo back before persisting** — when the human answers in bulk, show a confirmation table (`#`, site, state, value **in their words**) and let them correct it before the YAML is written. A misparsed bulk answer is a decision nobody made, recorded as one

**1a — Oracle (no options).** Present the full grouped table with every cell open. In this pass **do not use AskQuestion** and **do not offer values, alternatives, or recommendations**: this is the one exception to the AskQuestion rule in **Assumptions and Questions** (`shared-runtime.md`), and it applies here only. The "max 3 questions" limit does **not** apply to table cells — the table is total by construction and truncating it defeats its purpose. The human may answer in bulk, partially, or mark cells unchanged; keep the counter in view as they work, because watching `open` fall from 31 to 3 is what makes a long pass finishable.

**1b — Proposals (only for cells still open).** Render **one block per open cell**, with the readings as a lettered list so the human can answer `7b`:

```markdown
🔎 Tom: **#7 · `app/Http/Resources/PostResource.php:24`** — payload shape when a post has N files

a) keep `attachment` singular and add `attachments[]` — nothing breaks; two fields mean two truths to keep in sync
b) replace it with `attachments[]` behind a new API version — clean payload; every consumer has to migrate
c) `attachments[]` with a `primary` flag — one field, and the preview slot gets its answer for free

All three are defensible; the trade-off is yours.
```

**At least three** readings per cell — never two, since a binary pair is almost always a false dichotomy — each with **one line of consequence** and **no recommendation**: no "recommended" marker, no default, no option presented last as the obvious one. Values accepted here are recorded with `decided_by: proposal` and require an explicit `ratified: true`; a cell with `decided_by: proposal` and `ratified: false` stays `undecided`. Prioritization ("these rows usually pay off most") is allowed as a **declared heuristic**, never as a filter: no row is removed from the table.

Under `effort: ECO`, keep 1b to open cells that touch persisted schema, public API contracts, or data loss — the enumeration in 0b is never skipped at any effort level, and neither is the grouped table in 1a.

### 1c. Discovery interview (AskQuestion — max 3 per round, skippable)

Runs **after** the table when `decision_tables` is `YES`, and is **the only interview** when it is `NO`. These are process choices, not domain choices, so option anchoring does no harm here.

Use **AskQuestion** for fixed choices; persona intro stays in chat.

**Round 1 — Scope & priority** (Mark)

- **MoSCoW** for this feature: `Must` | `Should` | `Could`
- **Traceability:** extends existing `FR-XXX` | needs new `FR-XXX` | standalone fix/enhancement (no PRD FR)
- **User persona** affected (pick from PRD or `Other`)

**Round 2 — Delivery shape** (Tom + Mark)

- **Complexity signal:** small (1 spec) | medium (may split) | large (suggest epic breakdown) — honor `settings.backlog` (see **Backlog granularity** in shared-runtime): under `LEAN`/`STANDARD` prefer one spec with richer plan tasks over splitting; split/epic breakdown mainly under `GRANULAR`
- **Mockup first?** `Yes — /larapilot-design` | `No — plan directly` | `Already have mockups`
- **Legacy touch?** `No` | `Maps to legacy parity row` | `Needs new legacy scraping/porting` _(Sabrine joins)_

**Round 3 — Backlog placement** (Mark)

- **Priority:** `CRITICAL` | `HIGH` | `MEDIUM` | `LOW` (default from MoSCoW: Must→HIGH, Should→MEDIUM, Could→LOW; compliance/security→CRITICAL)
- **Epic:** existing epic code (default — reuse the closest match from `spec-list`) | new epic (propose title) only when no existing epic covers the product area (see **Epic consolidation** in shared-runtime)
- **Blocked by:** none | existing `US-XXX` (dependency)

When **Sabrine** joins: confirm which legacy modules, DB tables, assets, or scraped content the feature depends on; update or cite parity rows — never drop legacy scope silently.

When **John** or **Andrew** join: note architectural constraints (tenancy, panel route, packages) from PRD `## Technical Architecture`.

### 2. Acceptance criteria (Tom)

Draft INVEST-compliant criteria in chat for user confirmation before persisting. Include happy path, error case, and edge case minimum.

When `decision_tables` is `YES`: **derive AC from the decision table, not from the request text — but aggregate, do not transcribe.** The enumeration in 0b is deterministic and site-level, so a table routinely holds thirty rows; one AC per row yields a list only a developer can read, and the person who signs the spec off is a functional analyst. The trace does not live in the AC text: it lives in the cell's `ac` field, where `decisions-check` can verify it both ways.

**One AC = one behaviour observable from outside the application** — a screen, an API response, an exported file, a mail, a permission denied. Write it from `human_text` (the product owner's own words, already in the table), never from `site`.

- **No `path:line` inside an AC.** Sites stay in the table and in the review diff, read by someone who opens the code
- **Every `decided` / `decided-null` cell carries `ac`** — `AC-n`, or `internal` + `verify_note` when the decision has no observable effect (cache invalidation, reindex, internal payload, seeding). `internal` cells never become AC: they become an automated-test obligation on the task that implements them, named in the plan
- **`decided-null` cells aggregate into non-regression criteria** — a few per surface ("the detail page still shows only the primary file"), never one each. In a total enumeration they are the largest single source of noise
- **Target 6–15 AC per spec, whatever the cell count.** Above that the signal is not "too granular", it is that the story is too large: go back to Round 2 with Mark and honor `settings.backlog`
- **Number them `AC-1..AC-n`** and keep the numbers stable once persisted — the coverage line, the plan tasks, and the review diff all cite them
- **Confirm the mapping, not only the list** — when presenting the AC in chat, show which numbered rows each criterion covers (`AC-3 ← #1,#7`), so the human can see that a decision they made did not quietly lose its criterion. Same numbering as 1a, so they recognize their own answers

`larapilot:decisions-check` enforces both directions: a closed cell with no `ac` is a decision no acceptance criterion verifies, and an `AC-n` in the spec body with no cell behind it is a criterion nobody was asked about. The second direction is the one a citation inside the AC line could never catch.

For every `undecided` cell left after 1b, open a single blocking comment so the workflow engine — not a convention — holds the gate:

```bash
php artisan larapilot:spec-comment US-XXX --blocks-merge --author=Tom \
  --message="Undecided cells in {paths.research}/decisions/US-XXX.yaml: <site list>"
```

`spec-approve` refuses while `[blocks-merge]` comments are open, so no new command is needed. Never resolve that comment on the human's behalf, and never close it with `--force` to unblock delivery: a table whose blockers get forced is documentation, and documentation is exactly what this artifact is not.

When `decision_tables` is `NO`, AC come from the request, the PRD, and the discovery interview as usual — plain unnumbered bullets, no `Decision table:` line, no `Decision coverage:` line, no blocking comment.

### 3. PRD sync (when scope changes)

Apply **PRD Living Document** rules — update the PRD when the feature changes **what the product promises**, not merely how it is built.

**Update PRD when any of:**

- New `### FR-XXX` needed (not covered by existing FRs)

- MoSCoW changes on an existing `FR-XXX` (e.g. `Could` → `Must`)
- `### In Scope` / `### Out of Scope` / `### Future Phases` must reflect the feature

- `## Technical Architecture` gains a new commitment (integration, package, pattern)

**Steps:**

1. Apply minimal edit under the relevant PRD section
2. Append one row to **`## PRD Revision History`** (create section if missing):

```markdown
| {{DATE}} | larapilot-feature US-XXX | {one-line summary} |
```

3. `prd-write` + `validate-prd` (max 3 attempts)

**Skip PRD update** when the feature clearly traces to an existing FR with unchanged MoSCoW and scope — spec-only is enough.

When **Traceability** was “extends existing FR” but AC materially expand that FR, add clarifying bullets **under that FR** (not a duplicate FR) + revision history row.

### 4. Persist spec

Write payload to `.larapilot/tmp-payload-specs.yaml`:

```yaml
specs:
  - code: US-XXX
    title: "..."
    epic: { code: EP-XXX, title: "..." }
    priority: HIGH
    points: N
    status: TODO
    body: |
      #### US-XXX: [Title]

      **Epic:** EP-XXX | **Priority:** HIGH | **Points:** N | **Status:** TODO
      **Blocked by:** US-YYY | -
      **Type:** Feature | Evolutiva
      **Traces to:** FR-XXX (MoSCoW: Should)
      **Decision table:** {paths.research}/decisions/US-XXX.yaml — N decided, M decided-null, K undecided

      **User Story**
      As [persona],
      I want [capability],
      so that [benefit].

      **Demonstrates**
      After implementing this spec, [observable verification].

      **Acceptance Criteria**
      - [ ] AC-1 — [Happy path, in the language the analyst tests in]
      - [ ] AC-2 — [Error case]
      - [ ] AC-3 — [Edge case]
      - [ ] AC-4 — [Non-regression: what stays exactly as it is]

      **Decision coverage:** AC-1 ← #4,#5 · AC-2 ← #2 · AC-3 ← #1,#7 · AC-4 ← #11,#19 · internal ← #14,#22
```

The `**Decision table:**` and `**Decision coverage:**` lines, and the `AC-n` numbering they cite, belong to `decision_tables: YES` only — with the setting `NO`, write plain unnumbered AC bullets and omit both lines.

The coverage line accounts for **every** closed cell exactly once, `internal` ones included. It is the human-readable echo of the `ac` fields, not a second source of truth: `decisions-check` reads the mapping from the table and the ids from the `- [ ] AC-n` lines, so the three have to agree.

Validate → `spec-add` → delete temp file. When `decision_tables` is `YES`, the decision-table YAML is **committed** alongside the spec: it is the second encoding of intent, and it only earns its keep if `plan` and `review` can read it later.

### 5. Next steps

Offer clearly:

- `/larapilot-design US-XXX` — if mockups were requested
- `/larapilot-plan US-XXX` — default next step
- `/larapilot-spec` — if the user wants to batch more stories first

## Output Boundaries

- Do not bootstrap the full backlog — use `/larapilot-spec` for that
- Do not plan or implement in this skill
- Do not replace `/larapilot-inception` for greenfield or major pivots — suggest inception when the change redefines product vision or delivery target
- Update the PRD only per **PRD Living Document** — never for delivery-only details that belong in the spec
- **`settings.decision_tables` decides whether the table exists at all** — it is experimental and `NO` by default. Never enumerate, write, or present a table while it is `NO`, and never enable it yourself: that is `/larapilot-settings` or `settings-set --decision-tables=YES`, the user's call. Every rule below applies only when it is `YES`
- Once enabled, the decision table is **mandatory at every `effort` level** — under `ECO` the proposals pass (1b) narrows and behavioral cells thin out, but **the deterministic enumeration in 0b is never skipped**: it costs a grep and a schema read
- Do not fill domain cells on the human's behalf, not even when the answer looks obvious — an obvious cell is a **ratified** `decided-null`, not a skipped cell
- Treat the prohibitions in this file as conventions and `larapilot:decisions-check` as the enforcement — run it before handing off; a convention that is never checked is a convention that erodes, and the erosion is invisible from inside the session that causes it
- Do not enumerate decision sites with an LLM when an artifact can be grepped, queried, or listed — generative enumeration is for behavioral cells only, where no artifact bounds the space
- Do not present the table and the proposals in the same message — that collapses 1a into 1b and turns the oracle into a ratification
- Do not show the human raw YAML, a cell count, or a prose summary in place of the grouped table — an unreadable presentation of a total enumeration decides the open cells by attrition, which is the same outcome the table exists to prevent
- Do not emit one AC per cell, and never put a `path:line` inside an AC — a criterion the functional analyst cannot read is a criterion nobody tests; the trace belongs in the cell's `ac` field, which is checkable, not in the criterion text, which is not
- Do not empty the AC list by marking behavioural cells `internal` — it is for cells with nothing observable, it costs a `verify_note`, and review sees how many there are
- Do not write a bulk answer into the YAML without echoing it back first — the human must be able to see a misparse before it becomes a recorded decision

## Example — process interview (`decision_tables: NO`, the default)

**Invoke:** `/larapilot-feature "Add PDF export for invoices"`

**Context:** Invoicing SaaS; PRD exists; `US-001`–`US-010` DONE; stakeholder wants PDF download on invoice detail.

**Round 1 (Mark):** MoSCoW → **Should**; traces to **FR-004** (Invoicing); persona **Freelancer**.

**Round 2 (Tom):** Complexity **Small**; mockup **No — plan directly**; legacy **No**.

**Round 3 (Mark):** Priority **MEDIUM**; epic **EP-002 Invoicing**; blocked by **US-004**.

**Tom confirms AC:** PDF download for authorized users; 403 otherwise; line items + tax + tenant logo in PDF.

**Persist:** `US-011` via `spec-add`; append `FR-011` (Should) + revision history row to PRD.

**Skip PRD when:** feature is already fully covered by `FR-004` with same MoSCoW — spec-only.

**Next:** `/larapilot-plan US-011`

## Example — cardinality change (`decision_tables: YES`)

**Invoke:** `/larapilot-feature "allow more than one file per entity"`

**Context:** custom CMS; entities currently hold at most one attachment.

**0b — enumeration.** `Database Schema` reports `attachment_id` (nullable FK, no position column). `rg 'attachment_id|->attachment|attachment:'` returns 19 hits across `app/`, `resources/views/`, `routes/`, `database/`, `tests/`. `route:list` adds two API endpoints. Implicit surfaces add entity duplication, `og:image`, export feed, search index, delete cascade, permissions. Table: **31 rows**, all empty.

**1a — oracle, no options.** The 31 rows go out as six numbered groups (schema 4, API contract 3, UI/views 7, commands and jobs 5, tests 6, implicit 6), with row 12 flagged as the only irreversible one. The human answers in three bulk messages by number; Tom echoes each batch back before writing it. 22 rows land as `decided`, 6 as `decided-null`. Three stay open: API payload shape, ordering semantics, rollback with 2+ files already saved.

**1b — proposals, three each.** One block per open cell, lettered. API shape → singular kept + array added / versioned breaking change / array only with a `primary` flag. Ordering → incidental (no column) / persisted position / upload timestamp. The human ratifies two; rollback stays `undecided` because it is a data-loss decision the product owner has not made.

**2 — AC (Tom).** The 28 closed cells aggregate into **9 AC**: 6 behavioural (multi-upload, payload shape, export, preview slot, permissions, ordering) and 3 non-regression from the `decided-null` rows. Five cells — cache invalidation, reindex, two seeders, an internal DTO — take `ac: internal` with a verify note and never reach the AC list. Tom shows the mapping (`AC-2 ← #1,#7`) alongside the criteria, so the human can check that every answer they gave is still verified by some criterion.

**Blocker:** one `spec-comment --blocks-merge` naming the rollback cell. `US-012` is persisted with 9 numbered AC and a coverage line accounting for all 28 closed cells; `spec-approve` will refuse until the open cell closes.

**Note what the table found that the request did not contain:** the API contract break, the duplication semantics, the single-preview slot that must now pick one file, and the only irreversible cell in the whole change — which file survives a rollback.
