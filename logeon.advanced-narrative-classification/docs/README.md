# Logeon Advanced Narrative Classification

Modulo Classe B che estende i `narrative_tags` del core con:

- tassonomie leggere
- alias controllati
- collegamenti tag ↔ nodo tassonomico
- discovery avanzata per quest, eventi, scene e fazioni

## Dipendenze

Nessuna dipendenza modulo esplicita. Il modulo lavora sopra il sistema `narrative_tags` del core.

## Superfici incluse

- pagina admin: `/admin/advanced-narrative-classification`
- pagina game: `/game/advanced-narrative-classification`
- API modulo caricate solo se attivo

## Schema dati

Tabelle additive:

- `anc_taxonomies`
- `anc_taxonomy_nodes`
- `anc_tag_aliases`
- `anc_tag_node_links`

## Limiti attuali dell'MVP

- tassonomie con profondità massima pratica di 2 livelli
- nessun sistema di bundle/suggerimenti editoriali automatici
- nessuna modifica ai CRUD core di quest/eventi/scene/fazioni: il modulo lavora come layer parallelo di lettura e governance
