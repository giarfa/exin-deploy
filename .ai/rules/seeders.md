---
paths:
  - 'database/seeders/**'
---

# Seeders

## Il seeder dichiara la configurazione ma delega i rilasci alle Action
Due modi di seminare nello stesso file, e non e un'incoerenza da uniformare.

**Configurazione** (membri, ruoli, template, progetti, mappature) = uno **stato**: si scrive con dati espliciti, senza passare dalle Action dell'interfaccia. Dichiarare lo stato finale e piu chiaro che ricostruirlo, e i commenti esistenti lo dicono.

**Rilasci** (`seedReleases()`) = un **processo**: lo stato *e* la sequenza di transizioni che lo ha prodotto, quindi si chiamano `StartRelease` e `CloseStep`. Scriverli a mano vorrebbe dire replicare qui snapshot, risoluzione dei responsabili, invariante dello step attivo unico, valori congelati e payload di cinque tipi di evento — il dominio in un secondo posto. Il registro delle transizioni e proprio cio che una scrittura a mano falsificherebbe meglio: righe di forma giusta e contenuto inventato.

Due conseguenze pratiche: le Action scrivono `now()`, quindi gli istanti vanno riportati indietro **dopo** (con `forceFill`, perche `completed_at`/`completed_by` non sono assegnabili in massa); e nessuna release va seminata su un template disattivato, che `StartRelease` rifiuterebbe — un ambiente dimostrativo con uno stato irriproducibile dall'applicazione mente a chi lo usa per capire come funziona.

`DatabaseSeederTest` tiene quattro casi e non dieci di proposito: con `RefreshDatabase` ogni caso ripaga l'intero seed (~1s), e la granularita costerebbe piu di quanto renda.
