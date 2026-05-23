# Logeon Archetype Attributes

Modulo opzionale che collega `logeon.archetypes` e `logeon.attributes` tramite regole additive su attributi personaggio.

## Scopo

- estendere gli archetipi senza modificare il modulo archetipi core;
- aggiungere vincoli e suggerimenti sugli attributi durante creazione e aggiornamento personaggio;
- mantenere tutto il codice isolato in `modules/logeon.archetype-attributes/`.

## Dipendenze

- `logeon.archetypes`
- `logeon.attributes`

## Feature principali

- pagina admin `/admin/archetype-attributes` per CRUD regole;
- supporto ai tipi regola:
  - `fixed_value`
  - `min_value`
  - `max_value`
  - `bonus`
  - `suggestion`
- campi attributo aggiuntivi in creazione personaggio;
- validazione server-side su creazione;
- bridge runtime sul modulo `game.profile` per validare e salvare gli aggiornamenti attributo tramite il nuovo endpoint del modulo.

## Persistenza

Il modulo usa solo la tabella additiva:

- `lf_archetype_attribute_rules`

La disinstallazione con purge elimina completamente la tabella.
