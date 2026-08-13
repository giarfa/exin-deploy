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
- **Template di workflow** (US-003, FR-006): il processo di rilascio diventa configurabile.
  Un amministratore crea, modifica e disattiva template riutilizzabili. Un solo template
  puo essere predefinito, e impostarne uno nuovo toglie il flag al precedente. I template
  non si cancellano: vi si appoggiano progetti e release.
- **Step ordinati** (US-003, FR-007): ogni template e una sequenza di passaggi, ciascuno con
  nome, istruzioni per chi lo esegue e ruolo responsabile. Gli step si riordinano con
  comandi "sposta su" e "sposta giu", raggiungibili da tastiera. Dopo un riordino o una
  cancellazione le posizioni restano contigue e senza buchi.
- **Campi richiesti** (US-003, FR-008): per ogni step si definisce cosa il responsabile
  dovra fornire per chiuderlo — etichetta, tipo fra quattro (testo breve, testo lungo,
  link, conferma), obbligatorieta, posizione e un testo di aiuto facoltativo. Un campo
  obbligatorio non compilato impedira di chiudere lo step; uno facoltativo no.
- **Un template senza step non e utilizzabile**, e il motivo e detto in chiaro: "disattivato"
  e "senza step" sono due situazioni diverse e si risolvono in modo diverso.
- **Il progetto adotta un processo** (US-003, chiusura di FR-004): alla creazione viene
  proposto il template predefinito, sostituibile subito e modificabile dopo. Cambiare il
  predefinito non tocca i progetti gia creati.
- **Ruoli previsti e non assegnati** (US-003, chiusura di FR-004): elenco progetti e pagina
  dei responsabili nominano i ruoli che il template richiede e che sul progetto non hanno
  un responsabile, spiegando la conseguenza — una release avviata cosi si bloccherebbe su
  quello step.
- La pagina dei responsabili segnala quando una persona ricopre piu ruoli sullo stesso
  progetto: e una scelta legittima su un team piccolo, e detta come tale non sembra piu un
  dato da correggere.
- **Avvio di una release** (US-004, FR-009): un amministratore avvia un rilascio su un
  progetto indicando un'etichetta — `v2.4.0`, `2026.08.1` — che deve essere unica su quel
  progetto. La release nasce "in corso", con l'autore e l'istante dell'avvio, e l'avvio
  finisce nel registro delle transizioni.
- **Il processo viene congelato all'avvio** (US-004, prima attivazione di FR-010): step e
  campi del template vengono copiati nella release. Da quel momento modificare, riordinare
  o disattivare il template non cambia i rilasci gia avviati, e nemmeno lo storico: cosa
  era stato richiesto in un rilascio passato resta leggibile com'era allora. Copia anche il
  **nome** del ruolo responsabile, cosi che rinominare un ruolo non riscriva il passato.
- **Ogni step sa gia chi ne risponde**: all'avvio il ruolo di ciascuno step viene risolto in
  una persona leggendo la mappatura del progetto. Cambiare la mappatura dopo non riassegna
  gli step delle release gia avviate.
- **Il primo step e attivo, gli altri attendono**: la catena nasce con un solo step su cui
  si puo lavorare, e tutti gli altri bloccati.
- **L'avvio e impedito quando mancano le condizioni**, e il motivo e detto in chiaro:
  progetto disattivato, processo non associato, template disattivato o senza step, ruoli
  senza responsabile — nominati — o responsabili disattivati — nominati anch'essi. Il
  motivo compare gia sull'elenco progetti, dove il comando di avvio resta visibile ma
  disabilitato: si scopre cosa manca prima di provare, non dopo.
- **Registro delle transizioni in sola aggiunta** (US-004, base di FR-016): una riga
  scritta non si modifica e non si cancella. E la condizione perche il registro valga come
  prova di cosa e successo durante un rilascio.
- **Chiusura di uno step con validazione** (US-005, FR-010, FR-011): il responsabile dello
  step attivo compila le informazioni congelate all'avvio e chiude il passaggio. Un campo
  obbligatorio vuoto, un indirizzo malformato o una conferma non spuntata rifiutano la
  chiusura, e il motivo dice cosa correggere: su un link il messaggio nomina i difetti
  trovati — "manca lo schema (https://) e contiene uno spazio" — invece di dire soltanto
  che non e valido. Un campo facoltativo lasciato vuoto non impedisce la chiusura, e resta
  distinguibile da uno compilato a vuoto.
- **Avanzamento sequenziale** (US-005, FR-010): alla chiusura valida vengono registrati
  valori, autore e istante; lo step passa a completato e il successivo diventa attivo, in
  **una sola transazione** insieme alle due righe del registro. Un doppio invio produce un
  solo avanzamento e un solo passaggio di consegne. Una release ha al massimo uno step
  attivo per volta, ed e verificato lungo l'intera catena.
- **La schermata dice a chi passa il flusso** alla chiusura: lo strumento non invia
  notifiche, quindi chi chiude sa chi avvisare. Uno step bloccato dice di chi si sta
  aspettando la chiusura; uno step completato mostra le informazioni fornite in sola
  lettura.
- **Salvataggio senza chiusura** (US-005): una bozza si salva anche incompleta, senza far
  avanzare la catena e senza scrivere nel registro — che documenta le transizioni, non i
  salvataggi. Un indirizzo malformato viene rifiutato anche in bozza: salvarlo
  significherebbe riproporlo rotto alla ripresa.
- **Solo il responsabile o un amministratore compilano e chiudono** (US-005, FR-012), con
  decisione a livello di Policy lato server. Il vincolo dello step attivo vale **anche** per
  un amministratore: non si compila un passaggio il cui turno non e arrivato o che e gia
  chiuso.
- **Conclusione della release** (US-006, FR-017): la chiusura dell'ultimo step della catena
  conclude il rilascio con autore e istante della consegna. Una release conclusa non ha
  alcuno step attivo, non compare fra quelle in corso ed e in sola lettura per chiunque,
  amministratori inclusi. La conclusione e registrata nel registro delle transizioni, e
  resta consultabile a tempo indeterminato. La riapertura di uno step resta fuori
  perimetro.
- **Diagrammi del modello e delle transizioni** nel README: entita-relazioni con definizione
  e istanza distinte, e macchina a stati di step e release annotata con gli eventi scritti
  nel registro.
- **Ambiente dimostrativo**: seeder con il team di esempio, incluso un membro disattivato
  per verificare il rifiuto dell'accesso, piu cinque ruoli funzionali, la mappatura
  predefinita e due progetti di cui uno con una sostituzione. Include ora il template
  "Rilascio standard" con cinque step e quattordici campi, e un secondo template
  disattivato.
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
  progetti, mappature e template. Le schermate accettano soltanto i ruoli e i template
  realmente in elenco: indicarne uno per identificativo non permette di aggirare il filtro.
- Uno step o un campo appartenenti a un altro template non sono raggiungibili cambiando
  identificativo nell'indirizzo (binding annidato) ne passandolo a un'azione.
- Un tipo di campo fuori dai quattro previsti e rifiutato lato server, non solo assente
  dal menu.
- **Tentativi non autorizzati sugli step tracciati due volte** (US-005, FR-012): un
  tentativo di compilare o chiudere senza esserne autorizzati e rifiutato con 403,
  registrato nel log applicativo — per chi presidia il sistema — e nel registro delle
  transizioni, che resta accanto al rilascio come prova di processo. Un accesso in sola
  lettura negato produce la voce di log e si ferma li: il registro non e cancellabile, e un
  ricaricamento di indirizzo lo gonfierebbe di righe che non dicono nulla sul rilascio.
- I valori compilati non possono essere scritti su campi che non appartengono allo step
  indicato: la chiusura itera sullo snapshot, non sulle chiavi ricevute.

### Changed

- SQLite configurato in modalita **WAL** con `busy_timeout`, per contenere il rischio di
  concorrenza in scrittura accettato nel PRD.
