-- Logeon Advance Maps - uninstall (safe)
-- Nota: non viene rimossa la colonna parent_map_id perche appartiene al core.

DROP TABLE IF EXISTS map_hotspots;

-- Ripristino soft colonne opzionali introdotte dal modulo.
ALTER TABLE maps
    DROP COLUMN IF EXISTS map_type,
    DROP COLUMN IF EXISTS is_visible;

