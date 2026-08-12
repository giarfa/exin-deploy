---
paths:
  - app/Models/ProjectRoleAssignment.php
---

# Models

## Una persona puo ricoprire piu ruoli sullo stesso progetto: e voluto
Il vincolo unico e su `(project_id, role_id)`: per ogni coppia progetto/ruolo c'e al massimo una persona. NON esiste, e non va introdotto, un vincolo su `(project_id, user_id)`.

Vedendo la stessa persona su piu righe della pagina responsabili sembra un difetto: non lo e. Su un team di poche persone il cumulo di ruoli (Dev Lead + DevOps) e la norma, e vietarlo bloccherebbe l'uso reale. Confermato dall'utente in revisione di US-002 e scritto nel PRD (`ProjectRoleAssignment` — "unico per coppia progetto/ruolo"). Il seeder lo dimostra: Davide Rossi e QA e DevOps su `gestionale-magazzino`.

Cambiarlo e una modifica di perimetro: serve prima aggiornare il PRD.
