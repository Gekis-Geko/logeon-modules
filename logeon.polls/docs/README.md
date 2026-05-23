# Logeon Polls

Modulo Classe B per sondaggi anonimi della community.

## Funzioni MVP

- creazione sondaggi da admin
- 2-5 opzioni obbligatorie
- voto anonimo con blocco doppio voto per utente
- visibilità `public`, `player_only`, `staff_only`
- risultati visibili in game solo a sondaggio chiuso

## Pagine

- `/admin/polls`
- `/game/polls`

## Schema dati

- `polls`
- `poll_options`
- `poll_votes`

## Limiti attuali

- una sola scelta per sondaggio
- voto non modificabile
- nessuna risposta testuale
- nessuna integrazione notifiche o system events nell'MVP
