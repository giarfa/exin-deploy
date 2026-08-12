# Changelog

Tutte le modifiche rilevanti di questo progetto sono documentate qui.

Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.1.0/) e il
versionamento segue [Semantic Versioning](https://semver.org/lang/it/).

## [Unreleased]

### Added

- **Accesso allo strumento** (US-001, FR-001): autenticazione via Laravel Fortify con
  recupero password e verifica in due passaggi TOTP, schermate proprie in Livewire e Flux.
- **Gestione dei membri del team**: un amministratore crea, modifica e disattiva i membri.
  I membri non vengono mai cancellati, per non perdere la loro traccia nello storico dei
  rilasci.
- **Livelli applicativi** `admin` e `member` come enum nativo, con autorizzazione
  applicata dalle Policy lato server.
- **Shell applicativa** secondo lo Starter Kit variante Livewire: sidebar permanente da
  1024 px e drawer sotto, tema chiaro/scuro/sistema, skip link e navigazione accessibile.
  Le sezioni non ancora implementate sono visibili e marcate "in arrivo".
- **Ruoli funzionali** (US-002, FR-002): catalogo dei ruoli di processo, condiviso fra i
  progetti. Un ruolo referenziato non e cancellabile — il rifiuto dice cosa lo referenzia —
  ma resta disattivabile.
- **Progetti** (US-002, FR-003): anagrafica con identificativo leggibile univoco, vincolato
  a livello di schema. I progetti non si cancellano: contengono lo storico dei rilasci.
- **Mappatura ruolo → persona per progetto** (US-002, FR-004): una sola persona per coppia
  progetto/ruolo. Un ruolo creato dopo il progetto compare da solo come non assegnato.
- **Mappatura predefinita di team** (US-002, FR-005): precompilata sui progetti alla loro
  creazione, in una sola transazione. Non e retroattiva, e le due mappature restano
  indipendenti. Le predefinite con ruolo o persona disattivati non vengono copiate, e i
  ruoli rimasti scoperti sono riportati all'utente.
- **Ambiente dimostrativo**: seeder con il team di esempio, incluso un membro disattivato
  per verificare il rifiuto dell'accesso, piu cinque ruoli funzionali, la mappatura
  predefinita e due progetti di cui uno con una sostituzione.
- Documentazione baseline: README con avvio via Herd, nota architetturale sulla separazione
  fra definizione e istanza, questo changelog.

### Security

- Hashing **Argon2id** al posto di bcrypt.
- Regole password globali via `Password::defaults()`: minimo 8 caratteri, maiuscole e
  minuscole, numeri, simboli, e verifica contro violazioni di dati note.
- **Chiavi primarie UUID** (UUIDv7) su tutti i modelli.
- **Nessuna registrazione pubblica**: `/register` risponde 404.
- **Passkey disattivate**, pur presenti come dipendenza di Fortify: fuori dal perimetro
  del prodotto.
- Un membro disattivato non accede piu, con messaggio dedicato distinto da quello delle
  credenziali errate. Tentativi falliti e accessi rifiutati vengono registrati nel log.
- `robots.txt` con `Disallow: /` e meta `noindex, nofollow`: lo strumento non deve essere
  indicizzato in nessun caso.
- Configurazione del processo riservata agli amministratori, con Policy dedicate su ruoli,
  progetti e mappature. Le schermate di mappatura accettano soltanto i ruoli realmente in
  elenco: indicare un ruolo per identificativo non permette di aggirare il filtro.

### Changed

- SQLite configurato in modalita **WAL** con `busy_timeout`, per contenere il rischio di
  concorrenza in scrittura accettato nel PRD.
