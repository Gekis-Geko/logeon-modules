INSERT IGNORE INTO `lf_abilities_spells_categories` (`slug`, `name`, `description`, `is_active`, `sort_order`)
VALUES
    ('general', 'Generale', 'Abilita generiche e trasversali.', 1, 100),
    ('combat', 'Combattimento', 'Abilita marziali e tattiche.', 1, 110),
    ('magic', 'Magia', 'Incantesimi, rituali e arti arcane.', 1, 120),
    ('social', 'Sociale', 'Influenza, empatia e leadership.', 1, 130),
    ('crafting', 'Artigianato', 'Produzione, riparazione e mestiere.', 1, 140),
    ('knowledge', 'Conoscenza', 'Studio, erudizione e investigazione.', 1, 150);

INSERT IGNORE INTO `lf_abilities_spells_point_categories` (`slug`, `name`, `description`, `is_active`, `sort_order`)
VALUES
    ('combat', 'Combattimento', 'Punti abilita per marziale e tattica.', 1, 110),
    ('magic', 'Magia', 'Punti abilita per incantesimi e rituali.', 1, 120),
    ('social', 'Sociale', 'Punti abilita per interazione e influenza.', 1, 130),
    ('crafting', 'Artigianato', 'Punti abilita per produzione e mestiere.', 1, 140),
    ('knowledge', 'Conoscenza', 'Punti abilita per studio e ricerca.', 1, 150);
