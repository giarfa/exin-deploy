---
name: larapilot-feature
description: Adds a new feature or evolutiva to an existing Larapilot project by enumerating the decision surfaces it breaks, collecting the human oracle for each, then creating a backlog spec. Use when the user wants a new capability, enhancement, or evolutiva after inception — not a full greenfield PRD. Especially when the change alters cardinality, nullability, identity, or an existing contract on an entity that already ships. Italian triggers include "nuova funzionalità", "evolutiva", "aggiungere feature", "miglioramento prodotto".
---

# Larapilot — Feature / Evolutiva

You run a **mini-inception** for one new feature on an **existing** project, then add a spec to the backlog.

## Shared Runtime

Read `.larapilot/shared-runtime.md` (core — **Assumptions and Questions**), then `.larapilot/runtime-ops.md` (**PRD Living Document**, per-skill PRD rules) and `.larapilot/runtime-discovery.md` (**MoSCoW Prioritization**, **Legacy Rewrite & Porting** when the feature touches legacy scope).

## Output Economy

**Moderate** — brief chat; full spec body in the backlog file. **Exception:** the decision
table (0.5 / 1.a) is rendered in full in chat, one row per cell. It is the human's working
surface, not a summary — never abridge it, and never replace it with a prose recap of the
interesting rows.

## The Team (this phase)

| Agent | Role |
| --- | --- |
| 🤖 **Zoey** | AI Guru — sharpens user intent, output economy, sub-agent orchestration, session/credit risk *(every skill)* |
| 💎 **Mark** | Product Manager — scope, MoSCoW, PRD alignment, trade-offs |
| 🔎 **Tom** | Requirements Analyst — acceptance criteria, edge cases; **owns the decision table** (enumeration, oracle collection, undecided-cell blockers) |
| 🗄️ **Mike** | Data/Schema — co-enumerates decision sites from schema, FKs, and constraints when the feature changes cardinality, nullability, or identity |
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
7. When undecided cells remain in the decision table: `php artisan larapilot:spec-comment {code} --blocks-merge --author=Tom --message=...`

**Decision table artifact:** `{paths.research}/decisions/{code}.yaml` (`paths.research` from `config-show`). No dedicated CLI command — the skill writes and reads the YAML directly; enforcement rides on `spec-comment --blocks-merge`, which `spec-approve` already honors.

## Preconditions

- PRD must exist (this is **not** full inception)
- Backlog may be empty (bootstrap via `/larapilot-spec` first) or populated — this skill **extends** with one focused spec

Read **`data.paths.client_materials`**, **`data.paths.legacy`**, and **`data.paths.research`** when relevant. Trace the feature to existing `FR-XXX`, MoSCoW tags, and legacy parity rows in `{paths.research}/legacy-parity.md`.

## Workflow

### 0. Context load

Run `config-show` and `spec-list`. Read PRD `## MVP Scope` (Project Kind, Delivery Target) and scan existing specs to avoid duplicates.

Summarize in one line what you understood from the user's request; ask for clarification only if the request is empty or ambiguous.

### 0.5 Decision surfaces (Tom + Mike — deterministic enumeration)

Before asking anything, enumerate the **sites** where this feature breaks an assumption
the codebase already relies on. The enumeration is **deterministic, not generative**:
totality comes from artifacts, not from inference — an LLM enumerating from the spec
shares the spec's blind spots by construction. No sub-agent required (safe under `ECO`).

Sources, in this order:

1. **Schema** — Boost `Database Schema`: columns, FKs, unique/nullable constraints on the
   entities in scope; **Mike** reads cardinality, identity, and cascade implications
2. **Code** — over `data.workdir`, grep the symbols that carry the assumption:

   ```bash
   rg -n --stats '<symbol>' app/ routes/ resources/views/ database/ tests/ lang/
   ```

   where `<symbol>` covers singular/plural field names, relation names, DB columns,
   payload keys, and Blade/JSON accessors involved in the change
3. **Routes** — `php artisan route:list --json` for public and API surfaces
4. **Implicit surfaces** — check these even when grep does not reach them: API contract,
   entity duplication, export/feed/sitemap, `og:image` and single-preview slots, search
   index, delete cascade, import/seeding, permissions, cache invalidation, notifications

**One occurrence = one row.** Do not merge rows and do not filter by relevance:
prioritization is a separate, ratified step (see 1.b). A row nobody looks at is a row
nobody decided — that is the state the table exists to make visible.

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
  - site: "entity duplication"
    source: implicit
    question: "are files copied or referenced"
    state: undecided          # → blocks approval

    proposals: []             # stays empty until the human has answered (1.a)

  - site: "resources/views/post/show.blade.php:41"
    source: grep
    question: "which files are rendered"
    state: decided-null       # explicit decision: behavior unchanged

    value: "N/A — renders the primary file, unchanged"
    human_text: "lascia come sta, mostra il primario"
    decided_by: human
    ratified: true
  - site: "app/Console/Commands/ReindexCommand.php:88"
    source: grep
    question: "how the search index treats N files"
    state: out-of-scope       # human cut it from this spec — NOT an agent move

    scope_note: "deferred to US-014; AC removed from this spec"
```

**`symbols` and `scope` are load-bearing, not metadata.** They are what lets the
enumeration be *reproduced* by someone who was not in the session — and an enumeration
that cannot be reproduced cannot be trusted. Record the grep terms verbatim.

**`human_text` carries the human's own words**, not a tidied restatement. It is the only
evidence in the file that a human answered at all. When the human accepts a proposal from
1.b verbatim, that is `decided_by: proposal` + `ratified: true` — not `human`.

**`out-of-scope` is a human state only.** It means the product owner cut the site from this
spec. No downstream skill may set it (see **Single writer** below).

**The three states are the point.** `decided-null` is a decision that nothing changes
here; `undecided` is a hole. Prose cannot represent the difference — a table can, and
that difference is the auditable, re-runnable part of the method.

When the spec is a **rework** or the surface was enumerated before, read the existing
file first: keep ratified cells, append newly discovered sites, and never silently drop
a row that no longer greps (mark it `state: undecided` with a note instead).

#### Single writer

**This skill is the only writer of the decision table.** `plan`, `implement`, `review`,
and `autopilot` read it and never write it — they have no human in the room, so a cell
filled there is a cell the agent decided for itself.

The invariant is mechanical, not honour-based: the table must be committed **before the
feature branch exists** (or on `develop` under `GITFLOW`), so any later change shows up as
a diff on the branch. Commit it in its own commit, touching nothing else:

```bash
git add {paths.research}/decisions/{code}.yaml && \
  git commit -m "docs({code}): decision table — K cells open"
php bin/decisioni.php --spec={code} --base=develop   # must exit 0 before handing off

```

If a cell needs deciding once implementation is under way, the legal path is to come
**back here**, with the human, and land the answer as a new commit outside the feature
branch. There is no legal path that fills a cell mid-flight.

### 1. Interviews — two passes, in this order

The order is binding: **1.a always precedes 1.b.** An oracle collected after seeing
candidate answers is a ratification, not an oracle.

**1.a — Oracle (no options).** Present the table with empty cells in chat as a markdown
table. In this pass **do not use AskQuestion** and **do not offer values, alternatives,
or recommendations**: this is the one exception to the AskQuestion rule in
**Assumptions and Questions** (`shared-runtime.md`), and it applies here only. The
"max 3 questions" limit does **not** apply to table cells — the table is total by
construction and truncating it defeats its purpose. The human may answer in bulk,
partially, or mark cells `decided-null`.

**1.b — Proposals (only for cells still empty).** Now Tom proposes, per open cell,
**at least three** possible readings — never two, since a binary pair is almost always
a false dichotomy — and **no recommendation**. Values accepted here are recorded with
`decided_by: proposal` and require an explicit `ratified: true`. A cell with
`decided_by: proposal` and `ratified: false` stays `undecided`. Prioritization
("these rows usually pay off most") is allowed as a **declared heuristic**, never as a
filter: no row is removed from the table.

Under `effort: ECO`, keep 1.b to open cells that touch persisted schema, public API
contracts, or data loss — the enumeration in 0.5 is never skipped at any effort level.

### 1.c Discovery interview (AskQuestion — max 3 per round, skippable)

Runs **after** the table. These are process choices, not domain choices, so option
anchoring does no harm here.

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

**Derive AC from the decision table, not from the request text.** Every `decided` and
`decided-null` cell becomes one AC line citing its site — that citation is what makes the
criterion checkable at review time. Do not paraphrase several cells into one AC line:
one cell, one line, or the trace is lost.

For every `undecided` cell left after 1.b, open a single blocking comment so the workflow
engine — not a convention — holds the gate:

```bash
php artisan larapilot:spec-comment US-XXX --blocks-merge --author=Tom \
  --message="Undecided cells in {paths.research}/decisions/US-XXX.yaml: <site list>"
```

`spec-approve` refuses while `[blocks-merge]` comments are open, so no new command is
needed. Never resolve that comment on the human's behalf, and never close it with
`--force` to unblock delivery: a table whose blockers get forced is documentation, and
documentation is exactly what this artifact is not.

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
      - [ ] [Happy path]
      - [ ] [Error case]
      - [ ] [Edge case]
      - [ ] [Decided cell] — {site} → {value}
      - [ ] [Decided-null cell] — {site} → behavior unchanged (ratified)
```

Validate → `spec-add` → delete temp file. The decision-table YAML is **committed**
alongside the spec: it is the second encoding of intent, and it only earns its keep if
`plan` and `review` can read it later.

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
- The decision table is **mandatory at every `effort` level**. Under `ECO` the proposals
  pass (1.b) narrows and behavioral cells thin out; **the deterministic enumeration in
  0.5 is never skipped** — it costs a grep and a schema read
- Do not fill domain cells on the human's behalf, not even when the answer looks obvious:
  an obvious cell is a **ratified** `decided-null`, not a skipped cell
- Treat the prohibitions in this file as conventions, and `bin/decisioni.php` as the
  enforcement. Run it before handing off; a convention that is never checked is a
  convention that erodes, and the erosion is invisible from inside the session that causes it
- Do not enumerate decision sites with an LLM when an artifact can be grepped, queried,
  or listed. Generative enumeration is for behavioral cells only, where no artifact
  bounds the space
- Do not present the table and the proposals in the same message — that collapses 1.a
  into 1.b and turns the oracle into a ratification

## Example — process interview

**Invoke:** `/larapilot-feature "Add PDF export for invoices"`

**Context:** Invoicing SaaS; PRD exists; `US-001`–`US-010` DONE; stakeholder wants PDF download on invoice detail.

**Round 1 (Mark):** MoSCoW → **Should**; traces to **FR-004** (Invoicing); persona **Freelancer**.

**Round 2 (Tom):** Complexity **Small**; mockup **No — plan directly**; legacy **No**.

**Round 3 (Mark):** Priority **MEDIUM**; epic **EP-002 Invoicing**; blocked by **US-004**.

**Tom confirms AC:** PDF download for authorized users; 403 otherwise; line items + tax + tenant logo in PDF.

**Persist:** `US-011` via `spec-add`; append `FR-011` (Should) + revision history row to PRD.

**Skip PRD when:** feature is already fully covered by `FR-004` with same MoSCoW — spec-only.

**Next:** `/larapilot-plan US-011`

## Example — cardinality change (decision table in action)

**Invoke:** `/larapilot-feature "allow more than one file per entity"`

**Context:** custom CMS; entities currently hold at most one attachment.

**0.5 — enumeration.** `Database Schema` reports `attachment_id` (nullable FK, no
position column). `rg 'attachment_id|->attachment|attachment:'` returns 19 hits across
`app/`, `resources/views/`, `routes/`, `database/`, `tests/`. `route:list` adds two API
endpoints. Implicit surfaces add entity duplication, `og:image`, export feed, search
index, delete cascade, permissions. Table: **31 rows**, all empty.

**1.a — oracle, no options.** The human fills 22 rows directly and marks 6 as
`decided-null`. Three stay open: API payload shape, ordering semantics, rollback with 2+
files already saved.

**1.b — proposals, three each.** API shape → singular kept + array added / versioned
breaking change / array only with a `primary` flag. Ordering → incidental (no column) /
persisted position / upload timestamp. The human ratifies two; rollback stays
`undecided` because it is a data-loss decision the product owner has not made.

**Blocker:** one `spec-comment --blocks-merge` naming the rollback cell. `US-012` is
persisted with 30 AC lines citing sites; `spec-approve` will refuse until the cell closes.

**Note what the table found that the request did not contain:** the API contract break,
the duplication semantics, the single-preview slot that must now pick one file, and the
only irreversible cell in the whole change — which file survives a rollback.
