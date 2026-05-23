# Logeon Advanced Items

## Scopo

Modulo `Classe B` opzionale per aggiungere evoluzioni additive al dominio oggetti.

## Boundary architetturale

- non sostituisce `items`, `inventory` o `shop` del core;
- non riusa pagine admin riservate del core;
- non modifica `app/` o `core/`;
- usera solo tabelle proprie, route proprie e hook/slot esistenti.

## Stato attuale

- manifest compatibile con `ModuleRuntime`;
- CRUD admin di profili avanzati;
- assegnazioni profilo -> personaggio con stato corrente;
- pagina game con utilizzo e ripristino reale delle risorse;
- collegamento opzionale a un `item_id` core senza toccare il CRUD oggetti.
