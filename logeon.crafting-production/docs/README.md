# Logeon Crafting / Production

Modulo opzionale Classe B che aggiunge:

- professioni leggere assegnabili ai personaggi
- processi produttivi con input, output e requisiti
- sorgenti materiali contestuali
- storico lavorazioni con tracciabilita

## Pagine

- Admin: `/admin/crafting-production`
- Game: `/game/crafting-production`

## Requisiti supportati nel MVP

- `profession`
- `faction`
- `area`
- `event`
- `item`
- `station`

## Formato righe admin

- Input: `item_id|quantita|consume|nota`
- Input tool/non consumabile: `item_id|quantita|keep|nota`
- Output: `item_id|quantita|create|nota`
- Requisiti: `tipo|required|valore|nota`
- Sorgenti: `item_id|quantita|grant|nota`

## Note runtime

- gli input vengono verificati usando il sistema inventario core
- i consumi usano `InventoryService::destroyItem()`
- gli output usano `InventoryService::grantItemReward()`
- gli eventi attivi considerano `system_events.status = active` e `narrative_events.status = open`
- il modulo non richiede `economy`, ma salva metadata sufficienti a dialogarci in futuro
