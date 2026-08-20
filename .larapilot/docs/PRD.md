# Product Requirements Document

**Author:** Larapilot
**Date:** 2026-08-12

## Elevator Pitch

Uno strumento interno che trasforma il rilascio in produzione da conversazione sparsa tra chat, email e memoria personale in un processo tracciato e verificabile. Ogni rilascio di un progetto apre un'istanza di workflow: una catena ordinata di step, ciascuno con un responsabile identificato per ruolo e una checklist di informazioni obbligatorie (testo, link, conferme). Lo step si chiude solo quando quelle informazioni esistono davvero, e solo allora il flusso passa al responsabile successivo. Il rilascio si considera concluso quando l'ultimo step — la consegna effettiva in produzione — viene marcato completo, lasciando dietro di sé uno storico completo di chi ha fatto cosa, quando e con quali dati.

## Vision

Su un team distribuito il rilascio non fallisce per incompetenza tecnica: fallisce perché nessuno sa con certezza a chi tocca adesso, cosa manca, e se quel passaggio è stato realmente eseguito o solo dato per fatto. Le checklist su documenti condivisi decadono, le conferme su chat si perdono, e la conoscenza del processo resta nella testa di una o due persone.

La visione è rendere il processo di rilascio un **oggetto di prima classe**: configurabile una volta come template, riutilizzabile su tutti i progetti, esplicito su responsabilità e prerequisiti, e con una traccia storica non riscrivibile. Lo strumento non esegue il deploy e non tocca i server: orchestra le persone. Ogni release conclusa diventa la prova documentata che il processo è stato rispettato, e la base di dati per capire dove il processo si blocca.

L'orizzonte oltre l'MVP è duplice: rendere il flusso proattivo (notificare il responsabile quando è il suo turno, invece di aspettare che guardi) e leggibile in aggregato (dove si perde tempo, quali step sono cronicamente lenti).

## User Personas

### Release Owner (amministratore del processo)

- **Ruolo:** possiede il processo di rilascio; configura progetti, ruoli funzionali, membri del team e template di workflow; avvia le release e ne monitora l'avanzamento.
- **Obiettivi:** definire una volta il processo corretto e riapplicarlo a ogni progetto senza riscriverlo; sapere in ogni momento a che punto è ogni rilascio in corso e chi lo sta trattenendo; disporre di uno storico affidabile a rilascio concluso.
- **Pain Points:** oggi il processo vive in un documento che nessuno aggiorna; deve inseguire personalmente le persone per capire se un passaggio è stato fatto; a posteriori non riesce a ricostruire cosa era stato verificato in un rilascio passato.

### Step Owner (membro del team distribuito)

- **Ruolo:** ricopre un ruolo funzionale nel processo (Dev Lead, QA, DevOps, PM, Security…) ed è responsabile di uno o più step su uno o più progetti.
- **Obiettivi:** vedere in un colpo d'occhio se c'è qualcosa che aspetta lui; capire senza chiedere cosa deve fornire esattamente per chiudere il proprio step; consegnare le informazioni una volta sola, nel posto giusto.
- **Pain Points:** scopre in ritardo che il flusso era fermo su di lui; non sa quali informazioni sono attese e in quale formato; ripete le stesse informazioni su canali diversi a persone diverse.

### Osservatore del rilascio (stakeholder interno)

- **Ruolo:** project manager o referente che non compila step ma deve conoscere lo stato di un rilascio.
- **Obiettivi:** rispondere a "dove siamo?" senza interrompere il team; vedere lo storico di un rilascio concluso.
- **Pain Points:** ogni aggiornamento di stato costa una domanda diretta e un'interruzione a qualcuno.

## Functional Requirements

### FR-001: Autenticazione e anagrafica dei membri del team

**MoSCoW:** Must

Accesso allo strumento riservato ai membri del team autenticati. Ogni membro ha nome, email, stato attivo/disattivo e un livello applicativo (`admin` oppure `member`). Gli `admin` configurano il sistema e possono intervenire su qualsiasi release; i `member` operano sugli step di cui sono responsabili. La disattivazione di un membro ne impedisce l'accesso senza cancellarne la traccia storica sulle release passate.

### FR-002: Catalogo dei ruoli funzionali

**MoSCoW:** Must

Gestione di un catalogo di ruoli funzionali di processo (es. Dev Lead, QA, DevOps, PM, Security), condiviso a livello di team e indipendente dai singoli progetti. I ruoli sono l'unità con cui i template di workflow esprimono la responsabilità di uno step, così che lo stesso template funzioni su progetti con persone diverse. Un ruolo referenziato da template o release non è cancellabile: è disattivabile.

### FR-003: Progetti

**MoSCoW:** Must

Gestione dei progetti su cui il team rilascia: nome, identificativo leggibile, descrizione, stato attivo/disattivo e template di workflow associato. Ogni progetto è il contenitore delle proprie release e del proprio storico.

### FR-004: Assegnazione ruolo → persona per progetto

**MoSCoW:** Must

Per ogni progetto si definisce quale membro del team ricopre ciascun ruolo funzionale utilizzato dal template associato. Questa mappatura è ciò che trasforma un template astratto in un processo con responsabili reali. Il sistema segnala i ruoli previsti dal template ma non ancora assegnati sul progetto, perché una release avviata con ruoli scoperti si bloccherebbe.

### FR-005: Assegnazione ruolo → persona predefinita a livello di team

**MoSCoW:** Must

Poiché sui diversi progetti sono coinvolte di norma le stesse persone, esiste una mappatura predefinita ruolo → persona valida a livello di team. Alla creazione di un nuovo progetto la mappatura predefinita viene precompilata sul progetto, dove resta modificabile senza alterare il valore predefinito. Questo elimina la riconfigurazione manuale a ogni nuovo progetto, che è il costo che rende inutilizzabili gli strumenti di questo tipo.

### FR-006: Template di workflow configurabili

**MoSCoW:** Must

Gestione di template di workflow riutilizzabili: nome, descrizione, stato attivo/disattivo e flag di template predefinito. Un template è una sequenza **ordinata** di step; l'ordine è modificabile in configurazione. Poiché la maggior parte dei progetti condivide lo stesso processo, il template predefinito è quello proposto in automatico ai nuovi progetti, mentre progetti con esigenze particolari possono usarne uno diverso.

### FR-007: Definizione degli step di un template

**MoSCoW:** Must

Ogni step di un template ha: posizione nella sequenza, nome, descrizione o istruzioni per chi lo esegue, e il **ruolo funzionale responsabile**. Le istruzioni sono il punto in cui il processo diventa autoesplicativo per chi lo eredita.

### FR-008: Definizione dei campi informativi richiesti per step

**MoSCoW:** Must

Ogni step definisce l'elenco dei campi informativi che il responsabile deve fornire per chiuderlo. Ogni campo ha etichetta, tipo, obbligatorietà, posizione e testo di aiuto opzionale. Tipi supportati nell'MVP:

- **testo breve** — valori sintetici (numero di versione, nome ambiente, identificativo ticket)
- **testo lungo** — note, esiti di verifica, motivazioni
- **link** — URL validato (pipeline, changelog, report di test, ticket)
- **conferma** — flag booleano di presa in carico o verifica eseguita

Un campo obbligatorio non compilato impedisce la chiusura dello step; un campo opzionale non la impedisce.

### FR-009: Avvio di una release come istanza di workflow

**MoSCoW:** Must

Da un progetto si avvia una nuova release indicando un'etichetta identificativa (es. versione o data). All'avvio il sistema:

1. **congela uno snapshot** della definizione del workflow — step, ordine, campi richiesti — dentro la release;
2. **risolve ruolo → persona** secondo la mappatura del progetto, memorizzando il responsabile effettivo su ciascuno step;
3. attiva il primo step della catena.

Lo snapshot è un requisito, non un dettaglio implementativo: modifiche successive al template non devono alterare la forma delle release già in corso, altrimenti lo storico dei rilasci diventa inattendibile e non è più possibile sapere cosa fosse stato effettivamente richiesto in un rilascio passato.

In fase di creazione, per ciascun ruolo previsto dal processo l'amministratore può indicare un responsabile diverso da quello risolto dalla mappatura di progetto, sovrascrivendo di fatto — solo per questa release — l'assegnazione di default. L'override è un effetto one-shot: non modifica la mappatura del progetto (`ProjectRoleAssignment`) né i default di team (`DefaultRoleAssignment`), e segue lo stesso responsabile risolto dallo snapshot per il resto del ciclo di vita della release.

### FR-010: Avanzamento sequenziale con un solo step attivo

**MoSCoW:** Must

Una release ha in ogni momento **esattamente uno** step attivo. Gli step successivi restano bloccati e non compilabili; quelli precedenti sono completati e in sola lettura. La chiusura dello step attivo attiva automaticamente il successivo; la chiusura dell'ultimo step conclude la release.

### FR-011: Chiusura di uno step con validazione dei campi obbligatori

**MoSCoW:** Must

Il responsabile dello step attivo compila i campi e ne richiede la chiusura. Il sistema rifiuta la chiusura se un campo obbligatorio è vuoto o non valido (URL malformato su campo link, conferma non spuntata), mostrando il motivo. Alla chiusura registra valori, autore e istante.

### FR-012: Autorizzazione sulla chiusura degli step

**MoSCoW:** Must

Uno step può essere compilato e chiuso solo dal membro assegnato come responsabile, oppure da un `admin`. Il controllo è applicato a livello di autorizzazione lato server e non solo nascondendo comandi nell'interfaccia. Un tentativo non autorizzato è rifiutato e tracciato.

### FR-013: Vista operativa "i miei step"

**MoSCoW:** Must

Ogni membro autenticato dispone di una vista che elenca, su tutti i progetti, gli step attualmente attivi di cui è responsabile, con progetto, release e nome dello step. È la schermata di ingresso quotidiana e determina se lo strumento viene usato o abbandonato: deve essere immediatamente leggibile anche da telefono.

### FR-014: Vista di dettaglio della release

**MoSCoW:** Must

Una vista mostra la release nella sua interezza: etichetta, progetto, stato, sequenza completa degli step con stato di ciascuno (completato / attivo / bloccato), responsabile, valori già forniti sugli step chiusi, e istanti di completamento. È la risposta strutturale alla domanda "dove siamo e chi stiamo aspettando".

### FR-015: Elenco e storico delle release

**MoSCoW:** Must

Elenco delle release filtrabile per progetto e per stato (in corso / concluse), con accesso al dettaglio storico completo delle release concluse. Lo storico è consultabile a tempo indeterminato.

### FR-016: Registro immutabile delle transizioni

**MoSCoW:** Must

Ogni evento rilevante di una release — avvio, chiusura di uno step, attivazione dello step successivo, conclusione, tentativo di accesso non autorizzato — è registrato in un log in sola aggiunta, con attore, istante e riferimento allo step. Il registro non è modificabile né cancellabile dall'interfaccia. Su un processo di rilascio la tracciabilità è la funzione stessa del prodotto, non un extra di conformità.

### FR-017: Conclusione della release come consegna in produzione

**MoSCoW:** Must

La chiusura dell'ultimo step segna la release come conclusa, con istante e autore della consegna. Una release conclusa non è più modificabile e resta consultabile in sola lettura. Il ciclo di vita è quindi: apertura al rilascio → catena di step → consegna effettiva in produzione.

### FR-018: Dati dimostrativi coerenti

**MoSCoW:** Must

Il sistema è corredato di factory e seeder che producono un ambiente dimostrativo coerente: membri del team con ruoli, almeno due progetti, un template di workflow realistico di rilascio, una release in corso a metà catena e una release conclusa. Serve allo sviluppo, ai test e a valutare l'usabilità reale delle schermate senza tabelle vuote.

### FR-019: Riapertura o correzione di uno step chiuso da parte di un admin

**MoSCoW:** Should

Un `admin` può riaprire uno step già chiuso, o correggerne i valori, quando sono state inserite informazioni errate. L'operazione è integralmente tracciata nel registro (valore precedente, valore nuovo, autore, motivazione) e riporta la release allo step riaperto. Senza questa funzione un errore di battitura obbliga ad annullare e ricreare la release; con essa serve una traccia esplicita perché è un'operazione di scavalcamento del processo. Rinviata alle fasi successive dato il target MVP.

### FR-020: Annullamento di una release

**MoSCoW:** Should

Un `admin` può annullare una release non conclusa (rilascio abortito, ripianificato o sostituito), indicando una motivazione. La release resta nello storico con stato annullato e non riappare tra quelle in corso. Rinviata alle fasi successive dato il target MVP.

### FR-021: Commenti sugli step

**MoSCoW:** Should

Possibilità di aggiungere commenti a uno step, per scambiare chiarimenti nel contesto invece che su canali esterni. Rinviata alle fasi successive dato il target MVP.

### FR-022: Visibilità della versione di template usata da una release

**MoSCoW:** Should

Nel dettaglio della release è indicato quale template e quale versione dello snapshot sono stati usati, e se il template è successivamente cambiato. Utile per confrontare rilasci eseguiti con processi diversi. Rinviata alle fasi successive dato il target MVP.

### FR-023: Duplicazione di un template

**MoSCoW:** Could

Duplicazione di un template esistente come base per una variante, per non ricostruire da zero un processo simile.

### FR-024: Metriche di processo

**MoSCoW:** Could

Cruscotto con durata media per step, tempo totale per release e individuazione degli step cronicamente lenti — la base quantitativa per migliorare il processo invece di intuirlo.

### FR-025: Notifica automatica al responsabile del turno

**MoSCoW:** Won't

Alla chiusura di uno step, notifica automatica (email e/o canale di team) al responsabile dello step successivo. Esclusa da questo rilascio per scelta esplicita: nell'MVP il turno si scopre consultando la vista "i miei step". È il primo candidato per il rilascio seguente — vedi il rischio annotato in `### Rischi accettati`.

### FR-026: Step paralleli o grafo di dipendenze

**MoSCoW:** Won't

Tappe con step in parallelo o dipendenze arbitrarie tra step (DAG). Esclusa da questo rilascio: il modello adottato è la catena strettamente sequenziale.

### FR-027: Esecuzione o innesco automatico di deploy

**MoSCoW:** Won't

Innesco di pipeline CI/CD, chiamata di webhook o esecuzione di comandi sui server. Esclusa da questo rilascio: lo strumento orchestra persone e non tocca infrastruttura, il che mantiene la superficie di sicurezza minima (nessuna credenziale di terzi, nessun accesso ai server).

### FR-028: Multi-team e isolamento tra organizzazioni

**MoSCoW:** Won't

Supporto a più team o clienti isolati sulla stessa installazione. Esclusa da questo rilascio: l'installazione serve un singolo team interno.

## MVP Scope

**Project Kind:** Personal
**Delivery Target:** MVP
**Deadlines:** nessuna scadenza o milestone fissa dichiarata

### In Scope

Corrispondono ai requisiti **Must** (FR-001 → FR-018):

- Autenticazione dei membri del team, con distinzione tra `admin` e `member`, e anagrafica disattivabile
- Catalogo dei ruoli funzionali di processo, condiviso tra i progetti
- Gestione dei progetti con template di workflow associato
- Mappatura ruolo → persona per progetto, con mappatura predefinita a livello di team precompilata sui nuovi progetti
- Configurazione di template di workflow: sequenza ordinata di step, ruolo responsabile per step, istruzioni
- Configurazione dei campi informativi per step nei quattro tipi previsti (testo breve, testo lungo, link, conferma), con obbligatorietà
- Avvio di una release con snapshot congelato della definizione e risoluzione ruolo → persona
- Avanzamento strettamente sequenziale con un solo step attivo, validazione dei campi obbligatori alla chiusura e attivazione automatica dello step successivo
- Autorizzazione lato server sulla chiusura degli step (responsabile o `admin`)
- Vista operativa "i miei step" cross-progetto, usabile da telefono
- Vista di dettaglio della release con l'intera catena e i valori forniti
- Elenco e storico delle release, filtrabili per progetto e stato
- Registro immutabile delle transizioni
- Conclusione della release come consegna effettiva in produzione, in sola lettura
- Factory e seeder con dataset dimostrativo coerente

### Out of Scope

Corrispondono ai requisiti **Won't** (FR-025 → FR-028), esclusi da questo rilascio con motivazione:

- **Notifiche automatiche al cambio di step** (FR-025) — esclusa per scelta esplicita dell'utente in fase di discovery; nell'MVP il turno si scopre entrando in applicazione. Rischio accettato annotato sotto.
- **Step paralleli o grafo di dipendenze** (FR-026) — il modello scelto è la catena strettamente sequenziale; introdurre parallelismo cambierebbe modello dati e interfaccia di configurazione.
- **Esecuzione o innesco automatico di deploy** (FR-027) — lo strumento orchestra persone; nessuna credenziale, nessun accesso a server o pipeline.
- **Multi-team / isolamento tra organizzazioni** (FR-028) — installazione a singolo team; nessuna multi-tenancy.

### Future Phases

Requisiti **Should** e **Could** rinviati, non cancellati:

1. **Notifica al responsabile del turno** (FR-025) — pur classificata Won't per questo rilascio, è il primo candidato del successivo: è ciò che trasforma il flusso da passivo a proattivo.
2. **Riapertura e correzione di step chiusi da parte di un admin** (FR-019) — con tracciamento completo di valore precedente, nuovo, autore e motivazione.
3. **Annullamento di una release** (FR-020) — con motivazione e conservazione nello storico.
4. **Commenti sugli step** (FR-021) — chiarimenti nel contesto invece che su canali esterni.
5. **Visibilità della versione di template usata dalla release** (FR-022).
6. **Duplicazione di un template** (FR-023).
7. **Metriche di processo** (FR-024) — durata per step, tempo totale, individuazione dei colli di bottiglia.
8. **Evoluzioni oltre il perimetro attuale**, da riaprire solo con una nuova discovery: step paralleli, innesco di pipeline, multi-team.
9. **Manutenzione e supporto** — canale di segnalazione dei bug e triage tramite `/larapilot-bug`; nessun impegno formale di SLA su uno strumento interno.

### Rischi accettati

Decisioni prese consapevolmente dall'utente contro l'indicazione del team, registrate qui per non essere riscoperte in seguito:

1. **Assenza di notifiche (FR-025).** Su un team distribuito, con catena strettamente sequenziale, la chiusura di uno step non avvisa nessuno: se il responsabile successivo non entra in applicazione, il rilascio resta fermo in silenzio e il ritardo si scopre a valle. Mitigazione nell'MVP: la vista "i miei step" è la schermata di ingresso e il dettaglio release rende evidente su chi è fermo il flusso.
2. **SQLite come database.** Adeguato a un team di poche persone, ma la scrittura concorrente sullo stesso file comporta blocchi in scrittura: due responsabili che chiudono step nello stesso istante possono incontrare un errore transitorio. Mitigazione: modalità WAL, timeout di attesa configurato, transazioni brevi. La migrazione a PostgreSQL o MySQL resta possibile ed è tanto meno costosa quanto più il codice resta su Eloquent puro e migrazioni portabili — vincolo da rispettare in implementazione.
3. **Catena strettamente sequenziale.** Un responsabile lento blocca l'intero rilascio anche quando altri step sarebbero eseguibili in parallelo. Accettato per semplicità di modello e interfaccia; l'introduzione delle tappe parallele richiederà una evolutiva sul modello dati.

## Technical Architecture

**Budget Sensitivity:** Relaxed
**Frontend Topology:** Laravel-coupled
**Frontend stack (in-repo):** Livewire 4 + Flux (componenti free) su Tailwind 4 e Vite 8
**External frontend repo:** N/A
**Admin panel:** Starter Kit (livewire)

### Stack

- **Laravel 13.24** su **PHP 8.4**, verificato con Boost `Application Info` sul repo esistente
- **Interfaccia:** approccio Laravel Starter Kit variante **Livewire**, come scelto in discovery. Nota operativa: gli Starter Kit ufficiali si applicano alla creazione del progetto (`laravel new`), mentre questo repo esiste già con Larapilot installato; la variante Livewire va quindi ottenuta installando **`livewire/livewire` ^4.4** e **`livewire/flux` ^2.16** e costruendo layout, navigazione e schermate di autenticazione allineati alle convenzioni dello starter kit. Compatibilità con Laravel 13.24 verificata in fase di discovery (dry-run pulito, nessun advisory). **Flux** è usato nella sola parte gratuita; l'eventuale adozione di componenti Flux Pro comporta una licenza a pagamento da valutare separatamente
- **Autenticazione:** **`laravel/fortify` ^1.38** (compatibilità verificata) per login, reset password e 2FA TOTP, con schermate proprie in Livewire. Nessun accesso pubblico né registrazione libera: gli account sono creati da un `admin`
- **Difese di base** secondo la baseline di sicurezza (**Security baseline** in `runtime-delivery.md`): `Password::defaults()` registrato in `AppServiceProvider::boot()` (minimo 8, maiuscole/minuscole, numeri, simboli, `uncompromised`), **chiavi primarie UUID** su tutti i nuovi modelli, **hashing Argon2id**, 2FA TOTP via Fortify. **Socialite/SSO non in scope**: nessun provider esterno di identità su uno strumento interno a singolo team
- **Superficie API:** nessuna. Coerente con la profondità architetturale prevista per il target **MVP** (fetta verticale sottile) e con la topologia Laravel-coupled; nessun `openapi.yaml` da mantenere
- **Nessuna coda necessaria nell'MVP:** senza notifiche, senza innesco di pipeline e senza import, non esiste I/O lento da spostare fuori dalla richiesta HTTP. Il driver di coda resta `sync`; l'introduzione delle notifiche (FR-025) porterà con sé una coda `database` e un worker
- **Politica pacchetti** secondo **Vendor & Package Policy** (`runtime-delivery.md`): built-in e first-party Laravel per primi, poi catalogo Spatie, e solo dopo altri vendor. Nessuna necessità individuata di pacchetti di terze parti oltre a Livewire, Flux e Fortify

### Modello dati

**Data store:** SQLite (scelta dell'utente, rischio di concorrenza in scrittura accettato e annotato in `### Rischi accettati`)
**Hierarchy:** nessuna gerarchia — gli step sono una lista ordinata per posizione, non un albero
**Search:** nessun motore di ricerca — filtri SQL su progetto e stato sono sufficienti ai volumi previsti
**CLI tooling:** solo comandi Artisan applicativi; nessuna CLI esterna in Bash o Go, nessuna automazione Git o script di server nel perimetro MVP

La separazione portante è tra **definizione** (configurazione riusabile) e **istanza** (rilascio in corso o concluso). Le due non condividono tabelle: è ciò che rende lo snapshot di FR-009 realizzabile senza ambiguità.

**Definizione (configurazione):**

| Entità | Contenuto | Note |
| --- | --- | --- |
| `User` | membro del team: nome, email, livello `admin`/`member`, attivo | PK UUID; mai cancellato fisicamente, disattivato |
| `Role` | ruolo funzionale di processo: nome, descrizione, attivo | non cancellabile se referenziato |
| `Project` | progetto: nome, slug, descrizione, attivo, template associato | contenitore delle release |
| `ProjectRoleAssignment` | ruolo → persona su un progetto | unico per coppia progetto/ruolo |
| `DefaultRoleAssignment` | ruolo → persona predefinita a livello di team | sorgente di precompilazione per FR-005 |
| `WorkflowTemplate` | template: nome, descrizione, attivo, predefinito | un solo predefinito attivo per volta |
| `StepDefinition` | step del template: posizione, nome, istruzioni, ruolo responsabile | ordinato per posizione, riordinabile |
| `FieldDefinition` | campo richiesto da uno step: etichetta, tipo, obbligatorio, posizione, aiuto | tipo come enum PHP nativo |

**Istanza (rilascio):**

| Entità | Contenuto | Note |
| --- | --- | --- |
| `Release` | progetto, etichetta, stato, riferimento al template di origine, autore e istante di avvio, istante di conclusione | uno stato tra `in_corso` e `conclusa` nell'MVP |
| `ReleaseStep` | copia dello step: posizione, nome, istruzioni, ruolo, **responsabile risolto**, stato, autore e istante di chiusura | stato tra `bloccato`, `attivo`, `completato` |
| `ReleaseStepField` | copia del campo: etichetta, tipo, obbligatorio, posizione, **valore fornito** | il valore vive qui, non nella definizione |
| `ReleaseEvent` | registro in sola aggiunta: release, step, attore, azione, dati, istante | nessun update né delete esposti |

**Invarianti da garantire in implementazione:**

1. Una release ha **al massimo uno** `ReleaseStep` in stato `attivo`; una release conclusa non ne ha alcuno.
2. La chiusura di uno step e l'attivazione del successivo avvengono in **una sola transazione**, insieme alla scrittura dell'evento nel registro: uno stato intermedio persistito sarebbe un rilascio senza step attivo e senza responsabile.
3. La transizione è **idempotente rispetto al doppio invio**: due richieste concorrenti di chiusura dello stesso step non devono produrre due avanzamenti né due eventi. Con SQLite serve un blocco pessimistico sulla riga della release o un controllo di stato dentro la transazione.
4. Le entità di definizione **non vengono lette in fase di esecuzione** di una release: gli step in corso leggono solo il proprio snapshot.
5. **Eager loading obbligatorio** su ogni vista che carica catene di step, campi e responsabili (`with`) — le viste "i miei step", dettaglio release ed elenco release sono tutte percorsi con relazioni annidate e sono i candidati naturali a un N+1. Indici su chiavi esterne e sulle colonne di filtro (`release.project_id`, `release.status`, `release_step.status`, `release_step.assigned_user_id`).
6. **Portabilità del database:** solo Eloquent e migrazioni portabili, nessuna funzione specifica di SQLite, così che la migrazione a PostgreSQL o MySQL resti un cambio di configurazione.
7. **SQLite in modalità WAL** con `busy_timeout` configurato, per contenere il rischio di concorrenza annotato tra i rischi accettati.

### UX & frontend

- **Due superfici distinte, non da confondere.** La **configurazione** (progetti, ruoli, membri, template, step, campi) è CRUD densa e a uso rado, riservata agli `admin`. La **superficie operativa** ("i miei step", dettaglio release, chiusura di uno step) è quotidiana, usata da tutti, e determina l'adozione dello strumento.
- **Mobile First** secondo il contratto responsive in `runtime-ux.md`: la superficie operativa è progettata a partire da 375 px — un responsabile deve poter chiudere il proprio step dal telefono. La configurazione può assumere schermi larghi.
- **Stato leggibile a colpo d'occhio:** la catena degli step comunica completato / attivo / bloccato senza affidarsi al solo colore, per non escludere chi non distingue i colori.
- **Accessibilità WCAG 2.2 AA** come bar di riferimento: contrasto, ordine di focus, etichette esplicite sui campi, messaggi di errore associati programmaticamente al campo che li ha generati. Tema chiaro e scuro entrambi previsti.
- **Asset di brand:** non forniti dal cliente. Trattandosi di strumento interno non pubblico, il set minimo è `favicon.svg` e un lockup testuale; nessuna immagine OG necessaria in assenza di pagine pubbliche condivisibili.
- **Nessun mockup obbligatorio prima dell'implementazione**, ma la superficie operativa è la candidata naturale a un passaggio in `/larapilot-design` prima di scrivere codice, se si vuole validare la leggibilità dello stato.

### Internationalization

Mercato singolo, team interno italofono. **Lingua unica: italiano**, senza infrastruttura multi-locale. Il testo dell'interfaccia passa comunque dai file `lang/` invece di essere incastonato nelle viste, così che un eventuale secondo locale sia una aggiunta e non un refactoring. **Fusi orari:** istanti memorizzati in UTC e mostrati nel fuso dell'applicazione — rilevante su team distribuito, dove "completato alle 18:40" deve significare la stessa cosa per tutti.

### Privacy & compliance

I dati personali trattati sono limitati e a base contrattuale/legittimo interesse in contesto di lavoro: nome, email aziendale e traccia delle azioni compiute nel processo di rilascio. Non ci sono utenti esterni, dati di categoria particolare, profilazione, marketing né cookie di terze parti; non serve un banner cookie oltre alla sessione tecnica.

Due punti restano rilevanti nonostante il perimetro ridotto: il **registro delle transizioni** documenta l'attività lavorativa di persone identificabili, quindi la sua finalità (tracciabilità del processo di rilascio) va dichiarata al team ed è opportuna una **politica di conservazione** esplicita per le release concluse; la **disattivazione** di un membro non cancella la sua traccia storica, ed è la scelta corretta per l'integrità del registro, ma va comunicata. Nessuna informativa pubblica né dichiarazione di accessibilità sono dovute in assenza di superficie pubblica.

### Sicurezza

- **Superficie ridotta per costruzione:** nessuna credenziale di terzi, nessun accesso a server o pipeline, nessuna API pubblica, nessun caricamento di file nell'MVP. Il perimetro di attacco è l'autenticazione e l'autorizzazione sugli step.
- **Autorizzazione a livello di Policy** su ogni azione che muta lo stato di una release, mai solo nascondendo comandi nell'interfaccia: chiusura step, riapertura, configurazione. Il rischio concreto è l'accesso diretto per identificativo a una release o a uno step di cui non si è responsabili.
- **Validazione all'ingresso** con Form Request o equivalente Livewire su tutti i campi, inclusa la validazione degli URL sui campi di tipo link e la protezione da assegnazione massiva.
- **Log applicativo** dei fallimenti di autenticazione e dei tentativi di chiusura non autorizzati, distinti dal registro funzionale delle transizioni.
- **File di disclosure** (`SECURITY.md`, `public/.well-known/security.txt`) previsti da `runtime-delivery.md`: non applicabili come gate di rilascio su un'applicazione non raggiungibile pubblicamente. Da riconsiderare se lo strumento venisse esposto su Internet.
- **Rassegna red-team** (Oliver) rinviata alla fase di rilascio secondo le regole del progetto **Personal**; il focus sarà l'aggiramento dell'autorizzazione sugli step.
- `composer audit` nella pipeline come gate minimo di supply chain.

### Development & delivery

- **Git mode:** `GITFLOW` — un branch `feature/US-XXX-*` per spec da `develop`, un commit atomico per task in formato Conventional Commits, descrizione di PR preparata localmente. **Nessun push automatico**: il push e l'apertura della PR remota avvengono solo su richiesta esplicita.
- **Testing:** `NORMAL` — test feature/unit su percorsi critici, Policy e validazione, con PHPUnit (il repo usa PHPUnit 12.5, non Pest). **Nessun test browser o E2E** a questo livello. I percorsi che meritano copertura in ogni caso: catena di avanzamento sequenziale, rifiuto della chiusura con campi obbligatori mancanti, autorizzazione dello step, integrità dello snapshot alla modifica del template a release aperta, doppio invio concorrente della chiusura.
- **Qualità:** Pint e Larastan a livello 5 via `php artisan larapilot:quality`. **Nota operativa:** `larastan/larastan` è dichiarato in `require-dev` di `composer.json` ma non risulta installato in `vendor/` — serve un `composer install` prima che il gate di qualità sia eseguibile.
- **Factory e seeder** aggiornati nello stesso commit di ogni modifica a migrazioni, modelli o enum, secondo **Test Data — Factories & Seeders** in `runtime-delivery.md`.
- **Versioning:** SemVer e `CHANGELOG.md` in formato Keep a Changelog, con tag `vX.Y.Z` sui rilasci.
- **CI/CD:** pipeline GitHub Actions con gli stage minimi previsti da `runtime-delivery.md` — Pint, Larastan livello 5, `php artisan test`, `composer audit`, build Vite. Il repo ha già una cartella `.github/`, da verificare in fase di implementazione.
- **Effort:** `STANDARD`. **Auto-approve:** disattivato — il passaggio a `DONE` richiede approvazione umana via `/larapilot-review`.

### Documentazione

Set baseline secondo **Technical Documentation** in `runtime-delivery.md`: `README.md` con prerequisiti, avvio locale via Herd, variabili d'ambiente, comandi di test e procedura di seed; una nota di panoramica architetturale che spieghi la separazione definizione/istanza e la regola dello snapshot, perché è la parte del sistema che un nuovo arrivato fraintende; disciplina di `CHANGELOG.md`. **Nessun OpenAPI** in assenza di API. Documentazione estesa (diagrammi, runbook, manuali PDF) non prevista: da richiedere esplicitamente per singola spec se servisse.

### Infrastruttura e rilascio

- **Local dev:** **Laravel Herd** — PHP e Composer gestiti da Herd, sito su dominio `.test` senza avviare server a mano. Nessuno scaffolding Sail o Docker da aggiungere.
- **Deploy target:** **server interno aziendale** (Excellence Innovation), raggiungibile dalla rete interna o via VPN, non esposto su Internet. È la scelta coerente con uno strumento di team non pubblico e con l'assenza di dati di clienti.
- **Edge / CDN / WAF:** non affrontato in discovery per una scelta deliberata — su un'applicazione non pubblica non porta valore. Il tema si riapre solo se il perimetro cambia; verrà valutato in `/larapilot-ship`.
- **Osservabilità:** nessuna piattaforma esterna. Log applicativo su file con `laravel/pail` già presente per l'ispezione in sviluppo. Se in esercizio servisse visibilità sugli errori, **Laravel Pulse** è la prima opzione da valutare per il rapporto costo/beneficio su installazione interna.
- **Backup:** punto operativo non banale con SQLite — il database è un file singolo, quindi il backup è una copia pianificata di quel file (con snapshot coerente in modalità WAL) verso una destinazione fuori dal server. Da definire in `/larapilot-ship`; è l'unico vero rischio operativo dello strumento, perché lo storico dei rilasci è il valore che accumula nel tempo.
- **Cloud/compute:** nessun provider cloud coinvolto, per scelta del target di deploy.

### Componenti principali

1. **Autenticazione e anagrafica** — Fortify con 2FA, gestione dei membri e del livello applicativo, disattivazione
2. **Configurazione di processo** — ruoli, progetti, mappature ruolo→persona (di progetto e predefinita), template, step, campi
3. **Motore della macchina a stati** — Action dedicate per `AvviaRelease`, `ChiudiStep`, `AvanzaAlProssimoStep`, con transazione unica e scrittura del registro; è il cuore del prodotto e va tenuto fuori da controller e componenti Livewire
4. **Superficie operativa** — "i miei step", dettaglio release, form di chiusura con validazione
5. **Storico e registro** — elenco release, dettaglio in sola lettura, registro delle transizioni in sola aggiunta
6. **Autorizzazione** — Policy su release e step, con distinzione responsabile / `admin`

### Prestazioni e scalabilità

I volumi sono strutturalmente contenuti: un team, alcuni progetti, alcuni rilasci al mese, catene di pochi step. Nessuna cache applicativa, nessuna coda, nessun motore di ricerca necessari nell'MVP. I due punti di attenzione reali non sono di volume ma di correttezza: **N+1** sulle viste con catene annidate (mitigato dall'eager loading obbligatorio già prescritto) e **concorrenza in scrittura su SQLite** (mitigata da WAL, `busy_timeout` e transazioni brevi). Il limite superiore dello strumento non è il carico ma il modello: la catena sequenziale, non la tecnologia.

Sul fronte costi, con Budget Sensitivity **Relaxed** non è stato aperto un round di budget. L'unica nota di ordine di grandezza: l'infrastruttura è un server interno già esistente e non introduce spesa ricorrente; le due spese potenzialmente future sono una licenza **Flux Pro** (solo se si adottano componenti a pagamento, evitabile) e nulla sul fronte SaaS.

## PRD Revision History

| Date | Trigger | Summary |
| --- | --- | --- |
| 2026-08-12 | larapilot-inception | PRD iniziale: orchestratore del processo di rilascio per team distribuito. Project Kind Personal, target MVP, catena sequenziale stretta, responsabili per ruolo risolti sul progetto, solo tracciamento. Stack Laravel 13 + Livewire 4 + Flux + Fortify su SQLite, dev locale Herd, deploy su server interno. Notifiche, step paralleli, automazione del deploy e multi-team esclusi da questo rilascio. |
| 2026-08-20 | larapilot-feature US-013 | Chiarito FR-009: in fase di creazione di una release, l'amministratore può sovrascrivere per ruolo il responsabile risolto dalla mappatura di progetto, come effetto one-shot sulla singola release (nessuna scrittura sui default). MoSCoW invariato (Must). |
