# Logeon Narrative States

## Scopo

Modulo `Classe B` opzionale per preset, kit e strumenti avanzati collegati agli stati narrativi.

## Boundary architetturale

- non sostituisce il dominio core `narrative-states`;
- non usa la pagina admin riservata `narrative-states`;
- non ridefinisce le route core gia documentate;
- aggiunge solo route, viste e dati propri.

## Stato attuale

- manifest compatibile con `ModuleRuntime`;
- CRUD admin di preset narrativi composti;
- step preset che orchestrano stati narrativi core (`apply` / `remove`);
- assegnazioni preset -> personaggio;
- pagina game che applica realmente il preset sul personaggio o su una scena accessibile.
