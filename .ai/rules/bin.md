---
paths:
  - 'bin/**'
---

# Bin

## Il tempo di enumerazione si ancora al commit della tabella, non alla punta di --base
Il check anti-shrink di `bin/decisioni.php` deve distinguere "file presente quando la tabella e stata scritta" da "file creato dall'implementazione". Non usare l'albero alla punta di `--base` come tempo di enumerazione: regge solo finche si sta sul ramo di feature e scade nell'istante del merge, quando la base assorbe l'implementazione e ogni file creato dalla spec diventa un finding `re-enumeration` su una decisione in realta ratificata (accaduto su US-013 subito dopo il merge, con CI rossa su develop).

L'ancora corretta e l'ultimo commit che ha toccato **la tabella stessa** (`git log -1 --format=%H -- <tabella>`): l'immutabilita di ramo garantisce che la tabella non si sia mossa dall'enumerazione, quindi quel commit *e* il tempo di enumerazione, e resta tale dopo il merge. Tabella non committata = nessuna ancora: astenersi e dichiararlo in `notes`, non leggere un albero di ripiego.

Regressione coperta da `tests/Feature/DecisionGateTest.php`, che costruisce un repo git usa-e-getta perche "dopo il merge" richiede di controllare la storia.
