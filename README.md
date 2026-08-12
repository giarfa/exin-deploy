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
visibile senza doverla ricreare a mano. Lo scenario di esecuzione — template, release in
corso, release conclusa — arriva con US-011.

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

Regole correlate, applicate dal codice: la chiusura di uno step, l'attivazione del
successivo e la scrittura dell'evento avvengono in **una sola transazione**, e una release
ha al massimo **uno** step attivo per volta.

I diagrammi (entita-relazioni e macchina a stati) arrivano con US-005, quando modello e
transizioni sono completi.

### Configurazione del processo

Le entita di definizione presenti oggi:

| Entita | Contenuto | Regole |
| --- | --- | --- |
| `Role` | ruolo funzionale (Dev Lead, QA, DevOps, ...) | nome univoco; non cancellabile se referenziato, sempre disattivabile |
| `Project` | progetto su cui si rilascia | slug univoco a livello di schema; mai cancellabile, solo disattivabile |
| `DefaultRoleAssignment` | ruolo → persona predefinita del team | una sola persona per ruolo |
| `ProjectRoleAssignment` | ruolo → persona su un progetto | una sola persona per coppia progetto/ruolo |

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
