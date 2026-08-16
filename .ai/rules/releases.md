---
paths:
  - 'app/Actions/Releases/**'
---

# Releases

## Il compare-and-swap sulla chiusura step non e ridondante col lock
`CloseStep` usa **sia** `lockForUpdate()` sulla riga della release **sia** update condizionati: `status = attivo` per chiudere lo step e `status = in_corso` per concludere la release sul ramo terminale. Non togliere i secondi: su SQLite `lockForUpdate()` non produce SQL (la grammatica non supporta `FOR UPDATE`), quindi la garanzia effettiva contro il doppio avanzamento e la doppia conclusione sono gli update condizionati. Il lock serve su MySQL/PostgreSQL, dove il vincolo di portabilita impone che il codice resti corretto.

La conclusione della release resta un metodo **privato** di `CloseStep` e non va estratta in una Action pubblica: sarebbe un secondo percorso di scrittura sullo stato della release, contro l'invariante "chiusura, avanzamento o conclusione ed eventi in una sola transazione".

Le scritture dei valori restano una query per campo: `upsert` richiederebbe un `ON CONFLICT` sulla chiave primaria di una tabella che ha anche l'unicita `(release_step_id, position)`, e SQLite non garantisce quale vincolo valuta per primo.
