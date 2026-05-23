# Logeon Abilities and Spells

## Scopo

Modulo `Classe B` opzionale per abilita, incantesimi e UI contestuale in location.

## Perche e un buon candidato modulo

Il core espone gia slot dedicati nel launcher scena e nel pannello utilita location.
Questo permette di costruire il modulo senza cambiare `app/` o `core/`.

## Stato attuale

- manifest compatibile con `ModuleRuntime`;
- migrazioni proprie del modulo;
- CRUD admin per definizioni abilita;
- assegnazione abilita ai personaggi;
- endpoint game per lista e uso;
- integrazione location via slot modulo.

## Funzionalita attive

- definizioni additive di abilita;
- supporto bersaglio `self` e `scene`;
- effetto `none`, `apply_state`, `remove_state`;
- uso in location con feedback UI nel pannello utilita e nel launcher scena.
