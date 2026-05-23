# Logeon Combat Environment

Estensione opzionale Tier 3 che dipende da `logeon.narrative-combat`.

## Scopo

Il modulo introduce il primo strato avanzato del battlefield:

- feature ambientali strutturate
- opportunita contestuali
- chokepoint e coperture evolute
- pericoli con stato variabile
- profondita ambiente configurabile

## Dipendenze

- `logeon.narrative-combat` obbligatorio
- il modulo base deve essere impostato almeno su `Tier 2`
- durante l'attivazione del modulo, se il base combat e ancora su `Tier 1`, Logeon lo promuove automaticamente a `Tier 2`

## Profondita interna

- `minimal`
- `standard`
- `advanced`

## Hook usati

- `combat.state.payload`
- `combat.resolve.scores`
- `twig.slot.game.location.conflicts.combat.tier3.after`

## Stato attuale

Questa prima release copre:

- authoring admin delle feature ambientali
- opportunita contestuali in game
- interazioni sintetiche sulle feature
- modificatori reali ai punteggi del resolver combat

Non copre ancora:

- zone tattiche complete con movement map
- catene di interazioni multi-step
- evoluzione automatica delle feature su domain event
- dashboard di bilanciamento staff dedicate
