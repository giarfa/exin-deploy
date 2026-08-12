# Mockup — Superficie operativa (Exin Deploy)

Riferimento visivo per la parte quotidiana del prodotto: **i miei step**, **dettaglio release**,
**chiusura di uno step**, **elenco release**. Non copre la superficie di configurazione
(progetti, ruoli, template, campi), che è CRUD densa a uso rado e verrà disegnata a parte.

**Contesto:** PRD `.larapilot/docs/PRD.md` — Project Kind `Personal`, Delivery Target `MVP`,
Frontend Topology `Laravel-coupled`, Admin panel `Starter Kit (livewire)`.
Requisiti coperti: **FR-010** … **FR-017** (avanzamento sequenziale, chiusura con validazione,
autorizzazione, vista "i miei step", dettaglio release, storico, registro, conclusione).

Nel PRD il prodotto non ha ancora un nome commerciale: i mockup usano **Exin Deploy**,
derivato dal nome del repository. Se il nome cambia, va aggiornato in `logo.svg` e nei `<title>`.

## Design system

**Laravel Starter Kit — variante `livewire`.**

| Risorsa | Percorso |
| --- | --- |
| Regole del sistema | `.larapilot/design-systems/starter-kit/README.md` |
| Indice varianti | `.larapilot/design-systems/starter-kit/sources.md` |
| Pattern di layout | `.larapilot/design-systems/starter-kit/components.md` |
| Token (copia locale) | `starter-kit-tokens.css` — copia verbatim di `tokens.css` |
| Kit ufficiale | [github.com/laravel/livewire-starter-kit](https://github.com/laravel/livewire-starter-kit) |

**Layout variant:** sidebar (default del kit) con `sk-inset` e header di breadcrumb.
Nessuna variante header o inset flottante.

I token del design system **non sono stati modificati**. Le estensioni di dominio vivono in
`mockup.css` con prefisso `exd-`, così che un aggiornamento del design system non venga sovrascritto.

### Token e palette

Palette neutra del kit, senza override di brand: `primary` near-black, `sidebar` off-white,
`destructive` rosso oklch, `radius` 0.625rem, font **Instrument Sans**. Il PRD non definisce
colori di brand e lo strumento è interno, quindi la palette di serie è quella corretta —
non c'è identità da rispettare.

Un solo colore ha significato di dominio, ed è preso dai token: `--destructive` per gli errori
di validazione. Gli stati della catena (completato / in corso / bloccato) **non usano colori
semantici**: usano peso, bordo, tratteggio e icona (vedi Accessibilità).

## File

| File | Contenuto | Viewport di riferimento |
| --- | --- | --- |
| `index.html` | **Primaria** — "I miei step": attività dell'utente su tutti i progetti | 375 px (mobile first) |
| `dark.html` | `index.html` in tema scuro + verifica contrasto dei tre stati della catena | 375 px |
| `dettaglio-release.html` | Catena completa dei 5 step con stati, responsabili e valori già forniti | 375 px → 1024 px+ |
| `chiusura-step.html` | Form di chiusura con i 4 tipi di campo e **stato di errore** | 375 px |
| `desktop.html` | **Companion desktop** — elenco release con tabella dati | 1280 px |
| `starter-kit-tokens.css` | Token del design system (copia) | — |
| `mockup.css` | Componenti di dominio, mobile first | — |
| `logo.svg`, `favicon.svg` | Asset di brand minimi | — |

Navigabile in locale su `/mockups/superficie-operativa` (rotta `larapilot.mockups.show`,
attiva solo fuori produzione).

### Mappatura sulle pagine del kit

| Schermata mockup | Implementazione nel kit |
| --- | --- |
| I miei step (`index.html`) | Nuova pagina Livewire dentro `AppLayout` — è la **home post-login**, sostituisce la dashboard placeholder del kit |
| Elenco release (`desktop.html`) | Pagina Livewire con tabella; non è una Filament Resource |
| Dettaglio release | Pagina Livewire con componente catena |
| Chiusura step | Form Livewire con validazione lato server |
| Login / reset / 2FA | Viste Fortify nel layout auth del kit (`html/login.html` come riferimento) |

### Mappatura sui componenti Flux (variante `livewire`)

| Elemento del mockup | Componente Flux |
| --- | --- |
| `.sk-sidebar` + nav | `flux:sidebar`, `flux:navlist`, `flux:navlist.item` |
| Toggle mobile | `flux:sidebar.toggle` |
| Menu utente in footer | `flux:dropdown` + `flux:profile` |
| Breadcrumb | `flux:breadcrumbs` |
| `.sk-btn--*` | `flux:button` con `variant` primary / outline / ghost / danger |
| `.sk-input`, `textarea` | `flux:input`, `flux:textarea` |
| `.exd-check` | `flux:checkbox` |
| `.exd-status` | `flux:badge` — **con icona + testo**, non solo colore |
| `.exd-alert` | `flux:callout variant="danger"` |
| `.exd-chain` | **Componente custom** — non esiste in Flux, va costruito (vedi sotto) |

Solo componenti **Flux free**: il PRD esclude una licenza Flux Pro. Se un componente
necessario risultasse Pro, va sostituito con markup proprio, non aggiunta la licenza
senza decisione esplicita.

## Componente da costruire: la catena degli step

È l'unico componente non derivabile dal kit, ed è il cuore visivo del prodotto.
Contratto che Alex deve rispettare:

- Markup **`<ol>`** — è una sequenza ordinata, non un elenco generico: l'ordine è informazione.
- Un elemento per step, con classe di stato: `--done`, `--active`, `--locked`.
- Marcatore circolare a sinistra: `✓` per completato, il **numero di posizione** per attivo e bloccato.
- Linea verticale di collegamento tra i marcatori, **assente sull'ultimo elemento**.
- Stato attivo: bordo pieno del colore del testo + anello attorno al marcatore. Stato bloccato:
  bordo e marcatore **tratteggiati**, testo attenuato. Completato: sfondo `secondary`, testo attenuato.
- Ogni step espone sempre **ruolo + nome del responsabile**: la responsabilità non va mai desunta.
- Gli step completati mostrano i valori forniti in `<dl>` (una colonna su mobile, due da 1024 px).
- Il pulsante di apertura appare **solo** sullo step attivo e **solo** al responsabile o a un
  amministratore — nascondere il pulsante non è autorizzazione, la Policy lato server resta obbligatoria.

## Responsive & navigazione _(contratto per Alex e Anne)_

**Mobile First:** ogni schermata è definita a 375 px e arricchita salendo. Nessuna schermata
è un desktop rimpicciolito.

| Breakpoint | Comportamento |
| --- | --- |
| **320 px** | Layout a colonna singola. Card degli step a piena larghezza. Il breadcrumb può troncare le voci intermedie, mai l'ultima. Nessun taglio orizzontale. |
| **375 px** | **Riferimento primario.** Un'azione primaria per card ("Apri e compila") a piena larghezza. Testo base 16 px. |
| **768 px** | Ancora colonna singola; sidebar ancora chiusa. Le azioni del form passano in linea (primaria a destra). Valori dei campi ancora su una colonna. |
| **1024 px** | **Soglia sidebar:** la sidebar diventa permanente, il toggle scompare. Dettaglio release passa a due colonne (catena + riquadro dati, `sticky`). Elenco release passa da card a tabella. Valori dei campi su due colonne. Densità dei controlli ridotta (altezza 2,25 rem). |
| **1280 px** | Larghezza di riferimento desktop. Nessun cambio strutturale oltre 1024 px. |
| **1920 px** | Il form di chiusura resta a `max-width: 42rem` e la catena non si allarga oltre il leggibile: nessuna riga di testo lunghissima. |

**Nota sulla soglia sidebar.** `tokens.css` del design system nasconde la sidebar sotto **768 px**,
mentre `components.md` prescrive **1024 px**. Ho adottato **1024 px** (override in `mockup.css`),
perché tra 768 e 1024 px la tabella dell'elenco release e le due colonne del dettaglio non stanno
insieme alla sidebar. Alex deve usare **una sola soglia** in tutta l'applicazione: 1024 px.

**Pattern di navigazione:** hamburger in alto a sinistra che apre la sidebar come drawer/sheet
sovrapposto sotto 1024 px. Un solo pattern in tutta l'app — nessuna bottom bar, nessun tab bar,
per non moltiplicare i modelli mentali.

- Trigger: `aria-expanded` aggiornato, `aria-controls` verso il contenitore del drawer.
- Alla chiusura il focus torna sul trigger; il focus resta intrappolato nel drawer mentre è aperto.
- `Esc` chiude il drawer.
- Breadcrumb visibile su ogni pagina più profonda dell'elenco (dettaglio release, chiusura step).

**Nessuno scroll orizzontale a nessun breakpoint.** La tabella dell'elenco release **non** usa
`overflow-x-auto`: cambia forma, diventando card impilate con etichette da `data-label`.
È la soluzione corretta qui perché le colonne sono poche e leggibili come coppie chiave-valore.

**Orientamento:** in landscape su telefono (circa 667×375) il layout resta a colonna singola e la
sidebar resta chiusa — la soglia è sulla larghezza, quindi la rotazione non cambia struttura.
Da verificare che l'header non occupi troppa altezza utile: `min-height` 3,5 rem è accettabile.

## Accessibilità _(WCAG 2.2 AA — cosa Alex deve preservare)_

Dimostrato nei mockup e **non negoziabile** in implementazione:

- **Landmark semantici:** `header`, `nav`, `main`, `footer`; un solo `h1` per pagina.
- **Skip link** `Salta al contenuto` come primo elemento focalizzabile, visibile solo al focus.
- **Focus visibile** su ogni elemento interattivo (`:focus-visible`, outline 2 px + offset 2 px),
  non solo su input e bottoni. Non rimuovere l'outline senza sostituirlo.
- **Stati mai dal solo colore:** ogni `.exd-status` è **icona + parola** ("✓ Completato", "● In corso",
  "◻ Bloccato"). Requisito 1.4.1, e requisito pratico: chi non distingue i colori è la maggioranza
  dei casi di daltonismo nei team tecnici.
- **Label associata** a ogni controllo tramite `for`/`id`. Nessun placeholder usato come etichetta.
- **Obbligatorietà annunciata a parole:** l'asterisco è `aria-hidden`, accanto c'è
  `<span class="exd-sr-only"> (obbligatorio)</span>`.
- **Errori collegati programmaticamente:** `aria-invalid="true"` sul campo e `aria-describedby`
  che punta al paragrafo di errore. Il testo di errore **dice cosa correggere**
  ("manca lo schema `https://` e contiene uno spazio"), non "campo non valido".
- **Riepilogo errori** in cima al form con `role="alert"` e link ai campi; ricevere il focus
  dopo un tentativo rifiutato.
- **`aria-live="polite"`** sul contatore degli step in attesa e sullo stato della release:
  cambiano dopo un aggiornamento Livewire senza ricaricare la pagina.
- **Target touch ≥ 44×44 px** sotto 1024 px (bottoni 2,75 rem, toggle 2,75 rem, checkbox in
  contenitore cliccabile). Densità desktop consentita solo da 1024 px.
- **`prefers-reduced-motion`** rispettato: le transizioni si azzerano. Non introdurre animazioni
  di transizione tra step senza fallback.
- **Contrasto verificato in entrambi i temi** — `dark.html` include i tre stati della catena
  proprio perché lo stato "bloccato" (tratteggiato + testo attenuato) è il caso limite.
  Se il testo attenuato scende sotto 4,5:1, va scurito: **non** è ammesso comunicare
  "bloccato" solo con l'attenuazione.

Da verificare in implementazione, non dimostrabile in un mockup statico: ordine di tabulazione
dopo l'apertura del drawer, annuncio degli aggiornamenti Livewire, gestione del focus dopo la
chiusura di uno step (il focus deve andare su un elemento significativo, non tornare a inizio pagina).

## Copy e microcopy _(Marika)_

Tono **diretto e operativo**, in italiano, seconda persona singolare. Regole applicate:

- Il turno si dichiara come fatto, non come domanda: **"Tocca a te"**, non "Azione richiesta".
- Le azioni descrivono la conseguenza: **"Chiudi lo step e prosegui"**, non "Salva".
- Ogni step attivo dichiara **a chi passa il flusso** dopo la chiusura: rende visibile la catena
  a chi non la conosce.
- Gli stati vuoti spiegano cosa accadrà: "Quando un collega chiude il proprio step e tocca a te,
  lo trovi qui".
- Gli errori indicano la correzione, non la colpa.
- Le durate sono relative dove serve urgenza ("da 2 giorni"), assolute nello storico
  ("04/08/2026 17:32").

Testi da mettere in `lang/it/` e non nelle viste, come prescrive il PRD.

## Mitigazione progettuale del rischio "assenza di notifiche"

Il PRD registra come **rischio accettato n.1** l'assenza di notifiche (FR-025 fuori scope):
se il responsabile del turno non entra, il rilascio si ferma in silenzio. Due scelte di design
riducono il danno senza reintrodurre la feature:

1. **"I miei step" è la home post-login**, non una dashboard di grafici: chi entra vede subito
   se qualcosa lo aspetta.
2. **Blocco "Release in corso su cui sei coinvolto"** con *chi* sta trattenendo il flusso e *da quanto*
   (`index.html`), e badge di anzianità nell'elenco release (`desktop.html`, "da 2 giorni").
   Chiunque può quindi sollecitare, invece di scoprire il blocco a valle.

Non sostituiscono una notifica. Se dopo alcune settimane d'uso il flusso continua a fermarsi,
FR-025 va promosso.

## Asset di brand _(Elise)_

Il cliente non ha fornito asset. Identità minima creata qui:

| Asset | File | Note |
| --- | --- | --- |
| Favicon | `favicon.svg` | Tre gradini ascendenti + spunta di consegna. Inverte con `prefers-color-scheme` dentro l'SVG, quindi resta visibile su chrome chiaro e scuro. Destinazione: `public/favicon.svg`. |
| Logo | `logo.svg` | Marchio + wordmark in **`currentColor`**: eredita il colore del testo, quindi una sola variante copre tema chiaro e scuro — nessun `logo-dark.svg` necessario. |

**Non prodotti, con motivazione:**

- `og-default.png` (1200×630) — **non serve**: nessuna superficie pubblica, nessun link condiviso
  fuori dalla rete interna. Da produrre solo se lo strumento venisse esposto.
- `apple-touch-icon.png` (180×180) — opzionale; utile solo se qualcuno aggiunge l'app alla schermata
  home del telefono. Ritagliabile dal marchio in 5 minuti quando servisse.
- Immagine di brand coordinata — non giustificata su uno strumento interno senza pagine di marketing.

**Spazio di rispetto e dimensione minima:** il wordmark resta leggibile fino a 100 px di larghezza;
sotto, usare il solo marchio. Spazio libero attorno pari all'altezza del marchio.

## Note SEO _(Emma)_

Lo strumento **non ha superficie pubblica** e va deliberatamente tenuto fuori dagli indici.
Non si applica la struttura SEO ordinaria; si applicano queste tre cose:

- `public/robots.txt` con `User-agent: * / Disallow: /` — l'applicazione non deve essere indicizzata
  in nessun caso, nemmeno se raggiungibile per errore.
- **Nessun `sitemap.xml`, nessun `llms.txt`**: sarebbero un elenco di URL interni offerto a chiunque.
  È la scelta corretta qui, in deroga esplicita alla regola generale sui file obbligatori
  (che vale per i siti pubblici).
- `<title>` unici e parlanti su ogni pagina — non per i motori, ma per le schede del browser e la
  cronologia: `Portale Clienti v2.4.0 — Exin Deploy` è utile, `Dashboard` no.
- Meta `robots: noindex, nofollow` nel layout come seconda barriera.

**Target Lighthouse** (misurato in locale, non in produzione): Accessibilità **≥ 90**,
Prestazioni **≥ 80**. Con pagine di questa leggerezza e nessuna immagine raster sono obiettivi
larghi; se non vengono raggiunti, il problema è nel bundle, non nel contenuto.

## Note di condivisione _(Lauren)_

Nessuna. Prodotto interno non pubblico: niente campagne, niente canali social, nessuna immagine
di condivisione. Se il perimetro cambiasse — per esempio distribuendo lo strumento come prodotto —
servirebbero `og-default.png`, meta Open Graph e una pagina pubblica di presentazione: sarebbe
una nuova discovery, non un'aggiunta.

## Note regolamentari _(Violet)_

- **European Accessibility Act / EN 301 549** — riguardano prodotti e servizi offerti a
  consumatori e (per EN 301 549) forniture al settore pubblico. Uno strumento interno, non
  commercializzato e non esposto, **ricade normalmente fuori** dal loro perimetro.
- **Legge 4/2004 (Stanca)** — si applica a pubbliche amministrazioni e soggetti equiparati o
  contrattualizzati: **non applicabile** a questo strumento interno.
- **Dichiarazione di accessibilità** — **non dovuta**. Manteniamo comunque **WCAG 2.2 AA** come
  standard interno: è la scelta corretta a prescindere dall'obbligo, perché il pubblico dello
  strumento sono colleghi che lo useranno per anni.
- **Da verificare una volta**, non in questo mockup: se Gruppo Excellence ha obblighi di
  accessibilità derivanti da contratti con la PA che si estendono agli strumenti interni usati
  per erogare quei servizi. È una verifica contrattuale, non tecnica.
- **Dati personali** — la superficie mostra nome, ruolo e attività lavorativa di persone
  identificabili (chi ha chiuso cosa e quando). Coerente con il PRD: finalità di tracciabilità
  del processo da dichiarare al team, con politica di conservazione delle release concluse.
  Nessun dato di categoria particolare, nessuna profilazione.

## Cosa non è coperto

- **Superficie di configurazione**: progetti, ruoli, membri, template, editor degli step e dei campi
  (con riordino). È il candidato naturale a un secondo passaggio di design — l'editor del template
  è l'unica schermata davvero complessa che resta.
- **Login, reset password, 2FA**: seguono le viste Fortify nel layout auth del kit;
  riferimenti `html/login.html` e `html/auth-split.html` nel design system.
- **Registro delle transizioni** come vista dedicata (FR-016): nel mockup i valori dei campi
  compaiono nel dettaglio release, ma la vista cronologica degli eventi non è disegnata.
- **Stati di caricamento** dei componenti Livewire: da definire in implementazione con Joe
  (`wire:loading` sui bottoni di chiusura, per evitare doppi invii — che sono anche un requisito
  di correttezza, non solo di UX).
