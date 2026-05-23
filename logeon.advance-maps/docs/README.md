# Modulo Logeon Advance Maps

## Scopo

Modulo non bundled per portare il dominio mappe a un livello avanzato senza duplicare il runtime core:

- gerarchia opzionale tra mappe;
- navigazione mappa -> mappa;
- breadcrumb filtrato per visibilita/permessi;
- hotspot misti (`location` e `map`);
- modalita ibrida come terzo render della stessa fonte dati.

## Stato attuale

Bootstrap iniziale completato:

- manifest modulo;
- bootstrap e autoload namespace modulo;
- migrazione install/uninstall;
- placeholder routes.

## Prossimi step implementativi

1. runtime service modulo per:
   - validazione parent ciclico e depth limit;
   - validazione target hotspot (`map` solo su figlie dirette);
   - costruzione breadcrumb filtrato.
2. estensione admin mappe:
   - campi `map_type`, `is_visible`;
   - gestione hotspot misti.
3. estensione runtime gioco:
   - render `hybrid`;
   - fallback `visual -> grid` hard-safe.
4. test minimi backend/frontend/admin.

## Ownership DB (modulo)

- `map_hotspots` (owned dal modulo)
- estensione `maps` con:
  - `map_type`
  - `is_visible`

