<?php

declare(strict_types=1);

namespace Modules\Logeon\AdvanceMaps\Services;

use App\Services\LocationService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class AdvanceMapsService
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var LocationService|null */
    private $locationService = null;
    /** @var bool|null */
    private $mapTypeColumn = null;
    /** @var bool|null */
    private $mapVisibleColumn = null;
    /** @var bool|null */
    private $hotspotsTable = null;

    public function __construct(DbAdapterInterface $db = null, LocationService $locationService = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->locationService = $locationService;
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

    private function locationService(): LocationService
    {
        if ($this->locationService instanceof LocationService) {
            return $this->locationService;
        }

        $this->locationService = new LocationService($this->db);
        return $this->locationService;
    }

    private function tableExists(string $table): bool
    {
        $row = $this->firstPrepared('SHOW TABLES LIKE ?', [$table]);
        return !empty($row);
    }

    private function mapHasMapType(): bool
    {
        if ($this->mapTypeColumn !== null) {
            return $this->mapTypeColumn;
        }
        $row = $this->firstPrepared('SHOW COLUMNS FROM maps LIKE "map_type"');
        $this->mapTypeColumn = !empty($row);
        return $this->mapTypeColumn;
    }

    private function mapHasVisibility(): bool
    {
        if ($this->mapVisibleColumn !== null) {
            return $this->mapVisibleColumn;
        }
        $row = $this->firstPrepared('SHOW COLUMNS FROM maps LIKE "is_visible"');
        $this->mapVisibleColumn = !empty($row);
        return $this->mapVisibleColumn;
    }

    private function hasHotspotsTable(): bool
    {
        if ($this->hotspotsTable !== null) {
            return $this->hotspotsTable;
        }
        $this->hotspotsTable = $this->tableExists('map_hotspots');
        return $this->hotspotsTable;
    }

    private function normalizeMapType($value): ?string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > 50) {
            throw AppError::validation('Tipo mappa troppo lungo', [], 'map_type_invalid');
        }
        return $text;
    }

    private function normalizeRenderMode($value): string
    {
        $mode = strtolower(trim((string) $value));
        if ($mode === 'visual' || $mode === 'hybrid') {
            return $mode;
        }
        return 'grid';
    }

    private function normalizeFlag($value): int
    {
        return ((int) $value === 1) ? 1 : 0;
    }

    private function normalizeNullableText($value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return ($text === '') ? null : $text;
    }

    private function getMapById(int $mapId)
    {
        if ($mapId <= 0) {
            return null;
        }

        return $this->firstPrepared(
            'SELECT m.*,
                    ' . ($this->mapHasMapType() ? 'm.map_type' : 'NULL') . ' AS map_type_safe,
                    ' . ($this->mapHasVisibility() ? 'm.is_visible' : '1') . ' AS is_visible_safe
             FROM maps m
             WHERE m.id = ?
             LIMIT 1',
            [$mapId],
        );
    }

    private function getMapVersionTokenFromRow($row): string
    {
        if (empty($row)) {
            return '';
        }

        $parts = [
            (string) ((int) ($row->id ?? 0)),
            trim((string) ($row->name ?? '')),
            trim((string) ($row->render_mode ?? '')),
            (string) ((int) ($row->position ?? 0)),
            (string) ((int) ($row->parent_map_id ?? 0)),
            (string) ((int) ($row->mobile ?? 0)),
            (string) ((int) ($row->initial ?? 0)),
            trim((string) ($row->status ?? '')),
            trim((string) ($row->icon ?? '')),
            trim((string) ($row->image ?? '')),
            trim((string) ($row->meteo ?? '')),
            trim((string) ($row->map_type_safe ?? $row->map_type ?? '')),
            (string) ((int) ($row->is_visible_safe ?? $row->is_visible ?? 1)),
            trim((string) ($row->date_created ?? '')),
            trim((string) ($row->date_updated ?? '')),
        ];

        return sha1(implode('|', $parts));
    }

    private function getMaxDepth(): int
    {
        $default = 10;
        $row = $this->firstPrepared(
            "SELECT `value` FROM sys_configs WHERE `key` = 'advance_maps_max_depth' LIMIT 1",
            [],
        );
        if (empty($row) || !isset($row->value)) {
            return $default;
        }
        $n = (int) $row->value;
        if ($n < 1) {
            return 1;
        }
        if ($n > 32) {
            return 32;
        }
        return $n;
    }

    private function normalizeParentMapId($value, int $currentId = 0): ?int
    {
        $parent = (int) $value;
        if ($parent <= 0) {
            return null;
        }

        if ($currentId > 0 && $parent === $currentId) {
            throw AppError::validation('Una mappa non puo essere parent di se stessa', [], 'map_parent_self_invalid');
        }

        $parentRow = $this->getMapById($parent);
        if (empty($parentRow)) {
            throw AppError::validation('Mappa padre non valida', [], 'map_parent_invalid');
        }

        $visited = [];
        $cursorId = $parent;
        while ($cursorId > 0) {
            if ($cursorId === $currentId || isset($visited[$cursorId])) {
                throw AppError::validation('Gerarchia mappe ciclica non consentita', [], 'map_parent_cycle');
            }
            $visited[$cursorId] = true;
            $cursor = $this->firstPrepared(
                'SELECT id, parent_map_id FROM maps WHERE id = ? LIMIT 1',
                [$cursorId],
            );
            if (empty($cursor)) {
                break;
            }
            $cursorId = (int) ($cursor->parent_map_id ?? 0);
        }

        return $parent;
    }

    private function assertDepthLimit(?int $parentMapId): void
    {
        if ($parentMapId === null || $parentMapId <= 0) {
            return;
        }

        $limit = $this->getMaxDepth();
        $depth = 1;
        $cursorId = $parentMapId;
        $visited = [];
        while ($cursorId > 0) {
            if (isset($visited[$cursorId])) {
                throw AppError::validation('Gerarchia mappe ciclica non consentita', [], 'map_parent_cycle');
            }
            $visited[$cursorId] = true;
            $depth++;
            if ($depth > $limit) {
                throw AppError::validation('Profondita massima gerarchia superata', [], 'map_depth_limit');
            }
            $cursor = $this->firstPrepared('SELECT parent_map_id FROM maps WHERE id = ? LIMIT 1', [$cursorId]);
            if (empty($cursor)) {
                break;
            }
            $cursorId = (int) ($cursor->parent_map_id ?? 0);
        }
    }

    private function resolveNextPosition($requested): int
    {
        $position = (int) $requested;
        if ($position > 0) {
            return $position;
        }
        $row = $this->firstPrepared('SELECT COALESCE(MAX(position), 0) AS max_position FROM maps');
        return ((int) ($row->max_position ?? 0)) + 1;
    }

    private function clearInitialFlag(): void
    {
        $this->execPrepared('UPDATE maps SET initial = 0', []);
    }

    private function visibilitySqlPrefix(bool $isStaff): string
    {
        if ($isStaff || !$this->mapHasVisibility()) {
            return '';
        }
        return ' AND m.is_visible = 1';
    }

    private function isMapReachableForRuntime(int $mapId, bool $isStaff): bool
    {
        if ($mapId <= 0) {
            return false;
        }

        $cursorId = $mapId;
        $visited = [];
        while ($cursorId > 0) {
            if (isset($visited[$cursorId])) {
                return false;
            }
            $visited[$cursorId] = true;

            $row = $this->firstPrepared(
                'SELECT id, parent_map_id, ' . ($this->mapHasVisibility() ? 'is_visible' : '1 AS is_visible') . ' FROM maps WHERE id = ? LIMIT 1',
                [$cursorId],
            );
            if (empty($row)) {
                return false;
            }
            if (!$isStaff && (int) ($row->is_visible ?? 1) !== 1) {
                return false;
            }

            $cursorId = (int) ($row->parent_map_id ?? 0);
        }
        return true;
    }

    private function mapSummaryRowToArray($row, bool $includeToken = false): array
    {
        $out = [
            'id' => (int) ($row->id ?? 0),
            'name' => (string) ($row->name ?? ''),
            'description' => (string) ($row->description ?? ''),
            'status' => (string) ($row->status ?? ''),
            'initial' => (int) ($row->initial ?? 0),
            'position' => (int) ($row->position ?? 0),
            'parent_map_id' => ((int) ($row->parent_map_id ?? 0)) > 0 ? (int) $row->parent_map_id : null,
            'parent_map_name' => isset($row->parent_map_name) ? (string) $row->parent_map_name : '',
            'mobile' => (int) ($row->mobile ?? 0),
            'icon' => (string) ($row->icon ?? ''),
            'image' => (string) ($row->image ?? ''),
            'render_mode' => $this->normalizeRenderMode($row->render_mode ?? 'grid'),
            'meteo' => (string) ($row->meteo ?? ''),
            'map_type' => $this->normalizeNullableText($row->map_type_safe ?? $row->map_type ?? null),
            'is_visible' => (int) ($row->is_visible_safe ?? $row->is_visible ?? 1),
            'children_count' => (int) ($row->children_count ?? 0),
            'locations_count' => (int) ($row->locations_count ?? 0),
            'has_children' => ((int) ($row->children_count ?? 0) > 0),
        ];

        if ($includeToken) {
            $out['version_token'] = $this->getMapVersionTokenFromRow($row);
        }

        return $out;
    }

    public function runtimeList(
        ?int $parentMapId,
        bool $rootOnly,
        ?int $idFilter,
        bool $isStaff,
        int $results = 200,
        int $page = 1,
        string $orderBy = 'position|ASC',
    ): array {
        $where = ['1=1'];
        $params = [];

        if ($idFilter !== null && $idFilter > 0) {
            $where[] = 'm.id = ?';
            $params[] = $idFilter;
        } elseif ($rootOnly || $parentMapId === null || $parentMapId <= 0) {
            $where[] = 'm.parent_map_id IS NULL';
        } else {
            $where[] = 'm.parent_map_id = ?';
            $params[] = $parentMapId;
        }

        if (!$isStaff && $this->mapHasVisibility()) {
            $where[] = 'm.is_visible = 1';
        }

        $allowedSort = [
            'id' => 'm.id',
            'name' => 'm.name',
            'position' => 'm.position',
            'render_mode' => 'm.render_mode',
            'initial' => 'm.initial',
            'mobile' => 'm.mobile',
            'parent_map_id' => 'm.parent_map_id',
        ];
        $parts = explode('|', $orderBy);
        $sortField = $allowedSort[$parts[0] ?? 'position'] ?? 'm.position';
        $sortDir = strtoupper($parts[1] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

        $results = max(1, min(500, $results));
        $page = max(1, $page);
        $offset = ($page - 1) * $results;

        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $countRow = $this->firstPrepared(
            'SELECT COUNT(*) AS n FROM maps m ' . $whereSql,
            $params,
        );
        $total = (int) ($countRow->n ?? 0);

        $rows = $this->fetchPrepared(
            'SELECT m.*,
                    p.name AS parent_map_name,
                    ' . ($this->mapHasMapType() ? 'm.map_type' : 'NULL') . ' AS map_type_safe,
                    ' . ($this->mapHasVisibility() ? 'm.is_visible' : '1') . ' AS is_visible_safe,
                    (SELECT COUNT(*) FROM maps c WHERE c.parent_map_id = m.id' . $this->visibilitySqlPrefix($isStaff) . ') AS children_count,
                    (SELECT COUNT(*) FROM locations l WHERE l.map_id = m.id AND l.date_deleted IS NULL) AS locations_count
             FROM maps m
             LEFT JOIN maps p ON p.id = m.parent_map_id
             ' . $whereSql . '
             ORDER BY ' . $sortField . ' ' . $sortDir . '
             LIMIT ? OFFSET ?',
            array_merge($params, [$results, $offset]),
        );

        $dataset = [];
        foreach ($rows as $row) {
            $mapId = (int) ($row->id ?? 0);
            if ($mapId <= 0) {
                continue;
            }
            if (!$isStaff && !$this->isMapReachableForRuntime($mapId, false)) {
                continue;
            }
            $dataset[] = $this->mapSummaryRowToArray($row);
        }

        return [
            'dataset' => $dataset,
            'properties' => [
                'page' => $page,
                'results_page' => $results,
                'orderBy' => ($parts[0] ?? 'position') . '|' . $sortDir,
                'tot' => ['count' => $total],
            ],
        ];
    }

    private function loadBreadcrumb(int $mapId, bool $isStaff): array
    {
        $breadcrumbs = [];
        $visited = [];
        $cursorId = $mapId;

        while ($cursorId > 0 && !isset($visited[$cursorId])) {
            $visited[$cursorId] = true;
            $row = $this->firstPrepared(
                'SELECT id, name, parent_map_id, ' . ($this->mapHasVisibility() ? 'is_visible' : '1 AS is_visible') . ' FROM maps WHERE id = ? LIMIT 1',
                [$cursorId],
            );
            if (empty($row)) {
                break;
            }
            if (!$isStaff && (int) ($row->is_visible ?? 1) !== 1) {
                break;
            }
            array_unshift($breadcrumbs, [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
            ]);
            $cursorId = (int) ($row->parent_map_id ?? 0);
        }

        return $breadcrumbs;
    }

    private function loadChildMaps(int $mapId, bool $isStaff): array
    {
        $whereVisibility = (!$isStaff && $this->mapHasVisibility()) ? ' AND m.is_visible = 1' : '';
        $rows = $this->fetchPrepared(
            'SELECT m.*,
                    p.name AS parent_map_name,
                    ' . ($this->mapHasMapType() ? 'm.map_type' : 'NULL') . ' AS map_type_safe,
                    ' . ($this->mapHasVisibility() ? 'm.is_visible' : '1') . ' AS is_visible_safe,
                    (SELECT COUNT(*) FROM maps c WHERE c.parent_map_id = m.id' . $whereVisibility . ') AS children_count,
                    (SELECT COUNT(*) FROM locations l WHERE l.map_id = m.id AND l.date_deleted IS NULL) AS locations_count
             FROM maps m
             LEFT JOIN maps p ON p.id = m.parent_map_id
             WHERE m.parent_map_id = ?' . $whereVisibility . '
             ORDER BY m.position ASC, m.id ASC',
            [$mapId],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->mapSummaryRowToArray($row);
        }
        return $out;
    }

    private function loadLocationsForRuntime(int $mapId, int $characterId): array
    {
        $rows = $this->fetchPrepared(
            'SELECT locations.id,
                    locations.map_id,
                    locations.owner_id,
                    locations.name,
                    locations.short_description,
                    locations.description,
                    locations.status,
                    locations.page,
                    locations.map_link,
                    locations.map_x,
                    locations.map_y,
                    locations.icon,
                    locations.image,
                    locations.guests,
                    locations.booking,
                    locations.deadline,
                    locations.cost,
                    locations.min_fame,
                    locations.min_socialstatus_id,
                    locations.is_house,
                    locations.chat_type,
                    locations.is_chat,
                    locations.is_private,
                    locations.access_policy,
                    locations.max_guests,
                    locations.date_created,
                    locations.date_updated,
                    locations.date_deleted
             FROM locations
             WHERE locations.map_id = ?
               AND locations.date_deleted IS NULL
             ORDER BY locations.name ASC',
            [$mapId],
        );

        if (empty($rows)) {
            return [];
        }

        $locationService = $this->locationService();
        $character = $locationService->getCharacterById($characterId);
        $invited = $locationService->getAcceptedInvitesSet($characterId);
        $guildAccess = $locationService->getGuildAccessSet($characterId);

        $dataset = [];
        foreach ($rows as $row) {
            $access = $locationService->evaluateAccess($row, $character, $invited, $guildAccess);
            $row->access = $access['allowed'];
            $row->access_reason = $access['reason'];
            $row->access_reason_code = $access['reason_code'];
            $row->is_owner = $access['is_owner'] ? 1 : 0;
            $row->is_invited = $access['is_invited'] ? 1 : 0;
            $row->is_full = $access['is_full'] ? 1 : 0;
            $row->guests_count = $access['guests_count'];
            $dataset[] = (array) $row;
        }
        return $dataset;
    }

    private function loadHotspotsForRuntime(int $mapId, bool $isStaff, array $children, array $locations): array
    {
        $out = [];
        $childById = [];
        foreach ($children as $child) {
            $childById[(int) ($child['id'] ?? 0)] = $child;
        }
        $locById = [];
        foreach ($locations as $loc) {
            $locById[(int) ($loc['id'] ?? 0)] = $loc;
        }

        if ($this->hasHotspotsTable()) {
            $whereVisible = (!$isStaff) ? ' AND h.is_visible = 1' : '';
            $rows = $this->fetchPrepared(
                'SELECT h.*
                 FROM map_hotspots h
                 WHERE h.map_id = ?' . $whereVisible . '
                 ORDER BY h.sort_order ASC, h.id ASC',
                [$mapId],
            );

            foreach ($rows as $row) {
                $id = (int) ($row->id ?? 0);
                $targetType = strtolower(trim((string) ($row->target_type ?? 'location')));
                if ($targetType !== 'map') {
                    $targetType = 'location';
                }
                $targetId = (int) ($row->target_id ?? 0);
                if ($targetId <= 0) {
                    continue;
                }

                if ($targetType === 'map') {
                    if (!isset($childById[$targetId])) {
                        continue;
                    }
                    $target = $childById[$targetId];
                    $targetName = (string) ($target['name'] ?? ('Mappa #' . $targetId));
                    $targetUrl = '/game/maps/' . $targetId;
                } else {
                    if (!isset($locById[$targetId])) {
                        continue;
                    }
                    $target = $locById[$targetId];
                    if (empty($target['access'])) {
                        continue;
                    }
                    $targetName = (string) ($target['name'] ?? ('Location #' . $targetId));
                    $targetUrl = '/game/maps/' . $mapId . '/location/' . $targetId;
                }

                $out[] = [
                    'id' => $id,
                    'map_id' => (int) ($row->map_id ?? $mapId),
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'target_name' => $targetName,
                    'target_url' => $targetUrl,
                    'label' => trim((string) ($row->label ?? '')),
                    'x' => (float) ($row->x ?? 0),
                    'y' => (float) ($row->y ?? 0),
                    'width' => (float) ($row->width ?? 6),
                    'height' => (float) ($row->height ?? 6),
                    'sort_order' => (int) ($row->sort_order ?? 0),
                    'is_visible' => (int) ($row->is_visible ?? 1),
                    'origin' => 'table',
                ];
            }
        }

        if (empty($out)) {
            foreach ($locations as $loc) {
                if (empty($loc['access'])) {
                    continue;
                }
                $x = isset($loc['map_x']) ? (float) $loc['map_x'] : null;
                $y = isset($loc['map_y']) ? (float) $loc['map_y'] : null;
                if ($x === null || $y === null) {
                    continue;
                }
                $locId = (int) ($loc['id'] ?? 0);
                if ($locId <= 0) {
                    continue;
                }
                $out[] = [
                    'id' => 0,
                    'map_id' => $mapId,
                    'target_type' => 'location',
                    'target_id' => $locId,
                    'target_name' => (string) ($loc['name'] ?? ('Location #' . $locId)),
                    'target_url' => '/game/maps/' . $mapId . '/location/' . $locId,
                    'label' => '',
                    'x' => $x,
                    'y' => $y,
                    'width' => 6.0,
                    'height' => 6.0,
                    'sort_order' => 0,
                    'is_visible' => 1,
                    'origin' => 'legacy_location',
                ];
            }
        }

        return $out;
    }

    public function runtimeContext(int $mapId, int $characterId, bool $isStaff): array
    {
        if ($mapId <= 0) {
            throw AppError::validation('Mappa non valida', [], 'map_not_found');
        }
        if ($characterId <= 0) {
            throw AppError::validation('Personaggio non valido', [], 'character_invalid');
        }

        $map = $this->getMapById($mapId);
        if (empty($map)) {
            throw AppError::notFound('Mappa non trovata', [], 'map_not_found');
        }
        if (!$isStaff && !$this->isMapReachableForRuntime($mapId, false)) {
            throw AppError::notFound('Mappa non raggiungibile', [], 'map_not_found');
        }

        $mapData = $this->mapSummaryRowToArray((object) [
            'id' => $map->id ?? 0,
            'name' => $map->name ?? '',
            'description' => $map->description ?? '',
            'status' => $map->status ?? '',
            'initial' => $map->initial ?? 0,
            'position' => $map->position ?? 0,
            'parent_map_id' => $map->parent_map_id ?? null,
            'parent_map_name' => '',
            'mobile' => $map->mobile ?? 0,
            'icon' => $map->icon ?? '',
            'image' => $map->image ?? '',
            'render_mode' => $map->render_mode ?? 'grid',
            'meteo' => $map->meteo ?? '',
            'map_type_safe' => $map->map_type_safe ?? null,
            'is_visible_safe' => $map->is_visible_safe ?? 1,
            'children_count' => 0,
            'locations_count' => 0,
        ]);

        $children = $this->loadChildMaps($mapId, $isStaff);
        $locations = $this->loadLocationsForRuntime($mapId, $characterId);
        $hotspots = $this->loadHotspotsForRuntime($mapId, $isStaff, $children, $locations);
        $breadcrumb = $this->loadBreadcrumb($mapId, $isStaff);

        $visualHasImage = trim((string) ($mapData['image'] ?? '')) !== '';
        $visualHasHotspots = false;
        foreach ($hotspots as $hs) {
            if (isset($hs['x']) && isset($hs['y'])) {
                $visualHasHotspots = true;
                break;
            }
        }
        $visualReady = $visualHasImage && $visualHasHotspots;
        $renderMode = $this->normalizeRenderMode($mapData['render_mode'] ?? 'grid');
        $effectiveMode = $renderMode;
        if (($renderMode === 'visual' || $renderMode === 'hybrid') && !$visualReady) {
            $effectiveMode = 'grid';
        }

        return [
            'map' => $mapData,
            'children' => $children,
            'locations' => $locations,
            'hotspots' => $hotspots,
            'breadcrumb' => $breadcrumb,
            'render_mode' => $renderMode,
            'effective_render_mode' => $effectiveMode,
            'visual_ready' => $visualReady,
        ];
    }

    public function adminList(
        string $nameLike = '',
        string $renderMode = '',
        string $initial = '',
        string $mobile = '',
        string $mapType = '',
        string $isVisible = '',
        int $results = 20,
        int $page = 1,
        string $sort = 'position|ASC',
    ): array {
        $where = [];
        $params = [];

        if ($nameLike !== '') {
            $where[] = 'm.`name` LIKE ?';
            $params[] = '%' . $nameLike . '%';
        }
        if ($renderMode !== '') {
            $where[] = 'm.`render_mode` = ?';
            $params[] = $this->normalizeRenderMode($renderMode);
        }
        if ($initial !== '') {
            $where[] = 'm.`initial` = ?';
            $params[] = ((int) $initial === 1) ? 1 : 0;
        }
        if ($mobile !== '') {
            $where[] = 'm.`mobile` = ?';
            $params[] = ((int) $mobile === 1) ? 1 : 0;
        }
        if ($this->mapHasMapType() && $mapType !== '') {
            $where[] = 'm.`map_type` = ?';
            $params[] = $mapType;
        }
        if ($this->mapHasVisibility() && $isVisible !== '') {
            $where[] = 'm.`is_visible` = ?';
            $params[] = ((int) $isVisible === 1) ? 1 : 0;
        }

        $whereClause = ($where !== []) ? ('WHERE ' . implode(' AND ', $where)) : '';
        $parts = explode('|', $sort);
        $allowedFields = ['id', 'name', 'position', 'render_mode', 'initial', 'mobile', 'parent_map_id'];
        $sortField = in_array($parts[0], $allowedFields, true) ? $parts[0] : 'position';
        $sortDir = strtoupper($parts[1] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

        $results = max(1, min(500, $results));
        $page = max(1, $page);
        $offset = ($page - 1) * $results;

        $countRow = $this->firstPrepared(
            'SELECT COUNT(*) AS n FROM maps m ' . $whereClause,
            $params,
        );
        $total = (int) ($countRow->n ?? 0);

        $rows = $this->fetchPrepared(
            'SELECT m.*,
                    p.name AS parent_map_name,
                    ' . ($this->mapHasMapType() ? 'm.map_type' : 'NULL') . ' AS map_type_safe,
                    ' . ($this->mapHasVisibility() ? 'm.is_visible' : '1') . ' AS is_visible_safe,
                    (SELECT COUNT(*) FROM maps c WHERE c.parent_map_id = m.id) AS children_count,
                    (SELECT COUNT(*) FROM locations l WHERE l.map_id = m.id AND l.date_deleted IS NULL) AS locations_count
             FROM maps m
             LEFT JOIN maps p ON p.id = m.parent_map_id
             ' . $whereClause . '
             ORDER BY m.`' . $sortField . '` ' . $sortDir . '
             LIMIT ? OFFSET ?',
            array_merge($params, [$results, $offset]),
        );

        $dataset = [];
        foreach ($rows as $row) {
            $dataset[] = $this->mapSummaryRowToArray($row, true);
        }

        return [
            'dataset' => $dataset,
            'properties' => [
                'query' => [
                    'name' => $nameLike,
                    'render_mode' => $renderMode,
                    'initial' => $initial,
                    'mobile' => $mobile,
                    'map_type' => $mapType,
                    'is_visible' => $isVisible,
                ],
                'page' => $page,
                'results_page' => $results,
                'orderBy' => $sortField . '|' . $sortDir,
                'tot' => ['count' => $total],
            ],
        ];
    }

    public function adminGet(int $id): array
    {
        $row = $this->getMapById($id);
        if (empty($row)) {
            throw AppError::validation('Mappa non trovata', [], 'map_not_found');
        }
        return $this->mapSummaryRowToArray($row, true);
    }

    public function adminSave(array $payload, int $actorUserId): array
    {
        $id = (int) ($payload['id'] ?? 0);
        $isUpdate = $id > 0;

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw AppError::validation('Nome mappa obbligatorio', [], 'map_name_required');
        }

        $parentMapId = $this->normalizeParentMapId($payload['parent_map_id'] ?? null, $id);
        $this->assertDepthLimit($parentMapId);

        $renderMode = $this->normalizeRenderMode($payload['render_mode'] ?? 'grid');
        $position = $this->resolveNextPosition($payload['position'] ?? null);
        $initial = $this->normalizeFlag($payload['initial'] ?? 0);
        $mobile = $this->normalizeFlag($payload['mobile'] ?? 0);
        $isVisible = $this->normalizeFlag($payload['is_visible'] ?? 1);
        $mapType = $this->normalizeMapType($payload['map_type'] ?? null);

        if ($initial === 1) {
            $this->clearInitialFlag();
        }

        if ($isUpdate) {
            $current = $this->getMapById($id);
            if (empty($current)) {
                throw AppError::validation('Mappa non trovata', [], 'map_not_found');
            }

            $clientToken = trim((string) ($payload['version_token'] ?? ''));
            if ($clientToken !== '') {
                $serverToken = $this->getMapVersionTokenFromRow($current);
                if (!hash_equals($serverToken, $clientToken)) {
                    throw AppError::validation(
                        'La mappa e stata modificata da un altro operatore. Ricarica i dati.',
                        [],
                        'map_concurrency_conflict',
                    );
                }
            }

            $sql = 'UPDATE maps
                    SET name = ?, description = ?, status = ?, initial = ?, position = ?,
                        parent_map_id = ?, mobile = ?, icon = ?, image = ?, render_mode = ?, meteo = ?';
            $params = [
                $name,
                $this->normalizeNullableText($payload['description'] ?? null),
                $this->normalizeNullableText($payload['status'] ?? null),
                $initial,
                $position,
                $parentMapId,
                $mobile,
                $this->normalizeNullableText($payload['icon'] ?? null),
                $this->normalizeNullableText($payload['image'] ?? null),
                $renderMode,
                $this->normalizeNullableText($payload['meteo'] ?? null),
            ];
            if ($this->mapHasMapType()) {
                $sql .= ', map_type = ?';
                $params[] = $mapType;
            }
            if ($this->mapHasVisibility()) {
                $sql .= ', is_visible = ?';
                $params[] = $isVisible;
            }
            $sql .= ' WHERE id = ?';
            $params[] = $id;
            $this->execPrepared($sql, $params);
        } else {
            $columns = [
                'name',
                'description',
                'status',
                'initial',
                'position',
                'parent_map_id',
                'mobile',
                'icon',
                'image',
                'render_mode',
                'meteo',
            ];
            $values = [
                $name,
                $this->normalizeNullableText($payload['description'] ?? null),
                $this->normalizeNullableText($payload['status'] ?? null),
                $initial,
                $position,
                $parentMapId,
                $mobile,
                $this->normalizeNullableText($payload['icon'] ?? null),
                $this->normalizeNullableText($payload['image'] ?? null),
                $renderMode,
                $this->normalizeNullableText($payload['meteo'] ?? null),
            ];
            if ($this->mapHasMapType()) {
                $columns[] = 'map_type';
                $values[] = $mapType;
            }
            if ($this->mapHasVisibility()) {
                $columns[] = 'is_visible';
                $values[] = $isVisible;
            }

            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $this->execPrepared(
                'INSERT INTO maps (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')',
                $values,
            );
            $id = (int) $this->db->lastInsertId();
        }

        $saved = $this->getMapById($id);
        return $this->mapSummaryRowToArray($saved, true);
    }

    public function adminDelete(int $mapId): void
    {
        if ($mapId <= 0) {
            throw AppError::validation('ID mappa non valido', [], 'map_id_invalid');
        }

        $map = $this->getMapById($mapId);
        if (empty($map)) {
            throw AppError::validation('Mappa non trovata', [], 'map_not_found');
        }

        $locRow = $this->firstPrepared('SELECT COUNT(*) AS total FROM locations WHERE map_id = ? AND date_deleted IS NULL', [$mapId]);
        if ((int) ($locRow->total ?? 0) > 0) {
            throw AppError::validation('Impossibile eliminare la mappa: ci sono luoghi associati', [], 'map_has_locations');
        }
        $childRow = $this->firstPrepared('SELECT COUNT(*) AS total FROM maps WHERE parent_map_id = ?', [$mapId]);
        if ((int) ($childRow->total ?? 0) > 0) {
            throw AppError::validation('Impossibile eliminare la mappa: contiene sottomappe', [], 'map_delete_has_children');
        }
        if ($this->hasHotspotsTable()) {
            $refRow = $this->firstPrepared('SELECT COUNT(*) AS total FROM map_hotspots WHERE target_type = "map" AND target_id = ?', [$mapId]);
            if ((int) ($refRow->total ?? 0) > 0) {
                throw AppError::validation(
                    'Impossibile eliminare la mappa: e referenziata da hotspot di altre mappe',
                    [],
                    'map_target_referenced',
                );
            }

            $this->execPrepared('DELETE FROM map_hotspots WHERE map_id = ?', [$mapId]);
        }

        $this->execPrepared('DELETE FROM maps WHERE id = ?', [$mapId]);
    }

    public function adminHotspotsList(int $mapId): array
    {
        if ($mapId <= 0) {
            throw AppError::validation('Mappa non valida', [], 'map_not_found');
        }
        if (empty($this->getMapById($mapId))) {
            throw AppError::validation('Mappa non trovata', [], 'map_not_found');
        }

        if (!$this->hasHotspotsTable()) {
            return [
                'dataset' => [],
                'options' => $this->hotspotOptions($mapId),
            ];
        }

        $rows = $this->fetchPrepared(
            'SELECT h.*
             FROM map_hotspots h
             WHERE h.map_id = ?
             ORDER BY h.sort_order ASC, h.id ASC',
            [$mapId],
        );

        $dataset = [];
        foreach ($rows as $row) {
            $dataset[] = [
                'id' => (int) ($row->id ?? 0),
                'map_id' => (int) ($row->map_id ?? $mapId),
                'target_type' => strtolower(trim((string) ($row->target_type ?? 'location'))),
                'target_id' => (int) ($row->target_id ?? 0),
                'label' => (string) ($row->label ?? ''),
                'x' => isset($row->x) ? (float) $row->x : null,
                'y' => isset($row->y) ? (float) $row->y : null,
                'width' => isset($row->width) ? (float) $row->width : 6.0,
                'height' => isset($row->height) ? (float) $row->height : 6.0,
                'sort_order' => (int) ($row->sort_order ?? 0),
                'is_visible' => (int) ($row->is_visible ?? 1),
            ];
        }

        return [
            'dataset' => $dataset,
            'options' => $this->hotspotOptions($mapId),
        ];
    }

    private function hotspotOptions(int $mapId): array
    {
        $maps = $this->fetchPrepared(
            'SELECT id, name FROM maps WHERE parent_map_id = ? ORDER BY position ASC, id ASC',
            [$mapId],
        );
        $locations = $this->fetchPrepared(
            'SELECT id, name FROM locations WHERE map_id = ? AND date_deleted IS NULL ORDER BY name ASC',
            [$mapId],
        );
        $mapOptions = [];
        foreach ($maps as $row) {
            $mapOptions[] = ['id' => (int) ($row->id ?? 0), 'name' => (string) ($row->name ?? '')];
        }
        $locationOptions = [];
        foreach ($locations as $row) {
            $locationOptions[] = ['id' => (int) ($row->id ?? 0), 'name' => (string) ($row->name ?? '')];
        }
        return [
            'maps' => $mapOptions,
            'locations' => $locationOptions,
        ];
    }

    public function adminHotspotSave(array $payload): array
    {
        if (!$this->hasHotspotsTable()) {
            throw AppError::validation('Tabella hotspot non disponibile', [], 'map_hotspot_invalid');
        }

        $id = (int) ($payload['id'] ?? 0);
        $mapId = (int) ($payload['map_id'] ?? 0);
        if ($mapId <= 0) {
            throw AppError::validation('Mappa non valida', [], 'map_not_found');
        }
        if (empty($this->getMapById($mapId))) {
            throw AppError::validation('Mappa non trovata', [], 'map_not_found');
        }

        $targetType = strtolower(trim((string) ($payload['target_type'] ?? 'location')));
        if ($targetType !== 'map') {
            $targetType = 'location';
        }
        $targetId = (int) ($payload['target_id'] ?? 0);
        if ($targetId <= 0) {
            throw AppError::validation('Target hotspot non valido', [], 'map_target_invalid');
        }

        if ($targetType === 'map') {
            $targetMap = $this->firstPrepared(
                'SELECT id, parent_map_id FROM maps WHERE id = ? LIMIT 1',
                [$targetId],
            );
            if (empty($targetMap) || (int) ($targetMap->parent_map_id ?? 0) !== $mapId) {
                throw AppError::validation(
                    'Hotspot mappa valido solo verso una mappa figlia diretta',
                    [],
                    'map_target_invalid',
                );
            }
        } else {
            $targetLoc = $this->firstPrepared(
                'SELECT id, map_id FROM locations WHERE id = ? AND date_deleted IS NULL LIMIT 1',
                [$targetId],
            );
            if (empty($targetLoc) || (int) ($targetLoc->map_id ?? 0) !== $mapId) {
                throw AppError::validation('Target location non valido per questa mappa', [], 'map_target_invalid');
            }
        }

        $x = ($payload['x'] === '' || $payload['x'] === null) ? null : (float) $payload['x'];
        $y = ($payload['y'] === '' || $payload['y'] === null) ? null : (float) $payload['y'];
        if ($x !== null && ($x < 0 || $x > 100)) {
            throw AppError::validation('Coordinata X non valida', [], 'map_hotspot_invalid');
        }
        if ($y !== null && ($y < 0 || $y > 100)) {
            throw AppError::validation('Coordinata Y non valida', [], 'map_hotspot_invalid');
        }

        $width = ($payload['width'] === '' || $payload['width'] === null) ? 6.0 : (float) $payload['width'];
        $height = ($payload['height'] === '' || $payload['height'] === null) ? 6.0 : (float) $payload['height'];
        if ($width <= 0 || $width > 100 || $height <= 0 || $height > 100) {
            throw AppError::validation('Dimensioni hotspot non valide', [], 'map_hotspot_invalid');
        }

        $label = $this->normalizeNullableText($payload['label'] ?? null);
        $sortOrder = (int) ($payload['sort_order'] ?? 0);
        $isVisible = $this->normalizeFlag($payload['is_visible'] ?? 1);

        if ($id > 0) {
            $row = $this->firstPrepared(
                'SELECT id, map_id FROM map_hotspots WHERE id = ? LIMIT 1',
                [$id],
            );
            if (empty($row) || (int) ($row->map_id ?? 0) !== $mapId) {
                throw AppError::validation('Hotspot non trovato', [], 'map_hotspot_invalid');
            }

            $this->execPrepared(
                'UPDATE map_hotspots
                 SET target_type = ?, target_id = ?, label = ?, x = ?, y = ?, width = ?, height = ?, is_visible = ?, sort_order = ?
                 WHERE id = ?',
                [$targetType, $targetId, $label, $x, $y, $width, $height, $isVisible, $sortOrder, $id],
            );
        } else {
            $this->execPrepared(
                'INSERT INTO map_hotspots
                 (map_id, target_type, target_id, label, x, y, width, height, is_visible, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$mapId, $targetType, $targetId, $label, $x, $y, $width, $height, $isVisible, $sortOrder],
            );
            $id = (int) $this->db->lastInsertId();
        }

        $saved = $this->firstPrepared(
            'SELECT * FROM map_hotspots WHERE id = ? LIMIT 1',
            [$id],
        );
        if (empty($saved)) {
            throw AppError::validation('Hotspot non trovato', [], 'map_hotspot_invalid');
        }

        return [
            'id' => (int) ($saved->id ?? 0),
            'map_id' => (int) ($saved->map_id ?? 0),
            'target_type' => strtolower(trim((string) ($saved->target_type ?? 'location'))),
            'target_id' => (int) ($saved->target_id ?? 0),
            'label' => (string) ($saved->label ?? ''),
            'x' => isset($saved->x) ? (float) $saved->x : null,
            'y' => isset($saved->y) ? (float) $saved->y : null,
            'width' => isset($saved->width) ? (float) $saved->width : 6.0,
            'height' => isset($saved->height) ? (float) $saved->height : 6.0,
            'sort_order' => (int) ($saved->sort_order ?? 0),
            'is_visible' => (int) ($saved->is_visible ?? 1),
        ];
    }

    public function adminHotspotDelete(int $id, int $mapId): void
    {
        if (!$this->hasHotspotsTable()) {
            return;
        }
        if ($id <= 0 || $mapId <= 0) {
            throw AppError::validation('Hotspot non valido', [], 'map_hotspot_invalid');
        }

        $row = $this->firstPrepared(
            'SELECT id, map_id FROM map_hotspots WHERE id = ? LIMIT 1',
            [$id],
        );
        if (empty($row) || (int) ($row->map_id ?? 0) !== $mapId) {
            throw AppError::validation('Hotspot non trovato', [], 'map_hotspot_invalid');
        }

        $this->execPrepared('DELETE FROM map_hotspots WHERE id = ?', [$id]);
    }
}

