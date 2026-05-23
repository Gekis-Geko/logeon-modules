# Logeon Narrative Combat

Modulo opzionale Classe B che estende il core conflitti con un sistema di combattimento narrativo progressivo.

## Stato attuale

L'implementazione corrente copre un **Tier 1 completo** e un **Tier 2 operativo**.

Il creatore di gioco puo scegliere dal pannello admin se usare il modulo in:

- `Tier 1`
- `Tier 2`

### Tier 1

- contesto combattimento associato a un conflitto core
- sincronizzazione partecipanti dal conflitto
- stamina e fatica
- dichiarazione azioni base
- risoluzione staff-driven delle azioni
- effetti narrativi di base
- pubblicazione esiti come messaggi di sistema/fato in location

### Tier 2 operativo

- `tier_level` del modulo riallineato a `2`
- indicatori sintetici leggibili per partecipante
- side summary e superiorita numerica
- cue di fase narrativa
- momentum sintetico per lato
- escalation progressiva con progressione contesto
- metriche derivate `pressure / control / attrition`
- relazioni di guardia leggere
- contesto ambientale leggero con visibilita, mobilita, pericolo e copertura
- impatto reale di stato squadra e ambiente sul resolver delle azioni

## Configurazione profondita

Dal pannello admin del modulo e disponibile il setting `combat_depth`:

- `1` = solo Tier 1
- `2` = Tier 1 + Tier 2

Se e attiva una estensione Tier 3 che richiede il layer avanzato, `Tier 2` viene mantenuto automaticamente come profondita minima effettiva.

## Azioni supportate

- `strike`
- `defend`
- `reposition`
- `recover`
- `protect`
- `disengage`

## Endpoint modulo

- `POST /combat/taxonomy`
- `POST /combat/state`
- `POST /combat/start`
- `POST /combat/participants/sync`
- `POST /combat/group/guard`
- `POST /combat/group/unguard`
- `POST /combat/env/set`
- `POST /combat/action/declare`
- `POST /combat/action/resolve`

## Integrazione

Il modulo si innesta nel conflict hub tramite lo slot:

- `view.slot.game.location.conflicts.combat_pane`

Non modifica file in `/app/` o `/core/` e usa solo le tabelle proprie del modulo.

## Estensioni Tier 3

Il Tier 3 non vive nello stesso modulo base.

La prima estensione prevista e gia supportata tramite dipendenza esplicita:

- `logeon.combat-environment`
- `logeon.combat-ai`
- `logeon.combat-coordination`
- `logeon.combat-admin-tools`

Questo permette di mantenere `logeon.narrative-combat` piu leggibile e configurabile, lasciando la profondita avanzata solo ai progetti che la vogliono davvero attivare.

## Limiti noti di questa release

- resolver per-conflict non agganciato al `ConflictResolverFactory`, perche l'hook core attuale non passa il `conflict_id`
- risoluzione delle azioni demandata allo staff
- niente zone tattiche piene o focus map estesa nel modulo base
- niente AI tattica, coordinated actions o dashboard staff avanzate
