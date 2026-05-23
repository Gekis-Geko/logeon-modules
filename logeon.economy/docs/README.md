# Logeon Economy

Modulo Classe B opzionale che aggiunge effetti economici contestuali e spiegabili sopra il core shop.

## Funzioni MVP

- CRUD admin per effetti economici
- collegamento effetti a `global`, `area`, `shop`, `faction`, `event`
- targeting su tutti i beni, item specifici o categorie item
- modifica runtime di:
  - prezzo
  - disponibilita
  - limite contestuale
  - accesso di fazione
  - etichette narrative
- integrazione con i nuovi hook core:
  - `shop.catalog.item`
  - `shop.purchase.resolve`
  - `shop.sell.resolve`

## Pagina admin

- `/admin/economy-effects`

## Dipendenze

Nessuna dipendenza hard da altri moduli.

Integrazioni soft:

- `logeon.factions` per membership/runtime fazione
- `logeon.multi-currency` per contesti valuta multipla
- `logeon.quests` e eventi core per layering narrativo del contesto

## Limiti attuali del MVP

- gli effetti `event` si attivano su `system_events.status = active` e `narrative_events.status = open`
- `stock_limit` e deterministico e contestuale, non simula refill o domanda/offerta
- il modulo non gestisce marketplace, inflazione, refill intelligente o economia autonoma
