---
paths:
  - 'app/Actions/Releases/**'
---

# Releases

## Il compare-and-swap sulla chiusura step non e ridondante col lock
`CloseStep` usa **sia** `lockForUpdate()` sulla riga della release **sia** un `update()` condizionato a `status = attivo`. Non togliere il secondo: su SQLite `lockForUpdate()` non produce SQL (la grammatica non supporta `FOR UPDATE`), quindi la garanzia effettiva contro il doppio avanzamento e l'update condizionato. Il lock serve su MySQL/PostgreSQL, dove il vincolo di portabilita impone che il codice resti corretto.

Le scritture dei valori restano una query per campo: `upsert` richiederebbe un `ON CONFLICT` sulla chiave primaria di una tabella che ha anche l'unicita `(release_step_id, position)`, e SQLite non garantisce quale vincolo valuta per primo.
