---
paths:
  - 'app/**'
---

# App

## Le release leggono solo il proprio snapshot, mai le tabelle di definizione
All'avvio di una release, step e campi del WorkflowTemplate vengono COPIATI in ReleaseStep / ReleaseStepField. Il codice di esecuzione (chiusura step, avanzamento, viste operative) deve leggere esclusivamente lo snapshot: se legge StepDefinition/FieldDefinition, modificare un template altera le release già in corso e lo storico dei rilasci diventa inattendibile.

Chiusura step + attivazione dello step successivo + scrittura in ReleaseEvent stanno in UNA sola transazione, con lock pessimistico sulla riga della release: un doppio invio concorrente non deve produrre due avanzamenti. Invariante: al massimo un ReleaseStep in stato attivo per release; zero quando la release è conclusa.
