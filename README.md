# Exin Deploy

Strumento interno per orchestrare il processo di rilascio in produzione su un team
distribuito. Ogni rilascio apre un'istanza di workflow: una catena ordinata di step,
ciascuno con un responsabile e una checklist di informazioni obbligatorie. Lo step si
chiude solo quando quelle informazioni esistono, e solo allora il flusso passa al
responsabile successivo.

Lo strumento **orchestra persone**: non esegue deploy, non tocca i server, non conserva
credenziali di terzi.

Contratto di prodotto: [`.larapilot/docs/PRD.md`](.larapilot/docs/PRD.md).
Backlog: [`.larapilot/backlog.yaml`](.larapilot/backlog.yaml).

## Prerequisiti

- PHP **8.4** con estensione sodium (necessaria per l'hashing Argon2id)
- Composer
- Node 20+ e npm
- [Laravel Herd](https://herd.laravel.com) per l'ambiente locale

## Avvio locale

Il progetto e servito da **Herd**: il sito e sempre raggiungibile su
`http://exin-deploy.test`, non serve avviare alcun server a mano.

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate

touch database/database.sqlite
php artisan migrate:fresh --seed

npm run dev        # oppure: npm run build
```

Il seeder crea il team dimostrativo. Tutti gli account condividono la password
`Rilascio-2026!`; l'amministratore e `f.giarola@gruppoexcellence.com`. Un membro
(`p.venturi@gruppoexcellence.com`) e volutamente **disattivato**, per poter verificare
il rifiuto dell'accesso.

Semina inoltre la configurazione di processo: cinque ruoli funzionali, la mappatura
predefinita del team e due progetti. Uno dei due (`gestionale-magazzino`) ha una
sostituzione rispetto alla predefinita, cosi che la differenza fra le due mappature sia
visibile senza doverla ricreare a mano.

E semina il processo di rilascio: il template **"Rilascio standard"**, attivo e
predefinito, con cinque step ordinati (Preparazione del codice, Verifica funzionale,
Valutazione di sicurezza, Preparazione dell'ambiente, Consegna in produzione) e quattordici
campi richiesti che coprono tutti e quattro i tipi previsti. Entrambi i progetti lo usano.
Un secondo template, **"Rilascio urgente"**, e disattivato: serve a vedere come si comporta
l'elenco e come si sostituisce il processo su un progetto.

La mappatura dimostrativa copre tutti i ruoli previsti dal template, quindi lo stato
iniziale e quello sano. Per vedere la segnalazione dei **ruoli scoperti**, rimuovi un
responsabile dalla pagina dei responsabili di un progetto.

Lo scenario di **esecuzione** — release in corso, release conclusa — arriva con US-011.

Non ci sono istruzioni Sail o Docker: l'ambiente locale scelto nel PRD e Herd.

## Variabili d'ambiente rilevanti

| Variabile | Valore | Perche |
| --- | --- | --- |
| `HASH_DRIVER` | `argon2id` | Baseline di sicurezza del progetto: mai bcrypt su un progetto nuovo |
| `DB_CONNECTION` | `sqlite` | Scelta del PRD, con il rischio di concorrenza in scrittura accettato |
| `DB_JOURNAL_MODE` | `WAL` | Consente letture concorrenti durante una scrittura |
| `DB_BUSY_TIMEOUT` | `5000` | Attende invece di fallire subito quando il file e bloccato |
| `DB_SYNCHRONOUS` | `NORMAL` | Compromesso fra durabilita e velocita adeguato a WAL |
| `APP_LOCALE` | `it` | Lingua unica del prodotto |
| `APP_FALLBACK_LOCALE` | `en` | Una stringa di framework non tradotta rende in inglese, non come chiave grezza |
| `MAIL_MAILER` | `log` | In locale le email di recupero password finiscono in `storage/logs` |

## Comandi

```bash
php artisan test --compact                 # suite completa
php artisan test --compact --filter=Auth   # sottoinsieme

php artisan larapilot:quality              # Pint + Larastan livello 5
php artisan larapilot:quality --fix        # applica la formattazione

php artisan migrate:fresh --seed           # ricrea il database dimostrativo
```

Prima di ogni commit: `vendor/bin/pint --dirty` e Larastan a livello 5. Il livello
non va abbassato senza una deroga umana registrata nel PRD o nel piano.

## Architettura

### Definizione e istanza sono separate

E la scelta portante del sistema, e la parte che chi arriva fraintende.

**Definizione** — la configurazione riusabile: ruoli funzionali, progetti, mappature
ruolo → persona, template di workflow con i loro step e i campi richiesti.

**Istanza** — un rilascio concreto: la release, i suoi step, i valori forniti, il
registro degli eventi.

Le due non condividono tabelle. All'avvio di una release, step e campi del template
vengono **copiati** nell'istanza (snapshot congelato). Da quel momento:

> il codice di esecuzione di una release legge **soltanto** il proprio snapshot,
> mai le tabelle di definizione.

Senza questa regola, modificare un template mentre tre release sono in corso ne
cambierebbe la forma sotto i piedi, e lo storico dei rilasci diventerebbe inattendibile:
non si potrebbe piu sapere cosa fosse stato effettivamente richiesto in un rilascio
passato. Le release concluse sono la prova documentata che il processo e stato rispettato,
e una prova che cambia retroattivamente non e una prova.

Da US-004 le due meta esistono entrambe, e la separazione non e piu una promessa ma uno
schema. Le entita di istanza:

| Entita | Contenuto | Regole |
| --- | --- | --- |
| `Release` | rilascio avviato su un progetto | etichetta univoca **per progetto** a schema; stati `in_corso` e `conclusa`; mai cancellabile |
| `ReleaseStep` | copia congelata di uno step del template | unicita `(release, posizione)` a schema; nome del ruolo congelato accanto alla chiave esterna; al massimo **uno** attivo per release |
| `ReleaseStepField` | copia congelata di un campo richiesto, e il valore fornito | unicita `(step, posizione)` a schema; una sola colonna `value` per tutti e quattro i tipi |
| `ReleaseEvent` | registro delle transizioni | **sola aggiunta**: una riga scritta non si modifica e non si cancella |

Cosa e congelato e cosa no:

| Congelato all'avvio | Riferimento vivo |
| --- | --- |
| forma della catena: numero, ordine, nomi e istruzioni degli step | identita delle **persone** (`assigned_user_id`) |
| etichetta, tipo, obbligatorieta e testo di aiuto di ogni campo | identita del **progetto** |
| **nome** del ruolo responsabile (`role_name`) | — |

La distinzione ha una ragione: rinominare un ruolo o riordinare un template non deve
riscrivere il passato, mentre una persona che cambia cognome deve comparire nello storico
con il proprio nome attuale — e la stessa persona, non un nome fossile.

La regola operativa e verificata da un test che ascolta le query eseguite mentre si legge
una release: se ne compare una su `workflow_templates`, `step_definitions`,
`field_definitions` o `project_role_assignments`, il test fallisce. Un altro test cancella
gli step di definizione e verifica che la release resti leggibile per intero.

Regole correlate, applicate dal codice: la chiusura di uno step, l'attivazione del
successivo e la scrittura dell'evento avvengono in **una sola transazione**, e una release
ha al massimo **uno** step attivo per volta.

I diagrammi (entita-relazioni e macchina a stati) arrivano con US-005, quando modello e
transizioni sono completi.

### Avvio di una release

Un amministratore avvia una release da un progetto, indicando un'etichetta
(`v2.4.0`, `2026.08.1`, ...). L'avvio passa da **un solo percorso**,
`App\Actions\Releases\StartRelease`, e avviene in **una sola transazione**.

Le precondizioni sono verificate in quest'ordine, tutte **prima** di qualsiasi scrittura:

1. il progetto e attivo — un progetto disattivato non accoglie nuove release;
2. il progetto ha un template, e il template e utilizzabile (`isUsable()`: attivo e con
   almeno uno step). Il motivo del rifiuto distingue "disattivato" da "senza step";
3. ogni ruolo previsto dagli step ha un responsabile sul progetto — i ruoli scoperti sono
   **nominati** nel rifiuto;
4. nessuno dei responsabili risolti e disattivato — anche qui, nominati.

Poi, nella stessa transazione: la release nasce `in_corso` con autore e istante, step e
campi vengono copiati in **due sole scritture di massa**, il primo step risulta `attivo` e
gli altri `bloccato`, e il registro riceve l'evento `release_avviata`. Il costo in query
non dipende dalla lunghezza della catena, ed e vincolato da un test.

L'ordine dei rifiuti non e casuale: si vede il secondo problema solo dopo aver risolto il
primo, quindi elencarli tutti insieme non aiuterebbe.

`Project::startBlocker()` anticipa lo stesso giudizio dove serve mostrarlo — la schermata di
avvio e l'elenco progetti, che disabilita il comando con il motivo accanto. Anticipa, non
sostituisce: l'Action decide comunque sul dato fresco, e il suo rifiuto viene reso come
messaggio e mai come errore tecnico.

**Il doppio invio non produce due release.** Non serve un lock pessimistico: qui non c'e
uno stato preesistente da leggere e riscrivere, e l'unicita `(project_id, label)` a livello
di schema fa fallire la seconda transazione, che non lascia nulla dietro di se. Il lock
richiesto da `.ai/rules/app.md` riguarda l'**avanzamento**, dove invece lo stato c'e.

### Schermate

| Rotta | Pagina | Accesso |
| --- | --- | --- |
| `/` | I miei step — segnaposto fino a US-007 | ogni membro |
| `/impostazioni/sicurezza` | Verifica in due passaggi | ogni membro |
| `/membri` | Membri del team | amministratore |
| `/ruoli` | Ruoli funzionali | amministratore |
| `/progetti` | Progetti, con il comando di avvio release per riga | amministratore |
| `/progetti/{progetto}/responsabili` | Mappatura ruolo → persona del progetto | amministratore |
| `/progetti/{progetto}/rilascio` | **Avvio di una release** | amministratore |
| `/template` · `/template/{t}/step` · `/template/{t}/step/{s}/campi` | Processo di rilascio | amministratore |
| `/responsabili-predefiniti` | Mappatura predefinita di team | amministratore |

La voce **Release** nella navigazione resta marcata "in arrivo": la pagina che promette e
l'**elenco** delle release (US-009), non la schermata di avvio, che si raggiunge dal
progetto su cui si rilascia.

### Configurazione del processo

Le entita di definizione presenti oggi:

| Entita | Contenuto | Regole |
| --- | --- | --- |
| `Role` | ruolo funzionale (Dev Lead, QA, DevOps, ...) | nome univoco; non cancellabile se referenziato, sempre disattivabile |
| `Project` | progetto su cui si rilascia | slug univoco a livello di schema; mai cancellabile, solo disattivabile |
| `DefaultRoleAssignment` | ruolo → persona predefinita del team | una sola persona per ruolo |
| `ProjectRoleAssignment` | ruolo → persona su un progetto | una sola persona per coppia progetto/ruolo |
| `WorkflowTemplate` | processo di rilascio riutilizzabile | nome univoco; **un solo predefinito**; mai cancellabile, solo disattivabile |
| `StepDefinition` | passaggio ordinato del processo, con ruolo responsabile e istruzioni | posizioni **contigue e senza duplicati**, unicita `(template, posizione)` a schema |
| `FieldDefinition` | informazione richiesta per chiudere uno step | **quattro tipi** (testo breve, testo lungo, link, conferma); unicita `(step, posizione)` a schema |

**Il template nomina ruoli, non persone.** E cio che rende lo stesso processo utilizzabile
su progetti con team diversi: la persona si ottiene all'avvio della release, risolvendo il
ruolo di ogni step sulla mappatura del progetto. Un ruolo previsto dal template e senza
responsabile sul progetto e segnalato in elenco progetti e nella pagina dei responsabili,
perche una release avviata cosi si bloccherebbe su quello step.

**Un template senza step non e utilizzabile.** `WorkflowTemplate::isUsable()` e
`unusableReason()` distinguono "disattivato" da "senza step": sono due situazioni che si
risolvono in modo diverso, e un messaggio unico costringerebbe a indovinare. E lo stesso
metodo che `StartRelease` invoca come precondizione dell'avvio: la regola e scritta una
volta sola.

**Il template predefinito e una proposta, non un legame.** Alla creazione di un progetto
viene proposto come valore iniziale e resta sostituibile, in creazione e in modifica —
stessa semantica della mappatura predefinita di team. Cambiare il predefinito non tocca i
progetti gia creati.

**Il riordino e affidato a un concern condiviso.** `App\Models\Concerns\OrderedByPosition`,
usato da step e campi, garantisce che dopo ogni spostamento o cancellazione le posizioni
restino `1..N`. La rinumerazione avviene in due passaggi dentro una sola transazione,
passando da posizioni **temporanee negative**: senza quel passaggio intermedio l'indice
unico rifiuterebbe la scrittura a meta strada. Per questo `position` e un `integer` con
segno. Ogni cancellazione passa da `deleteAndResequence()`: la contiguita non e
responsabilita di chi chiama.

**La mappatura predefinita non e retroattiva.** Alla creazione di un progetto,
`App\Actions\Projects\CreateProject` copia la mappatura predefinita sul progetto, dentro una
sola transazione. Da quel momento le due mappature sono indipendenti: modificare quella del
progetto non tocca la predefinita, e modificare la predefinita non tocca i progetti gia
creati. E il punto che si fraintende piu spesso, ed e dichiarato anche nell'interfaccia.

Non vengono copiate le predefinite il cui ruolo o la cui persona risultano disattivati: la
schermata riporta quanti ruoli sono rimasti scoperti, perche una release avviata con ruoli
scoperti si bloccherebbe.

La precompilazione e una **Action esplicita e non un observer** sul modello: con un observer
ogni `Project::factory()->create()` di test o di seeder erediterebbe mappature a sorpresa.

**Chi ricopre un ruolo deve poter accedere.** `App\Rules\AssignableUser`, condivisa dalle due
schermate di mappatura, rifiuta le persone disattivate. Una persona assegnata e disattivata in
seguito **non** viene rimossa — cancellare una traccia sarebbe peggio — ma e segnalata nella
riga, con icona e parola.

### Autenticazione e autorizzazione

- **Laravel Fortify** per accesso, recupero password e verifica in due passaggi TOTP.
- **Nessuna registrazione pubblica**: gli account nascono solo da un amministratore, e
  `/register` risponde 404.
- **Passkey disattivate.** `laravel/passkeys` arriva come dipendenza di Fortify, ma sono
  fuori dal perimetro del PRD: attivarle aprirebbe una superficie di autenticazione che
  nessun requisito ha specificato.
- I membri **non vengono cancellati**: si disattivano. La loro traccia sui rilasci passati
  deve restare leggibile nel registro.
- L'autorizzazione vive nelle **Policy lato server**. Nascondere un comando
  nell'interfaccia non e autorizzazione: le azioni Livewire non ripassano dal middleware
  della rotta e hanno il proprio controllo.

### Vincoli permanenti

1. **Portabilita del database.** Solo Eloquent e migrazioni portabili, nessuna funzione
   specifica di SQLite: la migrazione a PostgreSQL o MySQL deve restare un cambio di
   configurazione.
2. **Solo componenti Flux gratuiti.** Flux Pro richiede una licenza a pagamento che non e
   stata acquistata: non introdurre componenti Pro senza una decisione esplicita.
3. **Nessuna indicizzazione.** `robots.txt` con `Disallow: /` e meta `noindex` nel layout.
   Nessun `sitemap.xml` ne `llms.txt`: su uno strumento interno sarebbero un elenco di URL
   offerto a chiunque.
4. **Soglia responsive unica a 1024 px.** Sopra, sidebar permanente; sotto, drawer. E la
   soglia `max-lg:` gestita da Flux: non introdurne una seconda.
5. **Un solo template predefinito, garantito dal codice e non dallo schema.**
   L'invariante vive in `App\Actions\Workflows\SetDefaultWorkflowTemplate`, unico percorso
   di scrittura del flag. Un indice unico parziale non e portabile fra SQLite, MySQL e
   PostgreSQL con le migrazioni di Laravel (vincolo 1), e la variante con colonna nullable
   renderebbe `is_default` ambigua — `null` invece di `false` — proprio dove il codice la
   legge come booleana. Contropartite: transazione, percorso unico e un test che verifica
   l'assenza di due predefiniti dopo una sequenza di operazioni. Se il flag venisse scritto
   da un secondo percorso, l'indice parziale va rivalutato.
6. **`field_definitions.type` e una `string` con cast a enum, non un `enum` di schema.**
   Un vincolo di check costringerebbe SQLite a ricostruire la tabella al primo tipo
   aggiunto. Il rifiuto di un valore fuori dai quattro casi resta pieno: `Rule::enum` in
   scrittura, `ValueError` del cast Eloquent in lettura, entrambi coperti da test. Vale
   identico per `releases.status`, `release_steps.status` e `release_events.action`.
7. **`release_steps.role_name` e congelato accanto a `role_id`.** L'esecuzione legge
   `role_name`; la chiave esterna serve solo a rendere il ruolo non cancellabile
   (`restrict`, piu `Role::REFERENCING_RELATIONS`). Chi aggiunge una relazione a
   quella costante deve aggiungerla anche ai `withCount()` degli elenchi che chiamano
   `Role::usageLabel()` per riga, altrimenti il conteggio mancante diventa un N+1.
8. **Le posizioni dello snapshot non si riordinano.** `ReleaseStep` e `ReleaseStepField`
   **non** usano `OrderedByPosition`, e per questo la loro `position` e
   `unsignedInteger` e non `integer` con segno: non servono posizioni temporanee
   negative. Introdurre il trait aprirebbe un percorso di scrittura sull'ordine congelato,
   cioe sull'unica cosa che quelle tabelle esistono per proteggere.
9. **Una sola colonna `value` per tutti e quattro i tipi di campo.** Il valore fornito e
   sempre testo; la semantica per tipo — un link deve essere un indirizzo valido, una
   conferma obbligatoria deve risultare spuntata — appartiene alla chiusura dello step
   (US-005). Quattro colonne tipizzate ne lascerebbero tre sempre nulle su ogni riga.
10. **`release_events` non ha `updated_at`, ed e voluto.** Il registro e in sola aggiunta:
    `update()` e `delete()` sollevano `ReleaseEventIsAppendOnly` dal modello, e lo schema
    non offre nemmeno la colonna che dichiarerebbe possibile la modifica. Un registro
    correggibile a posteriori non e una prova.
11. **`releases.completed_by` e `completed_at` nascono vuote.** Sono create da US-004 e
    riempite da US-006, quando la chiusura dell'ultimo step conclude la release:
    aggiungerle dopo sarebbe una seconda migrazione sulla stessa tabella per una semantica
    gia decisa dal PRD. Per lo stesso motivo `ReleaseEventAction` nasce con tutti e cinque
    i casi di FR-016 anche se questa spec ne scrive uno solo — quei valori finiscono in
    colonna e sopravvivono nello storico, quindi rinominarli dopo sarebbe una migrazione
    di dati evitabile.

## Stack

| Componente | Versione |
| --- | --- |
| Laravel | 13.x |
| PHP | 8.4 |
| Livewire | 4.x (componenti a file singolo in `resources/views/components`) |
| Flux UI | 2.x, solo componenti gratuiti |
| Fortify | 1.x |
| Tailwind CSS | 4.x con Vite 8 |
| Test | PHPUnit 12.x (non Pest) |
| Analisi statica | Larastan livello 5 |

## Regole di progetto

Le decisioni non ovvie e le trappole gia incontrate sono registrate in
[`.ai/rules/`](.ai/rules/): leggerle prima di modificare il codice fa risparmiare le ore
che sono costate a chi le ha scritte.
