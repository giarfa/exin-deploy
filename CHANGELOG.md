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
- **Vista operativa "i miei step"** (US-007, FR-013): la schermata di ingresso non e piu un
  segnaposto, ed e deliberatamente una lista di lavoro e non una dashboard di grafici.
  Elenca gli step attivi di cui chi entra e responsabile, su tutti i progetti, con progetto,
  etichetta della release, nome dello step, posizione sul totale, ruolo congelato e da
  quanto e aperto. Il filtro e sull'assegnazione e non sull'autorizzazione: nemmeno un
  amministratore vede qui gli step altrui. Quando nulla lo attende, lo stato vuoto dice
  **quando** comparira uno step, non solo che non ce ne sono.
- **Blocco "Release in corso su cui sei coinvolto"** (US-007): dice chi trattiene il flusso,
  su quale step e da quanto tempo. E la mitigazione del rischio accettato n.1 del PRD —
  l'assenza di notifiche (FR-025, fuori perimetro) — e non un abbellimento: senza, un
  rilascio fermo resta invisibile finche qualcuno non lo cerca.
- **Dettaglio di una release** (US-008, FR-014): si apre un rilascio e si vede l'intera
  catena nell'ordine congelato all'avvio, con lo stato di ogni step, il ruolo e il nome del
  responsabile, e le informazioni fornite su ciascuno step chiuso — con autore e istante
  della chiusura. Gli step ancora bloccati non mostrano nulla di cio che verra chiesto:
  dicono soltanto cosa li sblocca. Accanto alla catena, i dati del rilascio: progetto,
  etichetta, stato, template di origine, chi lo ha avviato e quando, step completati sul
  totale.
- **Il dettaglio e consultabile da ogni membro autenticato** (US-008), anche da chi non e
  responsabile di alcuno step di quella release: su uno strumento che non invia notifiche,
  sapere dove un rilascio e fermo e chi si sta aspettando non e un privilegio. Resta una
  pagina di sola lettura — compilare e chiudere restano riservati al responsabile dello step
  attivo o a un amministratore — e la catena mostrata e sempre lo **snapshot congelato**, non
  il template di adesso.
- **Il dettaglio si raggiunge da dove serve**: dal blocco "release in corso su cui sei
  coinvolto", che pone la domanda a cui questa pagina risponde; dalla schermata di uno step,
  in tutti e tre i suoi stati; e dalla conferma di avvio di una release.
- **Elenco e storico delle release** (US-009, FR-015): la voce "Release" della navigazione
  porta ora a una pagina. Due sezioni con colonne diverse perche rispondono a domande
  diverse: quelle in corso dicono a che punto e la catena e chi trattiene il flusso, quelle
  concluse chi ha consegnato, quando e in quanto tempo. Filtri per stato e per progetto, che
  vivono nell'indirizzo e sopravvivono a una ricarica: un elenco filtrato e condivisibile.
  Lo storico non ha limite di data ne paginazione — cresce di qualche riga a settimana e il
  costo di lettura non dipende dal numero di righe.
- **L'elenco e consultabile da ogni membro autenticato** (US-009): `ReleasePolicy::viewAny()`
  passa da negata ad aperta, come gia `view()`. La decisione era rinviata di proposito alla
  spec che porta la schermata — un'autorizzazione senza una pagina che la applichi non si sa
  valutare. Avviare una release resta degli amministratori; cancellarne una resta di nessuno.
- **Le briciole del dettaglio tornano conformi al mockup**: la prima voce e ora l'elenco
  delle release e non piu "i miei step", ripiego dichiarato di US-008 quando l'elenco non
  esisteva ancora.
- **Registro delle transizioni consultabile** (US-010, FR-016): dal dettaglio di una release
  si apre la cronologia completa di cio che e successo — avvio, chiusura di ogni step,
  passaggio del flusso al responsabile successivo, conclusione — con l'attore e l'istante di
  ciascuna voce. L'ordine e crescente, dall'inizio del rilascio in poi: e un racconto di come
  e andata, non una lista di cose da fare.
- **I tentativi non autorizzati sono riservati agli amministratori** (US-010): la riga nomina
  una persona e cosa ha provato a fare, ed e materiale di sicurezza e non di processo. Chi
  non e amministratore non la vede e non ne vede traccia — nessun conteggio di voci nascoste.
- **L'immutabilita del registro e ora verificata sull'intera applicazione** (US-010): oltre al
  rifiuto del modello, i test pretendono che nessuna rotta delle superfici di rilascio accetti
  un metodo di scrittura, che nessuna rotta risolva una voce dall'indirizzo, che nessun
  comando Artisan la tocchi e che la schermata non esponga metodi pubblici oltre a quelli di
  lettura — in Livewire un metodo pubblico e un'azione invocabile dal browser. `update` e
  `delete` sono negate anche a un amministratore da `ReleaseEventPolicy`.

- **Diagrammi del modello e delle transizioni** nel README: entita-relazioni con definizione
  e istanza distinte, e macchina a stati di step e release annotata con gli eventi scritti
  nel registro.
- **Ambiente dimostrativo**: seeder con il team di esempio, incluso un membro disattivato
  per verificare il rifiuto dell'accesso, piu cinque ruoli funzionali, la mappatura
  predefinita e due progetti di cui uno con una sostituzione. Include ora il template
  "Rilascio standard" con cinque step e quattordici campi, e un secondo template
  disattivato.
- **Scenario di esecuzione dimostrativo** (US-011, FR-018): `migrate:fresh --seed` produce
  ora anche tre rilasci — uno consegnato con la catena tutta chiusa e il registro completo,
  uno a meta catena con valori realistici sul primo step, e uno appena avviato e fermo sul
  primo. Ogni schermata operativa ha quindi qualcosa da mostrare senza dover ricostruire uno
  scenario a mano. I rilasci sono prodotti chiamando le Action reali e non scrivendo righe:
  il registro delle transizioni che ne risulta e quello che il processo produce davvero.
- **Responsabile diverso dal default per singola release** (US-013, FR-009): in fase di
  creazione di una release il responsabile di ciascun ruolo del processo e sovrascrivibile
  per quel solo rilascio, senza toccare la mappatura del progetto ne i default di team. Un
  ruolo scoperto o con responsabile disattivato si sblocca da qui, indicando chi ne risponde
  per questa release: il comando di avvio nell'elenco progetti resta quindi raggiungibile in
  quei due casi, e il modulo obbliga a fornire la sostituzione prima dell'invio. Il pool
  selezionabile e lo stesso della mappatura di progetto, e un override verso una persona
  disattivata viene rifiutato con lo stesso messaggio di sempre.
- Documentazione baseline: README con avvio via Herd, nota architetturale sulla separazione
  fra definizione e istanza, questo changelog.

### Removed

- Il componente di navigazione `x-nav.planned` e la sua chiave di traduzione: nessuna
  sezione della sidebar e piu marcata "in arrivo", e un componente senza chiamanti e codice
  morto.

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

### Fixed

- **Filtri di stato dell'elenco dei rilasci** (US-012): "In corso" e "Conclusa" non
  restringevano l'elenco e non comparivano nell'indirizzo. L'espressione del comando
  raggiungeva il browser non compilata, che la rifiutava in console.
- **Comando di scambio nella sfida in due passaggi** (US-012): il collegamento che passa
  dal codice dell'app al codice di recupero era reso senza testo, per la stessa causa.
- L'attributo `x-cloak` era decorativo: mancava la regola di stile che lo rende efficace, e
  i blocchi alternati della sfida comparivano tutti insieme prima dell'avvio di Alpine.
