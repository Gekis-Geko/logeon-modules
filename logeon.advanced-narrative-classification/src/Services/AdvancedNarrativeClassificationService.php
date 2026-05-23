<?php

declare(strict_types=1);

namespace Modules\Logeon\AdvancedNarrativeClassification\Services;

use App\Services\NarrativeTagService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class AdvancedNarrativeClassificationService
{
    private const ENTITY_TYPES = [
        NarrativeTagService::ENTITY_QUEST_DEFINITION,
        NarrativeTagService::ENTITY_NARRATIVE_EVENT,
        NarrativeTagService::ENTITY_SYSTEM_EVENT,
        NarrativeTagService::ENTITY_SCENE,
        NarrativeTagService::ENTITY_FACTION,
    ];

    /** @var DbAdapterInterface */
    private $db;
    /** @var NarrativeTagService|null */
    private $tagService = null;

    public function __construct(DbAdapterInterface $db = null, NarrativeTagService $tagService = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->tagService = $tagService;
    }

    private function tagService(): NarrativeTagService
    {
        if ($this->tagService instanceof NarrativeTagService) {
            return $this->tagService;
        }

        $this->tagService = new NarrativeTagService($this->db);
        return $this->tagService;
    }

    private function firstPrepared(string $sql, array $params = [])
    {
        return $this->db->fetchOnePrepared($sql, $params);
    }

    private function fetchPrepared(string $sql, array $params = []): array
    {
        return $this->db->fetchAllPrepared($sql, $params);
    }

    private function execPrepared(string $sql, array $params = []): void
    {
        $this->db->executePrepared($sql, $params);
    }

    private function rowToArray($row): array
    {
        if (is_object($row)) {
            return (array) $row;
        }
        return is_array($row) ? $row : [];
    }

    private function normalizeText($value, int $max = 255): string
    {
        return mb_substr(trim((string) $value), 0, $max);
    }

    private function normalizeSlug($value, int $max = 80): string
    {
        $raw = strtolower(trim((string) $value));
        $raw = preg_replace('/[^a-z0-9_\-]+/u', '-', $raw);
        $raw = trim((string) $raw, '-_');
        return mb_substr((string) $raw, 0, $max);
    }

    private function normalizeAlias($value): string
    {
        $raw = strtolower(trim((string) $value));
        $raw = preg_replace('/\s+/u', ' ', $raw);
        return mb_substr((string) $raw, 0, 120);
    }

    private function normalizeBool($value, bool $default = true): int
    {
        if ($value === null || $value === '') {
            return $default ? 1 : 0;
        }

        $raw = strtolower(trim((string) $value));
        if (in_array($raw, ['1', 'true', 'yes', 'si', 'on'], true)) {
            return 1;
        }
        if (in_array($raw, ['0', 'false', 'no', 'off'], true)) {
            return 0;
        }

        return ((int) $value > 0) ? 1 : 0;
    }

    private function normalizeIds($raw): array
    {
        return $this->tagService()->parseTagIds($raw);
    }

    private function ensurePositiveId(int $id, string $message, string $code): void
    {
        if ($id <= 0) {
            throw AppError::validation($message, [], $code);
        }
    }

    private function hasTable(string $table): bool
    {
        $row = $this->firstPrepared(
            'SELECT COUNT(*) AS c
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?
             LIMIT 1',
            [$table],
        );

        return !empty($row) && (int) ($row->c ?? 0) > 0;
    }

    private function validateTagIds(array $tagIds): array
    {
        $ids = [];
        foreach ($tagIds as $tagId) {
            $id = (int) $tagId;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        if (empty($ids)) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT id
             FROM narrative_tags
             WHERE is_active = 1
               AND id IN (' . implode(',', $ids) . ')',
        );

        $found = [];
        foreach ($rows as $row) {
            $id = (int) ($row->id ?? 0);
            if ($id > 0) {
                $found[$id] = $id;
            }
        }

        if (count($found) !== count($ids)) {
            throw AppError::validation('Sono presenti tag non validi o non attivi.', [], 'advanced_narrative_invalid_tags');
        }

        return array_values($found);
    }

    private function normalizeEntityType($value): string
    {
        $raw = strtolower(trim((string) $value));
        if ($raw === '' || $raw === 'all') {
            return 'all';
        }
        return $this->tagService()->normalizeEntityType($raw);
    }

    private function normalizeMatchMode($value): string
    {
        $raw = strtolower(trim((string) $value));
        return $raw === 'all' ? 'all' : 'any';
    }

    private function getTaxonomy(int $taxonomyId): array
    {
        $row = $this->firstPrepared('SELECT * FROM anc_taxonomies WHERE id = ? LIMIT 1', [$taxonomyId]);
        if (empty($row)) {
            throw AppError::notFound('Tassonomia non trovata.', [], 'advanced_narrative_taxonomy_not_found');
        }
        return $this->rowToArray($row);
    }

    private function getNode(int $nodeId): array
    {
        $row = $this->firstPrepared(
            'SELECT n.*, t.name AS taxonomy_name
             FROM anc_taxonomy_nodes n
             INNER JOIN anc_taxonomies t ON t.id = n.taxonomy_id
             WHERE n.id = ?
             LIMIT 1',
            [$nodeId],
        );
        if (empty($row)) {
            throw AppError::notFound('Nodo tassonomico non trovato.', [], 'advanced_narrative_node_not_found');
        }
        return $this->rowToArray($row);
    }

    private function getAlias(int $aliasId): array
    {
        $row = $this->firstPrepared('SELECT * FROM anc_tag_aliases WHERE id = ? LIMIT 1', [$aliasId]);
        if (empty($row)) {
            throw AppError::notFound('Alias non trovato.', [], 'advanced_narrative_alias_not_found');
        }
        return $this->rowToArray($row);
    }

    private function listTaxonomies(bool $includeInactive = true): array
    {
        $sql = 'SELECT t.*,
                       (
                           SELECT COUNT(*)
                           FROM anc_taxonomy_nodes n
                           WHERE n.taxonomy_id = t.id
                       ) AS nodes_count
                FROM anc_taxonomies t';
        if (!$includeInactive) {
            $sql .= ' WHERE t.is_active = 1';
        }
        $sql .= ' ORDER BY t.sort_order ASC, t.name ASC, t.id ASC';

        $rows = $this->fetchPrepared($sql);
        $out = [];
        foreach ($rows as $row) {
            $item = $this->rowToArray($row);
            $item['id'] = (int) ($item['id'] ?? 0);
            $item['nodes_count'] = (int) ($item['nodes_count'] ?? 0);
            $item['is_active'] = (int) ($item['is_active'] ?? 0);
            $out[] = $item;
        }
        return $out;
    }

    private function listNodes(bool $includeInactive = true): array
    {
        $sql = 'SELECT n.*,
                       t.name AS taxonomy_name,
                       t.slug AS taxonomy_slug,
                       p.label AS parent_label,
                       (
                           SELECT COUNT(*)
                           FROM anc_tag_node_links l
                           WHERE l.node_id = n.id
                       ) AS linked_tags_count
                FROM anc_taxonomy_nodes n
                INNER JOIN anc_taxonomies t ON t.id = n.taxonomy_id
                LEFT JOIN anc_taxonomy_nodes p ON p.id = n.parent_id';
        if (!$includeInactive) {
            $sql .= ' WHERE n.is_active = 1 AND t.is_active = 1';
        }
        $sql .= ' ORDER BY t.sort_order ASC, t.name ASC, COALESCE(p.sort_order, -1) ASC, n.sort_order ASC, n.label ASC, n.id ASC';

        $rows = $this->fetchPrepared($sql);
        $out = [];
        foreach ($rows as $row) {
            $item = $this->rowToArray($row);
            $item['id'] = (int) ($item['id'] ?? 0);
            $item['taxonomy_id'] = (int) ($item['taxonomy_id'] ?? 0);
            $item['parent_id'] = (int) ($item['parent_id'] ?? 0);
            $item['is_active'] = (int) ($item['is_active'] ?? 0);
            $item['linked_tags_count'] = (int) ($item['linked_tags_count'] ?? 0);
            $out[] = $item;
        }
        return $out;
    }

    private function listAliases(bool $includeInactive = true): array
    {
        $sql = 'SELECT a.*,
                       t.slug AS tag_slug,
                       t.label AS tag_label
                FROM anc_tag_aliases a
                INNER JOIN narrative_tags t ON t.id = a.tag_id';
        if (!$includeInactive) {
            $sql .= ' WHERE a.is_active = 1 AND t.is_active = 1';
        }
        $sql .= ' ORDER BY a.alias ASC, a.id ASC';

        $rows = $this->fetchPrepared($sql);
        $out = [];
        foreach ($rows as $row) {
            $item = $this->rowToArray($row);
            $item['id'] = (int) ($item['id'] ?? 0);
            $item['tag_id'] = (int) ($item['tag_id'] ?? 0);
            $item['is_active'] = (int) ($item['is_active'] ?? 0);
            $out[] = $item;
        }
        return $out;
    }

    private function listNodeLinks(?int $nodeId = null): array
    {
        $sql = 'SELECT l.id,
                       l.node_id,
                       l.tag_id,
                       n.label AS node_label,
                       n.taxonomy_id,
                       t.slug AS tag_slug,
                       t.label AS tag_label,
                       t.category AS tag_category
                FROM anc_tag_node_links l
                INNER JOIN anc_taxonomy_nodes n ON n.id = l.node_id
                INNER JOIN narrative_tags t ON t.id = l.tag_id';
        $params = [];
        if ($nodeId !== null && $nodeId > 0) {
            $sql .= ' WHERE l.node_id = ?';
            $params[] = $nodeId;
        }
        $sql .= ' ORDER BY n.label ASC, t.label ASC';

        $rows = $this->fetchPrepared($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $item = $this->rowToArray($row);
            $item['id'] = (int) ($item['id'] ?? 0);
            $item['node_id'] = (int) ($item['node_id'] ?? 0);
            $item['tag_id'] = (int) ($item['tag_id'] ?? 0);
            $item['taxonomy_id'] = (int) ($item['taxonomy_id'] ?? 0);
            $out[] = $item;
        }
        return $out;
    }

    private function buildNodeTree(bool $includeInactive = false): array
    {
        $taxonomies = $this->listTaxonomies($includeInactive);
        $nodes = $this->listNodes($includeInactive);
        $links = $this->listNodeLinks();
        $activeTags = $this->tagService()->listActiveCatalog();

        $tagsById = [];
        foreach ($activeTags as $tag) {
            $tagsById[(int) ($tag['id'] ?? 0)] = $tag;
        }

        $tagBuckets = [];
        foreach ($links as $link) {
            $nodeId = (int) ($link['node_id'] ?? 0);
            $tagId = (int) ($link['tag_id'] ?? 0);
            if ($nodeId <= 0 || $tagId <= 0 || !isset($tagsById[$tagId])) {
                continue;
            }
            if (!isset($tagBuckets[$nodeId])) {
                $tagBuckets[$nodeId] = [];
            }
            $tagBuckets[$nodeId][] = $tagsById[$tagId];
        }

        $nodesById = [];
        foreach ($nodes as $node) {
            $node['children'] = [];
            $node['linked_tags'] = isset($tagBuckets[(int) $node['id']]) ? $tagBuckets[(int) $node['id']] : [];
            $nodesById[(int) $node['id']] = $node;
        }

        foreach ($nodesById as $nodeId => $node) {
            $parentId = (int) ($node['parent_id'] ?? 0);
            if ($parentId > 0 && isset($nodesById[$parentId])) {
                $nodesById[$parentId]['children'][] = $node;
            }
        }

        $out = [];
        foreach ($taxonomies as $taxonomy) {
            $taxonomyId = (int) ($taxonomy['id'] ?? 0);
            $taxonomy['nodes'] = [];
            foreach ($nodesById as $node) {
                if ((int) ($node['taxonomy_id'] ?? 0) !== $taxonomyId) {
                    continue;
                }
                if ((int) ($node['parent_id'] ?? 0) > 0) {
                    continue;
                }
                $taxonomy['nodes'][] = $node;
            }
            $out[] = $taxonomy;
        }

        return $out;
    }

    private function summary(): array
    {
        $taxonomies = (int) (($this->firstPrepared('SELECT COUNT(*) AS n FROM anc_taxonomies')->n) ?? 0);
        $nodes = (int) (($this->firstPrepared('SELECT COUNT(*) AS n FROM anc_taxonomy_nodes')->n) ?? 0);
        $aliases = (int) (($this->firstPrepared('SELECT COUNT(*) AS n FROM anc_tag_aliases')->n) ?? 0);
        $links = (int) (($this->firstPrepared('SELECT COUNT(*) AS n FROM anc_tag_node_links')->n) ?? 0);
        $orphanTags = (int) (($this->firstPrepared(
            'SELECT COUNT(*) AS n
             FROM narrative_tags t
             WHERE t.is_active = 1
               AND NOT EXISTS (
                    SELECT 1
                    FROM anc_tag_node_links l
                    WHERE l.tag_id = t.id
               )',
        )->n) ?? 0);

        return [
            'taxonomies' => $taxonomies,
            'nodes' => $nodes,
            'aliases' => $aliases,
            'links' => $links,
            'orphan_tags' => $orphanTags,
        ];
    }

    public function adminBootstrap(): array
    {
        return [
            'summary' => $this->summary(),
            'core_tags' => $this->tagService()->listCatalog([], 300, 1, 'label|ASC', true)['rows'],
            'taxonomies' => $this->listTaxonomies(true),
            'nodes' => $this->listNodes(true),
            'aliases' => $this->listAliases(true),
            'node_links' => $this->listNodeLinks(),
        ];
    }

    public function upsertTaxonomy(object $data): int
    {
        $id = (int) ($data->id ?? 0);
        $slug = $this->normalizeSlug($data->slug ?? '');
        $name = $this->normalizeText($data->name ?? '', 120);
        $description = $this->normalizeText($data->description ?? '', 255);
        $sortOrder = (int) ($data->sort_order ?? 0);
        $isActive = $this->normalizeBool($data->is_active ?? 1, true);

        if ($slug === '' || $name === '') {
            throw AppError::validation('Slug e nome sono obbligatori.', [], 'advanced_narrative_taxonomy_required');
        }

        $dup = $this->firstPrepared(
            'SELECT id
             FROM anc_taxonomies
             WHERE slug = ?
               AND id <> ?
             LIMIT 1',
            [$slug, $id],
        );
        if (!empty($dup)) {
            throw AppError::validation('Slug tassonomia già presente.', [], 'advanced_narrative_taxonomy_slug_conflict');
        }

        if ($id > 0) {
            $this->getTaxonomy($id);
            $this->execPrepared(
                'UPDATE anc_taxonomies
                 SET slug = ?,
                     name = ?,
                     description = ?,
                     sort_order = ?,
                     is_active = ?,
                     date_updated = NOW()
                 WHERE id = ?
                 LIMIT 1',
                [
                    $slug,
                    $name,
                    $description !== '' ? $description : null,
                    $sortOrder,
                    $isActive,
                    $id,
                ],
            );
            return $id;
        }

        $this->execPrepared(
            'INSERT INTO anc_taxonomies
                (slug, name, description, sort_order, is_active, date_created)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [
                $slug,
                $name,
                $description !== '' ? $description : null,
                $sortOrder,
                $isActive,
            ],
        );

        return (int) $this->db->lastInsertId();
    }

    public function deleteTaxonomy(int $taxonomyId): void
    {
        $this->ensurePositiveId($taxonomyId, 'Tassonomia non valida.', 'advanced_narrative_taxonomy_required');
        $this->getTaxonomy($taxonomyId);

        $children = (int) (($this->firstPrepared(
            'SELECT COUNT(*) AS n
             FROM anc_taxonomy_nodes
             WHERE taxonomy_id = ?',
            [$taxonomyId],
        )->n) ?? 0);
        if ($children > 0) {
            throw AppError::validation('Elimina prima i nodi della tassonomia.', [], 'advanced_narrative_taxonomy_has_nodes');
        }

        $this->execPrepared('DELETE FROM anc_taxonomies WHERE id = ? LIMIT 1', [$taxonomyId]);
    }

    public function upsertNode(object $data): int
    {
        $id = (int) ($data->id ?? 0);
        $taxonomyId = (int) ($data->taxonomy_id ?? 0);
        $parentId = (int) ($data->parent_id ?? 0);
        $slug = $this->normalizeSlug($data->slug ?? '');
        $label = $this->normalizeText($data->label ?? '', 120);
        $description = $this->normalizeText($data->description ?? '', 255);
        $sortOrder = (int) ($data->sort_order ?? 0);
        $isActive = $this->normalizeBool($data->is_active ?? 1, true);

        $this->ensurePositiveId($taxonomyId, 'Tassonomia obbligatoria.', 'advanced_narrative_node_taxonomy_required');
        $this->getTaxonomy($taxonomyId);

        if ($slug === '' || $label === '') {
            throw AppError::validation('Slug e label del nodo sono obbligatori.', [], 'advanced_narrative_node_required');
        }

        if ($parentId > 0) {
            $parent = $this->getNode($parentId);
            if ((int) ($parent['taxonomy_id'] ?? 0) !== $taxonomyId) {
                throw AppError::validation('Il parent deve appartenere alla stessa tassonomia.', [], 'advanced_narrative_node_parent_taxonomy');
            }
            if ((int) ($parent['parent_id'] ?? 0) > 0) {
                throw AppError::validation('La tassonomia supporta solo un livello di profondità figlio.', [], 'advanced_narrative_node_depth_limit');
            }
            if ($id > 0 && $parentId === $id) {
                throw AppError::validation('Un nodo non può essere parent di se stesso.', [], 'advanced_narrative_node_self_parent');
            }
        } else {
            $parentId = 0;
        }

        $dup = $this->firstPrepared(
            'SELECT id
             FROM anc_taxonomy_nodes
             WHERE taxonomy_id = ?
               AND slug = ?
               AND id <> ?
             LIMIT 1',
            [$taxonomyId, $slug, $id],
        );
        if (!empty($dup)) {
            throw AppError::validation('Slug nodo già presente nella tassonomia.', [], 'advanced_narrative_node_slug_conflict');
        }

        if ($id > 0) {
            $this->getNode($id);
            $this->execPrepared(
                'UPDATE anc_taxonomy_nodes
                 SET taxonomy_id = ?,
                     parent_id = ?,
                     slug = ?,
                     label = ?,
                     description = ?,
                     sort_order = ?,
                     is_active = ?,
                     date_updated = NOW()
                 WHERE id = ?
                 LIMIT 1',
                [
                    $taxonomyId,
                    $parentId > 0 ? $parentId : null,
                    $slug,
                    $label,
                    $description !== '' ? $description : null,
                    $sortOrder,
                    $isActive,
                    $id,
                ],
            );
            return $id;
        }

        $this->execPrepared(
            'INSERT INTO anc_taxonomy_nodes
                (taxonomy_id, parent_id, slug, label, description, sort_order, is_active, date_created)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $taxonomyId,
                $parentId > 0 ? $parentId : null,
                $slug,
                $label,
                $description !== '' ? $description : null,
                $sortOrder,
                $isActive,
            ],
        );

        return (int) $this->db->lastInsertId();
    }

    public function deleteNode(int $nodeId): void
    {
        $this->ensurePositiveId($nodeId, 'Nodo non valido.', 'advanced_narrative_node_required');
        $this->getNode($nodeId);

        $children = (int) (($this->firstPrepared(
            'SELECT COUNT(*) AS n
             FROM anc_taxonomy_nodes
             WHERE parent_id = ?',
            [$nodeId],
        )->n) ?? 0);
        if ($children > 0) {
            throw AppError::validation('Elimina prima i sotto-nodi collegati.', [], 'advanced_narrative_node_has_children');
        }

        $this->execPrepared('DELETE FROM anc_taxonomy_nodes WHERE id = ? LIMIT 1', [$nodeId]);
    }

    public function syncNodeTags(int $nodeId, $rawTagIds): void
    {
        $this->ensurePositiveId($nodeId, 'Nodo non valido.', 'advanced_narrative_node_required');
        $this->getNode($nodeId);
        $tagIds = $this->validateTagIds($this->normalizeIds($rawTagIds));

        $rows = $this->fetchPrepared(
            'SELECT tag_id
             FROM anc_tag_node_links
             WHERE node_id = ?',
            [$nodeId],
        );
        $existing = [];
        foreach ($rows as $row) {
            $id = (int) ($row->tag_id ?? 0);
            if ($id > 0) {
                $existing[$id] = $id;
            }
        }

        $next = [];
        foreach ($tagIds as $tagId) {
            $next[(int) $tagId] = (int) $tagId;
        }

        $toDelete = array_values(array_diff($existing, $next));
        $toInsert = array_values(array_diff($next, $existing));

        foreach ($toDelete as $tagId) {
            $this->execPrepared(
                'DELETE FROM anc_tag_node_links
                 WHERE node_id = ?
                   AND tag_id = ?
                 LIMIT 1',
                [$nodeId, $tagId],
            );
        }

        foreach ($toInsert as $tagId) {
            $this->execPrepared(
                'INSERT INTO anc_tag_node_links
                    (node_id, tag_id, date_created)
                 VALUES (?, ?, NOW())',
                [$nodeId, $tagId],
            );
        }
    }

    public function upsertAlias(object $data): int
    {
        $id = (int) ($data->id ?? 0);
        $tagId = (int) ($data->tag_id ?? 0);
        $alias = $this->normalizeText($data->alias ?? '', 120);
        $normalizedAlias = $this->normalizeAlias($alias);
        $notes = $this->normalizeText($data->notes ?? '', 255);
        $isActive = $this->normalizeBool($data->is_active ?? 1, true);

        $this->ensurePositiveId($tagId, 'Tag obbligatorio.', 'advanced_narrative_alias_tag_required');
        $this->tagService()->getById($tagId);

        if ($alias === '' || $normalizedAlias === '') {
            throw AppError::validation('Alias obbligatorio.', [], 'advanced_narrative_alias_required');
        }

        $dup = $this->firstPrepared(
            'SELECT id
             FROM anc_tag_aliases
             WHERE normalized_alias = ?
               AND id <> ?
             LIMIT 1',
            [$normalizedAlias, $id],
        );
        if (!empty($dup)) {
            throw AppError::validation('Alias già presente.', [], 'advanced_narrative_alias_conflict');
        }

        if ($id > 0) {
            $this->getAlias($id);
            $this->execPrepared(
                'UPDATE anc_tag_aliases
                 SET tag_id = ?,
                     alias = ?,
                     normalized_alias = ?,
                     notes = ?,
                     is_active = ?,
                     date_updated = NOW()
                 WHERE id = ?
                 LIMIT 1',
                [
                    $tagId,
                    $alias,
                    $normalizedAlias,
                    $notes !== '' ? $notes : null,
                    $isActive,
                    $id,
                ],
            );
            return $id;
        }

        $this->execPrepared(
            'INSERT INTO anc_tag_aliases
                (tag_id, alias, normalized_alias, notes, is_active, date_created)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [
                $tagId,
                $alias,
                $normalizedAlias,
                $notes !== '' ? $notes : null,
                $isActive,
            ],
        );

        return (int) $this->db->lastInsertId();
    }

    public function deleteAlias(int $aliasId): void
    {
        $this->ensurePositiveId($aliasId, 'Alias non valido.', 'advanced_narrative_alias_required');
        $this->getAlias($aliasId);
        $this->execPrepared('DELETE FROM anc_tag_aliases WHERE id = ? LIMIT 1', [$aliasId]);
    }

    private function collectTagIdsForNodes(array $nodeIds): array
    {
        if (empty($nodeIds)) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT DISTINCT tag_id
             FROM anc_tag_node_links
             WHERE node_id IN (' . implode(',', $nodeIds) . ')',
        );

        $tagIds = [];
        foreach ($rows as $row) {
            $id = (int) ($row->tag_id ?? 0);
            if ($id > 0) {
                $tagIds[$id] = $id;
            }
        }
        return array_values($tagIds);
    }

    private function searchAliases(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $like = '%' . $this->normalizeAlias($query) . '%';
        $rows = $this->fetchPrepared(
            'SELECT a.*,
                    t.slug AS tag_slug,
                    t.label AS tag_label
             FROM anc_tag_aliases a
             INNER JOIN narrative_tags t ON t.id = a.tag_id
             WHERE a.is_active = 1
               AND t.is_active = 1
               AND a.normalized_alias LIKE ?
             ORDER BY a.alias ASC, a.id ASC
             LIMIT 30',
            [$like],
        );

        $out = [];
        foreach ($rows as $row) {
            $item = $this->rowToArray($row);
            $item['id'] = (int) ($item['id'] ?? 0);
            $item['tag_id'] = (int) ($item['tag_id'] ?? 0);
            $out[] = $item;
        }
        return $out;
    }

    private function searchNodes(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $like = '%' . $query . '%';
        $rows = $this->fetchPrepared(
            'SELECT n.*,
                    t.name AS taxonomy_name
             FROM anc_taxonomy_nodes n
             INNER JOIN anc_taxonomies t ON t.id = n.taxonomy_id
             WHERE n.is_active = 1
               AND t.is_active = 1
               AND (
                    n.slug LIKE ?
                    OR n.label LIKE ?
                    OR n.description LIKE ?
               )
             ORDER BY n.label ASC, n.id ASC
             LIMIT 40',
            [$like, $like, $like],
        );

        $out = [];
        foreach ($rows as $row) {
            $item = $this->rowToArray($row);
            $item['id'] = (int) ($item['id'] ?? 0);
            $item['taxonomy_id'] = (int) ($item['taxonomy_id'] ?? 0);
            $item['parent_id'] = (int) ($item['parent_id'] ?? 0);
            $out[] = $item;
        }
        return $out;
    }

    public function searchCatalog(string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return ['tags' => [], 'aliases' => [], 'nodes' => []];
        }

        return [
            'tags' => $this->tagService()->listActiveCatalog(null, ['search' => $query]),
            'aliases' => $this->searchAliases($query),
            'nodes' => $this->searchNodes($query),
        ];
    }

    private function listTagsByIds(array $tagIds): array
    {
        $tagIds = $this->validateTagIds($tagIds);
        if (empty($tagIds)) {
            return [];
        }
        $rows = $this->fetchPrepared(
            'SELECT id, slug, label, description, category, is_active
             FROM narrative_tags
             WHERE id IN (' . implode(',', $tagIds) . ')
             ORDER BY label ASC',
        );
        $out = [];
        foreach ($rows as $row) {
            $item = $this->rowToArray($row);
            $item['id'] = (int) ($item['id'] ?? 0);
            $item['is_active'] = (int) ($item['is_active'] ?? 0);
            $out[] = $item;
        }
        return $out;
    }

    private function tagNodesMap(array $tagIds): array
    {
        $tagIds = $this->normalizeIds($tagIds);
        if (empty($tagIds)) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT l.tag_id,
                    n.id AS node_id,
                    n.label AS node_label,
                    n.slug AS node_slug,
                    t.id AS taxonomy_id,
                    t.name AS taxonomy_name,
                    t.slug AS taxonomy_slug
             FROM anc_tag_node_links l
             INNER JOIN anc_taxonomy_nodes n ON n.id = l.node_id
             INNER JOIN anc_taxonomies t ON t.id = n.taxonomy_id
             WHERE l.tag_id IN (' . implode(',', $tagIds) . ')
               AND n.is_active = 1
               AND t.is_active = 1
             ORDER BY t.sort_order ASC, n.sort_order ASC, n.label ASC',
        );

        $out = [];
        foreach ($rows as $row) {
            $item = $this->rowToArray($row);
            $tagId = (int) ($item['tag_id'] ?? 0);
            if ($tagId <= 0) {
                continue;
            }
            if (!isset($out[$tagId])) {
                $out[$tagId] = [];
            }
            $out[$tagId][] = [
                'node_id' => (int) ($item['node_id'] ?? 0),
                'node_label' => (string) ($item['node_label'] ?? ''),
                'node_slug' => (string) ($item['node_slug'] ?? ''),
                'taxonomy_id' => (int) ($item['taxonomy_id'] ?? 0),
                'taxonomy_name' => (string) ($item['taxonomy_name'] ?? ''),
                'taxonomy_slug' => (string) ($item['taxonomy_slug'] ?? ''),
            ];
        }
        return $out;
    }

    private function aliasesByTagMap(array $tagIds): array
    {
        $tagIds = $this->normalizeIds($tagIds);
        if (empty($tagIds)) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT id, tag_id, alias, notes
             FROM anc_tag_aliases
             WHERE is_active = 1
               AND tag_id IN (' . implode(',', $tagIds) . ')
             ORDER BY alias ASC',
        );

        $out = [];
        foreach ($rows as $row) {
            $item = $this->rowToArray($row);
            $tagId = (int) ($item['tag_id'] ?? 0);
            if ($tagId <= 0) {
                continue;
            }
            if (!isset($out[$tagId])) {
                $out[$tagId] = [];
            }
            $out[$tagId][] = [
                'id' => (int) ($item['id'] ?? 0),
                'alias' => (string) ($item['alias'] ?? ''),
                'notes' => (string) ($item['notes'] ?? ''),
            ];
        }
        return $out;
    }

    private function listRecentEntities(string $entityType, int $limit = 10): array
    {
        $limit = max(1, min(30, $limit));

        if ($entityType === NarrativeTagService::ENTITY_QUEST_DEFINITION) {
            if (!$this->hasTable('quest_definitions')) {
                return [];
            }
            return $this->fetchPrepared(
                'SELECT id, title, slug
                 FROM quest_definitions
                 ORDER BY id DESC
                 LIMIT ?',
                [$limit],
            );
        }

        if ($entityType === NarrativeTagService::ENTITY_NARRATIVE_EVENT) {
            return $this->fetchPrepared(
                'SELECT id, title, event_type, description, visibility, created_at
                 FROM narrative_events
                 ORDER BY created_at DESC, id DESC
                 LIMIT ?',
                [$limit],
            );
        }

        if ($entityType === NarrativeTagService::ENTITY_SYSTEM_EVENT) {
            if (!$this->hasTable('system_events')) {
                return [];
            }
            return $this->fetchPrepared(
                'SELECT id, title, type, status, description, starts_at
                 FROM system_events
                 ORDER BY COALESCE(starts_at, date_created) DESC, id DESC
                 LIMIT ?',
                [$limit],
            );
        }

        if ($entityType === NarrativeTagService::ENTITY_SCENE) {
            return $this->fetchPrepared(
                'SELECT l.id, l.name, l.short_description, m.name AS map_name
                 FROM locations l
                 LEFT JOIN maps m ON m.id = l.map_id
                 WHERE l.date_deleted IS NULL
                 ORDER BY l.id DESC
                 LIMIT ?',
                [$limit],
            );
        }

        if ($entityType === NarrativeTagService::ENTITY_FACTION) {
            if (!$this->hasTable('factions')) {
                return [];
            }
            return $this->fetchPrepared(
                'SELECT id, code, name, type, scope, is_public, is_active
                 FROM factions
                 ORDER BY name ASC, id ASC
                 LIMIT ?',
                [$limit],
            );
        }

        return [];
    }

    private function searchEntitiesByText(string $entityType, string $query, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '') {
            return $this->listRecentEntities($entityType, $limit);
        }

        $like = '%' . $query . '%';
        $limit = max(1, min(30, $limit));

        if ($entityType === NarrativeTagService::ENTITY_QUEST_DEFINITION) {
            if (!$this->hasTable('quest_definitions')) {
                return [];
            }
            return $this->fetchPrepared(
                'SELECT id, title, slug
                 FROM quest_definitions
                 WHERE title LIKE ?
                    OR slug LIKE ?
                 ORDER BY id DESC
                 LIMIT ?',
                [$like, $like, $limit],
            );
        }

        if ($entityType === NarrativeTagService::ENTITY_NARRATIVE_EVENT) {
            return $this->fetchPrepared(
                'SELECT id, title, event_type, description, visibility, created_at
                 FROM narrative_events
                 WHERE title LIKE ?
                    OR description LIKE ?
                    OR tags LIKE ?
                 ORDER BY created_at DESC, id DESC
                 LIMIT ?',
                [$like, $like, $like, $limit],
            );
        }

        if ($entityType === NarrativeTagService::ENTITY_SYSTEM_EVENT) {
            if (!$this->hasTable('system_events')) {
                return [];
            }
            return $this->fetchPrepared(
                'SELECT id, title, type, status, description, starts_at
                 FROM system_events
                 WHERE title LIKE ?
                    OR description LIKE ?
                    OR type LIKE ?
                 ORDER BY COALESCE(starts_at, date_created) DESC, id DESC
                 LIMIT ?',
                [$like, $like, $like, $limit],
            );
        }

        if ($entityType === NarrativeTagService::ENTITY_SCENE) {
            return $this->fetchPrepared(
                'SELECT l.id, l.name, l.short_description, m.name AS map_name
                 FROM locations l
                 LEFT JOIN maps m ON m.id = l.map_id
                 WHERE l.date_deleted IS NULL
                   AND (
                        l.name LIKE ?
                        OR l.short_description LIKE ?
                   )
                 ORDER BY l.name ASC, l.id ASC
                 LIMIT ?',
                [$like, $like, $limit],
            );
        }

        if ($entityType === NarrativeTagService::ENTITY_FACTION) {
            if (!$this->hasTable('factions')) {
                return [];
            }
            return $this->fetchPrepared(
                'SELECT id, code, name, type, scope, is_public, is_active
                 FROM factions
                 WHERE name LIKE ?
                    OR code LIKE ?
                    OR type LIKE ?
                 ORDER BY name ASC, id ASC
                 LIMIT ?',
                [$like, $like, $like, $limit],
            );
        }

        return [];
    }

    private function listEntitiesByIds(string $entityType, array $entityIds, int $limit = 20): array
    {
        $entityIds = $this->normalizeIds($entityIds);
        if (empty($entityIds)) {
            return [];
        }
        $limit = max(1, min(50, $limit));

        if ($entityType === NarrativeTagService::ENTITY_QUEST_DEFINITION) {
            if (!$this->hasTable('quest_definitions')) {
                return [];
            }
            return $this->fetchPrepared(
                'SELECT id, title, slug
                 FROM quest_definitions
                 WHERE id IN (' . implode(',', $entityIds) . ')
                 ORDER BY id DESC
                 LIMIT ' . $limit,
            );
        }

        if ($entityType === NarrativeTagService::ENTITY_NARRATIVE_EVENT) {
            return $this->fetchPrepared(
                'SELECT id, title, event_type, description, visibility, created_at
                 FROM narrative_events
                 WHERE id IN (' . implode(',', $entityIds) . ')
                 ORDER BY created_at DESC, id DESC
                 LIMIT ' . $limit,
            );
        }

        if ($entityType === NarrativeTagService::ENTITY_SYSTEM_EVENT) {
            if (!$this->hasTable('system_events')) {
                return [];
            }
            return $this->fetchPrepared(
                'SELECT id, title, type, status, description, starts_at
                 FROM system_events
                 WHERE id IN (' . implode(',', $entityIds) . ')
                 ORDER BY COALESCE(starts_at, date_created) DESC, id DESC
                 LIMIT ' . $limit,
            );
        }

        if ($entityType === NarrativeTagService::ENTITY_SCENE) {
            return $this->fetchPrepared(
                'SELECT l.id, l.name, l.short_description, m.name AS map_name
                 FROM locations l
                 LEFT JOIN maps m ON m.id = l.map_id
                 WHERE l.id IN (' . implode(',', $entityIds) . ')
                   AND l.date_deleted IS NULL
                 ORDER BY l.name ASC, l.id ASC
                 LIMIT ' . $limit,
            );
        }

        if ($entityType === NarrativeTagService::ENTITY_FACTION) {
            if (!$this->hasTable('factions')) {
                return [];
            }
            return $this->fetchPrepared(
                'SELECT id, code, name, type, scope, is_public, is_active
                 FROM factions
                 WHERE id IN (' . implode(',', $entityIds) . ')
                 ORDER BY name ASC, id ASC
                 LIMIT ' . $limit,
            );
        }

        return [];
    }

    private function enrichRows(string $entityType, array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $rows = $this->tagService()->attachTagsToRows($entityType, $rows, 'id', 'narrative_tags', false);
        $tagIds = [];
        foreach ($rows as $row) {
            foreach ((array) ($row['narrative_tag_ids'] ?? []) as $tagId) {
                $id = (int) $tagId;
                if ($id > 0) {
                    $tagIds[$id] = $id;
                }
            }
        }

        $tagNodesMap = $this->tagNodesMap(array_values($tagIds));
        $aliasesMap = $this->aliasesByTagMap(array_values($tagIds));

        foreach ($rows as &$row) {
            $row['classification_nodes'] = [];
            foreach ((array) ($row['narrative_tag_ids'] ?? []) as $tagId) {
                $tagId = (int) $tagId;
                if ($tagId <= 0) {
                    continue;
                }
                foreach ($tagNodesMap[$tagId] ?? [] as $node) {
                    $nodeKey = (int) ($node['node_id'] ?? 0);
                    if ($nodeKey <= 0) {
                        continue;
                    }
                    $row['classification_nodes'][$nodeKey] = $node;
                }
                foreach ((array) ($row['narrative_tags'] ?? []) as &$tag) {
                    if ((int) ($tag['id'] ?? 0) !== $tagId) {
                        continue;
                    }
                    $tag['aliases'] = $aliasesMap[$tagId] ?? [];
                    $tag['taxonomy_nodes'] = $tagNodesMap[$tagId] ?? [];
                }
                unset($tag);
            }
            $row['classification_nodes'] = array_values($row['classification_nodes']);
        }
        unset($row);

        return $rows;
    }

    public function discover(array $filters): array
    {
        $entityType = $this->normalizeEntityType($filters['entity_type'] ?? 'all');
        $matchMode = $this->normalizeMatchMode($filters['match_mode'] ?? 'any');
        $query = trim((string) ($filters['query'] ?? ''));
        $tagIds = $this->normalizeIds($filters['tag_ids'] ?? []);
        $nodeIds = $this->normalizeIds($filters['node_ids'] ?? []);
        $limit = max(1, min(20, (int) ($filters['limit'] ?? 10)));

        $matchedTags = [];
        $matchedNodes = [];
        $matchedAliases = [];

        if (!empty($nodeIds)) {
            $matchedNodes = $this->searchNodes('');
            $matchedNodes = array_values(array_filter($this->listNodes(false), static function (array $node) use ($nodeIds): bool {
                return in_array((int) ($node['id'] ?? 0), $nodeIds, true);
            }));
            $tagIds = array_merge($tagIds, $this->collectTagIdsForNodes($nodeIds));
        }

        if ($query !== '') {
            $search = $this->searchCatalog($query);
            $matchedTags = is_array($search['tags'] ?? null) ? $search['tags'] : [];
            $matchedAliases = is_array($search['aliases'] ?? null) ? $search['aliases'] : [];
            $matchedNodes = array_merge($matchedNodes, is_array($search['nodes'] ?? null) ? $search['nodes'] : []);

            foreach ($matchedTags as $tag) {
                $id = (int) ($tag['id'] ?? 0);
                if ($id > 0) {
                    $tagIds[] = $id;
                }
            }
            foreach ($matchedAliases as $alias) {
                $id = (int) ($alias['tag_id'] ?? 0);
                if ($id > 0) {
                    $tagIds[] = $id;
                }
            }
            foreach ($matchedNodes as $node) {
                $id = (int) ($node['id'] ?? 0);
                if ($id > 0) {
                    $nodeIds[] = $id;
                }
            }
            if (!empty($nodeIds)) {
                $tagIds = array_merge($tagIds, $this->collectTagIdsForNodes($this->normalizeIds($nodeIds)));
            }
        }

        $tagIds = array_values(array_unique(array_map('intval', $tagIds)));
        if (!empty($tagIds)) {
            $matchedTags = $this->listTagsByIds($tagIds);
        }

        $entityTypes = ($entityType === 'all') ? self::ENTITY_TYPES : [$entityType];
        $results = [];
        $totals = [];

        foreach ($entityTypes as $type) {
            if (!in_array($type, self::ENTITY_TYPES, true)) {
                continue;
            }

            $rows = [];
            $total = 0;
            if (!empty($tagIds)) {
                $entityIds = $this->tagService()->filterEntityIdsByTagIds(
                    $type,
                    $tagIds,
                    $matchMode === 'all',
                );
                $total = count($entityIds);
                $rows = $this->listEntitiesByIds($type, $entityIds, $limit);
            } elseif ($query !== '') {
                $rows = $this->searchEntitiesByText($type, $query, $limit);
                $total = count($rows);
            } else {
                $rows = $this->listRecentEntities($type, $limit);
                $total = count($rows);
            }

            $rows = $this->enrichRows($type, $rows);
            $results[$type] = $rows;
            $totals[$type] = $total;
        }

        $matchedNodeIds = [];
        foreach ($matchedNodes as $node) {
            $nodeId = (int) ($node['id'] ?? 0);
            if ($nodeId > 0) {
                $matchedNodeIds[$nodeId] = $nodeId;
            }
        }

        return [
            'filters' => [
                'entity_type' => $entityType,
                'match_mode' => $matchMode,
                'query' => $query,
                'tag_ids' => $tagIds,
                'node_ids' => array_values(array_unique(array_map('intval', $nodeIds))),
            ],
            'matched_tags' => $matchedTags,
            'matched_nodes' => !empty($matchedNodeIds)
                ? array_values(array_filter($this->listNodes(false), static function (array $node) use ($matchedNodeIds): bool {
                    return isset($matchedNodeIds[(int) ($node['id'] ?? 0)]);
                }))
                : [],
            'matched_aliases' => $matchedAliases,
            'totals' => $totals,
            'results' => $results,
        ];
    }

    public function tagContext(int $tagId): array
    {
        $this->ensurePositiveId($tagId, 'Tag non valido.', 'advanced_narrative_tag_required');
        $tag = $this->tagService()->getById($tagId);

        $aliasMap = $this->aliasesByTagMap([$tagId]);
        $nodeMap = $this->tagNodesMap([$tagId]);
        $usage = [];
        foreach (self::ENTITY_TYPES as $entityType) {
            $usage[$entityType] = count($this->tagService()->filterEntityIdsByTagIds($entityType, [$tagId], false));
        }

        $discover = $this->discover([
            'entity_type' => 'all',
            'tag_ids' => [$tagId],
            'match_mode' => 'any',
            'limit' => 5,
        ]);

        return [
            'tag' => $tag,
            'aliases' => $aliasMap[$tagId] ?? [],
            'taxonomy_nodes' => $nodeMap[$tagId] ?? [],
            'usage' => $usage,
            'results' => $discover['results'] ?? [],
        ];
    }

    private function topMappedTags(int $limit = 12): array
    {
        $rows = $this->fetchPrepared(
            'SELECT t.id,
                    t.slug,
                    t.label,
                    t.description,
                    t.category,
                    COUNT(DISTINCT l.node_id) AS nodes_count,
                    (
                        SELECT COUNT(*)
                        FROM narrative_tag_assignments a
                        WHERE a.tag_id = t.id
                    ) AS assignments_count
             FROM narrative_tags t
             LEFT JOIN anc_tag_node_links l ON l.tag_id = t.id
             WHERE t.is_active = 1
             GROUP BY t.id, t.slug, t.label, t.description, t.category
             ORDER BY assignments_count DESC, nodes_count DESC, t.label ASC
             LIMIT ' . max(1, min(30, $limit)),
        );

        $out = [];
        foreach ($rows as $row) {
            $item = $this->rowToArray($row);
            $item['id'] = (int) ($item['id'] ?? 0);
            $item['nodes_count'] = (int) ($item['nodes_count'] ?? 0);
            $item['assignments_count'] = (int) ($item['assignments_count'] ?? 0);
            $out[] = $item;
        }
        return $out;
    }

    public function gameBootstrap(): array
    {
        return [
            'summary' => $this->summary(),
            'taxonomies_tree' => $this->buildNodeTree(false),
            'featured_tags' => $this->topMappedTags(),
        ];
    }
}
