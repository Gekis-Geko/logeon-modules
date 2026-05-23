<?php

declare(strict_types=1);

namespace Modules\Logeon\CraftingProduction\Services;

use App\Services\FactionProviderRegistry;
use App\Services\InventoryService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;
use Core\ModuleManager;

class CraftingProductionService
{
    private const PROCESS_GATHER = 'gather';
    private const PROCESS_REFINEMENT = 'refinement';
    private const PROCESS_ASSEMBLY = 'assembly';
    private const PROCESS_COOKING_ALCHEMY = 'cooking_alchemy';
    private const PROCESS_REPAIR = 'repair';
    private const PROCESS_SALVAGE = 'salvage';
    private const PROCESS_CONVERSION = 'conversion';
    private const PROCESS_LABEL = 'label';

    private const VIS_PUBLIC = 'public';
    private const VIS_HIDDEN = 'hidden';
    private const VIS_RESTRICTED = 'restricted';

    private const DURATION_INSTANT = 'instant';
    private const DURATION_DELAYED = 'delayed';

    private const REQUIREMENT_PROFESSION = 'profession';
    private const REQUIREMENT_FACTION = 'faction';
    private const REQUIREMENT_AREA = 'area';
    private const REQUIREMENT_EVENT = 'event';
    private const REQUIREMENT_ITEM = 'item';
    private const REQUIREMENT_STATION = 'station';

    private const OP_REQUIRED = 'required';
    private const OP_ALLOWED = 'allowed';
    private const OP_BLOCKED = 'blocked';

    private const SOURCE_SHOP = 'shop';
    private const SOURCE_EVENT = 'event';
    private const SOURCE_FACTION = 'faction';
    private const SOURCE_AREA = 'area';
    private const SOURCE_SALVAGE = 'salvage';
    private const SOURCE_ADMIN = 'admin';

    private const SCOPE_GLOBAL = 'global';
    private const SCOPE_AREA = 'area';
    private const SCOPE_FACTION = 'faction';
    private const SCOPE_EVENT = 'event';

    /** @var DbAdapterInterface */
    private $db;
    /** @var ModuleManager|null */
    private $moduleManager = null;
    /** @var InventoryService|null */
    private $inventoryService = null;
    /** @var array<string,bool> */
    private $tableExists = [];

    public function __construct(
        DbAdapterInterface $db = null,
        ModuleManager $moduleManager = null,
        InventoryService $inventoryService = null,
    ) {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->moduleManager = $moduleManager;
        $this->inventoryService = $inventoryService;
    }

    private function moduleManager(): ModuleManager
    {
        if ($this->moduleManager instanceof ModuleManager) {
            return $this->moduleManager;
        }

        $this->moduleManager = new ModuleManager($this->db);
        return $this->moduleManager;
    }

    private function inventoryService(): InventoryService
    {
        if ($this->inventoryService instanceof InventoryService) {
            return $this->inventoryService;
        }

        $this->inventoryService = new InventoryService($this->db);
        return $this->inventoryService;
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

    private function begin(): void
    {
        $this->db->query('START TRANSACTION');
    }

    private function commit(): void
    {
        $this->db->query('COMMIT');
    }

    private function rollback(): void
    {
        try {
            $this->db->query('ROLLBACK');
        } catch (\Throwable $error) {
        }
    }

    private function rowToArray($row): array
    {
        if (is_object($row)) {
            return (array) $row;
        }

        return is_array($row) ? $row : [];
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function hasTable(string $table): bool
    {
        if (array_key_exists($table, $this->tableExists)) {
            return $this->tableExists[$table];
        }

        $row = $this->firstPrepared(
            'SELECT COUNT(*) AS c
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?
             LIMIT 1',
            [$table],
        );

        $this->tableExists[$table] = !empty($row) && (int) ($row->c ?? 0) > 0;
        return $this->tableExists[$table];
    }

    private function hasRuntimeArtifacts(): bool
    {
        return $this->hasTable('production_processes')
            && $this->hasTable('production_professions')
            && $this->hasTable('production_jobs');
    }

    private function ensurePositiveId(int $id, string $message, string $code): void
    {
        if ($id <= 0) {
            throw AppError::validation($message, [], $code);
        }
    }

    private function normalizeText($value, int $max = 255): string
    {
        return mb_substr(trim((string) $value), 0, $max);
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

    private function normalizeProcessType($value): string
    {
        $value = strtolower(trim((string) $value));
        $allowed = [
            self::PROCESS_GATHER,
            self::PROCESS_REFINEMENT,
            self::PROCESS_ASSEMBLY,
            self::PROCESS_COOKING_ALCHEMY,
            self::PROCESS_REPAIR,
            self::PROCESS_SALVAGE,
            self::PROCESS_CONVERSION,
            self::PROCESS_LABEL,
        ];

        return in_array($value, $allowed, true) ? $value : self::PROCESS_ASSEMBLY;
    }

    private function normalizeVisibility($value): string
    {
        $value = strtolower(trim((string) $value));
        $allowed = [self::VIS_PUBLIC, self::VIS_HIDDEN, self::VIS_RESTRICTED];
        return in_array($value, $allowed, true) ? $value : self::VIS_PUBLIC;
    }

    private function normalizeDurationType($value): string
    {
        $value = strtolower(trim((string) $value));
        $allowed = [self::DURATION_INSTANT, self::DURATION_DELAYED];
        return in_array($value, $allowed, true) ? $value : self::DURATION_INSTANT;
    }

    private function normalizeRequirementType($value): string
    {
        $value = strtolower(trim((string) $value));
        $allowed = [
            self::REQUIREMENT_PROFESSION,
            self::REQUIREMENT_FACTION,
            self::REQUIREMENT_AREA,
            self::REQUIREMENT_EVENT,
            self::REQUIREMENT_ITEM,
            self::REQUIREMENT_STATION,
        ];

        return in_array($value, $allowed, true) ? $value : self::REQUIREMENT_ITEM;
    }

    private function normalizeRequirementOperator($value): string
    {
        $value = strtolower(trim((string) $value));
        $allowed = [self::OP_REQUIRED, self::OP_ALLOWED, self::OP_BLOCKED];
        return in_array($value, $allowed, true) ? $value : self::OP_REQUIRED;
    }

    private function normalizeSourceType($value): string
    {
        $value = strtolower(trim((string) $value));
        $allowed = [
            self::SOURCE_SHOP,
            self::SOURCE_EVENT,
            self::SOURCE_FACTION,
            self::SOURCE_AREA,
            self::SOURCE_SALVAGE,
            self::SOURCE_ADMIN,
        ];

        return in_array($value, $allowed, true) ? $value : self::SOURCE_AREA;
    }

    private function normalizeScopeType($value): string
    {
        $value = strtolower(trim((string) $value));
        $allowed = [self::SCOPE_GLOBAL, self::SCOPE_AREA, self::SCOPE_FACTION, self::SCOPE_EVENT];
        return in_array($value, $allowed, true) ? $value : self::SCOPE_GLOBAL;
    }

    private function normalizeCode($value, int $max = 80): string
    {
        $raw = strtolower(trim((string) $value));
        $raw = preg_replace('/[^a-z0-9._-]+/', '-', $raw) ?: '';
        $raw = trim($raw, '-');
        return mb_substr($raw, 0, $max);
    }

    private function normalizeIds($raw): array
    {
        if (is_object($raw)) {
            $raw = (array) $raw;
        }

        if (!is_array($raw)) {
            $text = trim((string) $raw);
            if ($text === '') {
                return [];
            }
            $raw = preg_split('/[\s,;|]+/', $text) ?: [];
        }

        $out = [];
        foreach ($raw as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $out[$id] = $id;
            }
        }

        return array_values($out);
    }

    private function decodeMetaJson($value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function jsonEncode(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function parseStructuredLines($raw): array
    {
        $text = trim((string) $raw);
        if ($text === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $out[] = $parts;
        }

        return $out;
    }

    private function normalizeInputLines($raw): array
    {
        $rows = $this->parseStructuredLines($raw);
        $out = [];
        foreach ($rows as $index => $parts) {
            $itemId = (int) ($parts[0] ?? 0);
            $quantity = (int) ($parts[1] ?? 0);
            $consumeMode = strtolower(trim((string) ($parts[2] ?? 'consume')));
            $notes = $this->normalizeText($parts[3] ?? '', 255);
            if ($itemId <= 0 || $quantity <= 0) {
                continue;
            }
            if (!in_array($consumeMode, ['consume', 'keep'], true)) {
                $consumeMode = 'consume';
            }
            $out[] = [
                'item_id' => $itemId,
                'quantity' => $quantity,
                'consume_mode' => $consumeMode,
                'notes' => $notes,
                'sort_order' => ($index + 1) * 10,
            ];
        }

        return $out;
    }

    private function normalizeOutputLines($raw): array
    {
        $rows = $this->parseStructuredLines($raw);
        $out = [];
        foreach ($rows as $index => $parts) {
            $itemId = (int) ($parts[0] ?? 0);
            $quantity = (int) ($parts[1] ?? 0);
            $outputMode = strtolower(trim((string) ($parts[2] ?? 'create')));
            $notes = $this->normalizeText($parts[3] ?? '', 255);
            if ($itemId <= 0 || $quantity <= 0) {
                continue;
            }
            if (!in_array($outputMode, ['create', 'transform', 'recover'], true)) {
                $outputMode = 'create';
            }
            $out[] = [
                'item_id' => $itemId,
                'quantity' => $quantity,
                'output_mode' => $outputMode,
                'notes' => $notes,
                'sort_order' => ($index + 1) * 10,
            ];
        }

        return $out;
    }

    private function normalizeRequirementLines($raw): array
    {
        $rows = $this->parseStructuredLines($raw);
        $out = [];
        foreach ($rows as $index => $parts) {
            $type = $this->normalizeRequirementType($parts[0] ?? '');
            $operator = $this->normalizeRequirementOperator($parts[1] ?? '');
            $value = $this->normalizeText($parts[2] ?? '', 120);
            $notes = $this->normalizeText($parts[3] ?? '', 255);
            if ($value === '') {
                continue;
            }

            $out[] = [
                'requirement_type' => $type,
                'operator' => $operator,
                'requirement_value' => $value,
                'notes' => $notes,
                'sort_order' => ($index + 1) * 10,
            ];
        }

        return $out;
    }

    private function normalizeSourceItemLines($raw): array
    {
        $rows = $this->parseStructuredLines($raw);
        $out = [];
        foreach ($rows as $index => $parts) {
            $itemId = (int) ($parts[0] ?? 0);
            $quantity = (int) ($parts[1] ?? 0);
            $mode = strtolower(trim((string) ($parts[2] ?? 'grant')));
            $notes = $this->normalizeText($parts[3] ?? '', 255);
            if ($itemId <= 0 || $quantity <= 0) {
                continue;
            }
            if (!in_array($mode, ['grant', 'drop', 'recover', 'unlock'], true)) {
                $mode = 'grant';
            }
            $out[] = [
                'item_id' => $itemId,
                'quantity' => $quantity,
                'acquisition_mode' => $mode,
                'notes' => $notes,
                'sort_order' => ($index + 1) * 10,
            ];
        }

        return $out;
    }

    private function formatInputLines(array $rows): string
    {
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = implode('|', [
                (int) ($row['item_id'] ?? 0),
                (int) ($row['quantity'] ?? 0),
                (string) ($row['consume_mode'] ?? 'consume'),
                (string) ($row['notes'] ?? ''),
            ]);
        }

        return implode("\n", $lines);
    }

    private function formatOutputLines(array $rows): string
    {
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = implode('|', [
                (int) ($row['item_id'] ?? 0),
                (int) ($row['quantity'] ?? 0),
                (string) ($row['output_mode'] ?? 'create'),
                (string) ($row['notes'] ?? ''),
            ]);
        }

        return implode("\n", $lines);
    }

    private function formatRequirementLines(array $rows): string
    {
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = implode('|', [
                (string) ($row['requirement_type'] ?? ''),
                (string) ($row['operator'] ?? 'required'),
                (string) ($row['requirement_value'] ?? ''),
                (string) ($row['notes'] ?? ''),
            ]);
        }

        return implode("\n", $lines);
    }

    private function formatSourceItemLines(array $rows): string
    {
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = implode('|', [
                (int) ($row['item_id'] ?? 0),
                (int) ($row['quantity'] ?? 0),
                (string) ($row['acquisition_mode'] ?? 'grant'),
                (string) ($row['notes'] ?? ''),
            ]);
        }

        return implode("\n", $lines);
    }

    private function listItemMap(): array
    {
        if (!$this->hasTable('items')) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT id, name
             FROM items
             ORDER BY name ASC, id ASC',
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) ($row->id ?? 0)] = (string) ($row->name ?? ('Item #' . (int) ($row->id ?? 0)));
        }

        return $out;
    }

    private function listAreaMap(): array
    {
        if (!$this->hasTable('locations')) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT l.id, l.name, m.name AS map_name
             FROM locations l
             LEFT JOIN maps m ON m.id = l.map_id
             WHERE l.date_deleted IS NULL
             ORDER BY l.name ASC, l.id ASC',
        );

        $out = [];
        foreach ($rows as $row) {
            $label = trim((string) ($row->name ?? ('Area #' . (int) ($row->id ?? 0))));
            if (!empty($row->map_name)) {
                $label .= ' · ' . trim((string) $row->map_name);
            }
            $out[(int) ($row->id ?? 0)] = $label;
        }

        return $out;
    }

    private function listFactionMap(): array
    {
        if (!$this->moduleManager()->isActive('logeon.factions') || !$this->hasTable('factions')) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT id, name, code
             FROM factions
             WHERE is_active = 1
             ORDER BY name ASC, id ASC',
        );

        $out = [];
        foreach ($rows as $row) {
            $label = trim((string) ($row->name ?? ('Fazione #' . (int) ($row->id ?? 0))));
            if (!empty($row->code)) {
                $label .= ' · ' . trim((string) $row->code);
            }
            $out[(int) ($row->id ?? 0)] = $label;
        }

        return $out;
    }

    private function listEventMap(): array
    {
        $out = [];

        if ($this->hasTable('system_events')) {
            $rows = $this->fetchPrepared(
                'SELECT id, title
                 FROM system_events
                 ORDER BY id DESC
                 LIMIT 150',
            );
            foreach ($rows as $row) {
                $out[(int) ($row->id ?? 0)] = '[SYS] ' . trim((string) ($row->title ?? ('Evento #' . (int) ($row->id ?? 0))));
            }
        }

        if ($this->hasTable('narrative_events')) {
            $rows = $this->fetchPrepared(
                'SELECT id, title
                 FROM narrative_events
                 ORDER BY id DESC
                 LIMIT 150',
            );
            foreach ($rows as $row) {
                $id = (int) ($row->id ?? 0);
                if (!isset($out[$id])) {
                    $out[$id] = '[NAR] ' . trim((string) ($row->title ?? ('Evento #' . $id)));
                }
            }
        }

        return $out;
    }

    private function listCharacterNameMap(array $characterIds): array
    {
        $ids = $this->normalizeIds($characterIds);
        if (empty($ids) || !$this->hasTable('characters')) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT id, name, surname
             FROM characters
             WHERE id IN (' . implode(',', $ids) . ')',
        );

        $out = [];
        foreach ($rows as $row) {
            $label = trim(trim((string) ($row->name ?? '')) . ' ' . trim((string) ($row->surname ?? '')));
            $out[(int) ($row->id ?? 0)] = $label !== '' ? $label : ('Personaggio #' . (int) ($row->id ?? 0));
        }

        return $out;
    }

    private function filterReferenceMap(array $map, string $query): array
    {
        $needle = trim($query);
        if ($needle === '') {
            return [];
        }

        $needleLower = function_exists('mb_strtolower') ? mb_strtolower($needle) : strtolower($needle);
        $out = [];
        foreach ($map as $id => $label) {
            $haystack = function_exists('mb_strtolower') ? mb_strtolower((string) $label) : strtolower((string) $label);
            if (strpos($haystack, $needleLower) === false) {
                continue;
            }

            $out[] = [
                'id' => (int) $id,
                'label' => (string) $label,
            ];
            if (count($out) >= 12) {
                break;
            }
        }

        return $out;
    }

    public function adminSearchCharacters(string $query): array
    {
        $needle = trim($query);
        if ($needle === '' || !$this->hasTable('characters')) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT id, name, surname
             FROM characters
             WHERE name LIKE ?
                OR surname LIKE ?
                OR CONCAT_WS(" ", name, surname) LIKE ?
             ORDER BY name ASC, surname ASC, id ASC
             LIMIT 12',
            ['%' . $needle . '%', '%' . $needle . '%', '%' . $needle . '%'],
        );

        $dataset = [];
        foreach ($rows as $row) {
            $id = (int) ($row->id ?? 0);
            if ($id <= 0) {
                continue;
            }

            $label = trim(trim((string) ($row->name ?? '')) . ' ' . trim((string) ($row->surname ?? '')));
            $dataset[] = [
                'id' => $id,
                'label' => $label !== '' ? $label : ('Personaggio #' . $id),
            ];
        }

        return $dataset;
    }

    public function adminSearchScopeReferences(string $scopeType, string $query): array
    {
        $scopeType = $this->normalizeScopeType($scopeType);
        if ($scopeType === self::SCOPE_GLOBAL) {
            return [];
        }

        if ($scopeType === self::SCOPE_AREA) {
            return $this->filterReferenceMap($this->listAreaMap(), $query);
        }
        if ($scopeType === self::SCOPE_FACTION) {
            return $this->filterReferenceMap($this->listFactionMap(), $query);
        }
        if ($scopeType === self::SCOPE_EVENT) {
            return $this->filterReferenceMap($this->listEventMap(), $query);
        }

        return [];
    }

    private function processTypeLabel(string $value): string
    {
        return match ($value) {
            self::PROCESS_GATHER => 'Raccolta',
            self::PROCESS_REFINEMENT => 'Raffinazione',
            self::PROCESS_ASSEMBLY => 'Assemblaggio',
            self::PROCESS_COOKING_ALCHEMY => 'Cucina / Alchimia',
            self::PROCESS_REPAIR => 'Riparazione',
            self::PROCESS_SALVAGE => 'Salvage',
            self::PROCESS_CONVERSION => 'Conversione',
            self::PROCESS_LABEL => 'Etichetta',
            default => $value,
        };
    }

    private function requirementLabel(string $value): string
    {
        return match ($value) {
            self::REQUIREMENT_PROFESSION => 'Professione',
            self::REQUIREMENT_FACTION => 'Fazione',
            self::REQUIREMENT_AREA => 'Area',
            self::REQUIREMENT_EVENT => 'Evento',
            self::REQUIREMENT_ITEM => 'Strumento / item',
            self::REQUIREMENT_STATION => 'Stazione',
            default => $value,
        };
    }

    private function visibilityLabel(string $value): string
    {
        return match ($value) {
            self::VIS_PUBLIC => 'Pubblico',
            self::VIS_HIDDEN => 'Nascosto',
            self::VIS_RESTRICTED => 'Riservato',
            default => $value,
        };
    }

    private function sourceTypeLabel(string $value): string
    {
        return match ($value) {
            self::SOURCE_SHOP => 'Shop',
            self::SOURCE_EVENT => 'Evento',
            self::SOURCE_FACTION => 'Fazione',
            self::SOURCE_AREA => 'Area',
            self::SOURCE_SALVAGE => 'Salvage',
            self::SOURCE_ADMIN => 'Admin',
            default => $value,
        };
    }

    private function scopeLabel(string $value): string
    {
        return match ($value) {
            self::SCOPE_GLOBAL => 'Globale',
            self::SCOPE_AREA => 'Area',
            self::SCOPE_FACTION => 'Fazione',
            self::SCOPE_EVENT => 'Evento',
            default => $value,
        };
    }

    private function listProfessionRows(): array
    {
        if (!$this->hasRuntimeArtifacts()) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT *
             FROM production_professions
             ORDER BY is_active DESC, name ASC, id ASC',
        );

        $links = $this->fetchPrepared(
            'SELECT profession_id, character_id
             FROM production_profession_links
             ORDER BY character_id ASC',
        );

        $byProfession = [];
        $characterIds = [];
        foreach ($links as $row) {
            $professionId = (int) ($row->profession_id ?? 0);
            $characterId = (int) ($row->character_id ?? 0);
            if ($professionId <= 0 || $characterId <= 0) {
                continue;
            }
            if (!isset($byProfession[$professionId])) {
                $byProfession[$professionId] = [];
            }
            $byProfession[$professionId][$characterId] = $characterId;
            $characterIds[$characterId] = $characterId;
        }

        $characterMap = $this->listCharacterNameMap(array_values($characterIds));
        $out = [];
        foreach ($rows as $row) {
            $item = $this->rowToArray($row);
            $id = (int) ($item['id'] ?? 0);
            $assignedIds = array_values($byProfession[$id] ?? []);
            $assignedLabels = [];
            foreach ($assignedIds as $characterId) {
                $assignedLabels[] = $characterMap[$characterId] ?? ('Personaggio #' . $characterId);
            }
            $item['assigned_character_ids'] = $assignedIds;
            $item['assigned_character_ids_csv'] = implode(',', $assignedIds);
            $item['assigned_character_labels'] = $assignedLabels;
            $item['assigned_count'] = count($assignedIds);
            $out[] = $item;
        }

        return $out;
    }

    private function listProcessRows(bool $onlyActive = false): array
    {
        if (!$this->hasRuntimeArtifacts()) {
            return [];
        }

        $where = $onlyActive ? 'WHERE p.is_active = 1' : '';
        $rows = $this->fetchPrepared(
            'SELECT p.*
             FROM production_processes p
             ' . $where . '
             ORDER BY p.is_active DESC, p.name ASC, p.id ASC',
        );

        $processIds = [];
        foreach ($rows as $row) {
            $processIds[] = (int) ($row->id ?? 0);
        }
        $processIds = $this->normalizeIds($processIds);

        $inputsByProcess = [];
        $outputsByProcess = [];
        $requirementsByProcess = [];
        $itemMap = $this->listItemMap();
        $factionMap = $this->listFactionMap();
        $areaMap = $this->listAreaMap();
        $eventMap = $this->listEventMap();
        $professionRows = $this->listProfessionRows();
        $professionMap = [];
        foreach ($professionRows as $professionRow) {
            $professionMap[(int) ($professionRow['id'] ?? 0)] = (string) ($professionRow['name'] ?? '');
            $code = trim((string) ($professionRow['code'] ?? ''));
            if ($code !== '') {
                $professionMap[$code] = (string) ($professionRow['name'] ?? $code);
            }
        }

        if (!empty($processIds)) {
            $inputRows = $this->fetchPrepared(
                'SELECT *
                 FROM production_process_inputs
                 WHERE process_id IN (' . implode(',', $processIds) . ')
                 ORDER BY sort_order ASC, id ASC',
            );
            foreach ($inputRows as $row) {
                $item = $this->rowToArray($row);
                $item['item_name'] = $itemMap[(int) ($item['item_id'] ?? 0)] ?? ('Item #' . (int) ($item['item_id'] ?? 0));
                $inputsByProcess[(int) ($item['process_id'] ?? 0)][] = $item;
            }

            $outputRows = $this->fetchPrepared(
                'SELECT *
                 FROM production_process_outputs
                 WHERE process_id IN (' . implode(',', $processIds) . ')
                 ORDER BY sort_order ASC, id ASC',
            );
            foreach ($outputRows as $row) {
                $item = $this->rowToArray($row);
                $item['item_name'] = $itemMap[(int) ($item['item_id'] ?? 0)] ?? ('Item #' . (int) ($item['item_id'] ?? 0));
                $outputsByProcess[(int) ($item['process_id'] ?? 0)][] = $item;
            }

            $requirementRows = $this->fetchPrepared(
                'SELECT *
                 FROM production_process_requirements
                 WHERE process_id IN (' . implode(',', $processIds) . ')
                 ORDER BY sort_order ASC, id ASC',
            );
            foreach ($requirementRows as $row) {
                $item = $this->rowToArray($row);
                $label = (string) ($item['requirement_value'] ?? '');
                $type = (string) ($item['requirement_type'] ?? '');
                if ($type === self::REQUIREMENT_PROFESSION) {
                    $label = $professionMap[$label] ?? $professionMap[(int) $label] ?? $label;
                } elseif ($type === self::REQUIREMENT_FACTION) {
                    $label = $factionMap[(int) $label] ?? $label;
                } elseif ($type === self::REQUIREMENT_AREA) {
                    $label = $areaMap[(int) $label] ?? $label;
                } elseif ($type === self::REQUIREMENT_EVENT) {
                    $label = $eventMap[(int) $label] ?? $label;
                } elseif ($type === self::REQUIREMENT_ITEM) {
                    $label = $itemMap[(int) $label] ?? $label;
                }
                $item['value_label'] = $label;
                $item['requirement_type_label'] = $this->requirementLabel($type);
                $requirementsByProcess[(int) ($item['process_id'] ?? 0)][] = $item;
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $item = $this->rowToArray($row);
            $processId = (int) ($item['id'] ?? 0);
            $item['inputs'] = $inputsByProcess[$processId] ?? [];
            $item['outputs'] = $outputsByProcess[$processId] ?? [];
            $item['requirements'] = $requirementsByProcess[$processId] ?? [];
            $item['metadata_json'] = $this->decodeMetaJson((string) ($item['metadata_json'] ?? ''));
            $item['process_type_label'] = $this->processTypeLabel((string) ($item['process_type'] ?? ''));
            $item['visibility_label'] = $this->visibilityLabel((string) ($item['visibility'] ?? ''));
            $item['inputs_lines'] = $this->formatInputLines($item['inputs']);
            $item['outputs_lines'] = $this->formatOutputLines($item['outputs']);
            $item['requirements_lines'] = $this->formatRequirementLines($item['requirements']);
            $item['summary_label'] = $this->processSummaryLabel($item);
            $out[] = $item;
        }

        return $out;
    }

    private function listSourceRows(bool $onlyActive = false): array
    {
        if (!$this->hasRuntimeArtifacts() || !$this->hasTable('production_sources')) {
            return [];
        }

        $where = $onlyActive ? 'WHERE s.is_active = 1' : '';
        $rows = $this->fetchPrepared(
            'SELECT s.*
             FROM production_sources s
             ' . $where . '
             ORDER BY s.is_active DESC, s.name ASC, s.id ASC',
        );

        $sourceIds = [];
        foreach ($rows as $row) {
            $sourceIds[] = (int) ($row->id ?? 0);
        }
        $sourceIds = $this->normalizeIds($sourceIds);

        $itemMap = $this->listItemMap();
        $factionMap = $this->listFactionMap();
        $areaMap = $this->listAreaMap();
        $eventMap = $this->listEventMap();
        $sourceItemsBySource = [];

        if (!empty($sourceIds)) {
            $sourceItemRows = $this->fetchPrepared(
                'SELECT *
                 FROM production_source_items
                 WHERE source_id IN (' . implode(',', $sourceIds) . ')
                 ORDER BY sort_order ASC, id ASC',
            );
            foreach ($sourceItemRows as $row) {
                $item = $this->rowToArray($row);
                $item['item_name'] = $itemMap[(int) ($item['item_id'] ?? 0)] ?? ('Item #' . (int) ($item['item_id'] ?? 0));
                $sourceItemsBySource[(int) ($item['source_id'] ?? 0)][] = $item;
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $item = $this->rowToArray($row);
            $sourceId = (int) ($item['id'] ?? 0);
            $item['items'] = $sourceItemsBySource[$sourceId] ?? [];
            $item['items_lines'] = $this->formatSourceItemLines($item['items']);
            $item['metadata_json'] = $this->decodeMetaJson((string) ($item['metadata_json'] ?? ''));
            $item['source_type_label'] = $this->sourceTypeLabel((string) ($item['source_type'] ?? ''));
            $item['scope_type_label'] = $this->scopeLabel((string) ($item['scope_type'] ?? ''));
            $item['visibility_label'] = $this->visibilityLabel((string) ($item['visibility'] ?? ''));

            $scopeType = (string) ($item['scope_type'] ?? self::SCOPE_GLOBAL);
            $scopeRefId = (int) ($item['scope_ref_id'] ?? 0);
            $scopeLabel = 'Globale';
            if ($scopeType === self::SCOPE_AREA) {
                $scopeLabel = $areaMap[$scopeRefId] ?? ('Area #' . $scopeRefId);
            } elseif ($scopeType === self::SCOPE_FACTION) {
                $scopeLabel = $factionMap[$scopeRefId] ?? ('Fazione #' . $scopeRefId);
            } elseif ($scopeType === self::SCOPE_EVENT) {
                $scopeLabel = $eventMap[$scopeRefId] ?? ('Evento #' . $scopeRefId);
            }
            $item['scope_label'] = $scopeLabel;
            $out[] = $item;
        }

        return $out;
    }

    private function listRecentJobs(int $characterId = 0): array
    {
        if (!$this->hasRuntimeArtifacts()) {
            return [];
        }

        $where = '';
        $params = [];
        if ($characterId > 0) {
            $where = 'WHERE j.character_id = ?';
            $params[] = $characterId;
        }

        $rows = $this->fetchPrepared(
            'SELECT
                j.*,
                p.name AS process_name,
                p.process_type,
                c.name AS character_name,
                c.surname AS character_surname
             FROM production_jobs j
             LEFT JOIN production_processes p ON p.id = j.process_id
             LEFT JOIN characters c ON c.id = j.character_id
             ' . $where . '
             ORDER BY j.started_at DESC, j.id DESC
             LIMIT 25',
            $params,
        );

        $out = [];
        foreach ($rows as $row) {
            $item = $this->rowToArray($row);
            $item['process_type_label'] = $this->processTypeLabel((string) ($item['process_type'] ?? ''));
            $item['character_label'] = trim(trim((string) ($item['character_name'] ?? '')) . ' ' . trim((string) ($item['character_surname'] ?? '')));
            $item['context_snapshot'] = $this->decodeMetaJson((string) ($item['context_snapshot'] ?? ''));
            $item['result_snapshot'] = $this->decodeMetaJson((string) ($item['result_snapshot'] ?? ''));
            $out[] = $item;
        }

        return $out;
    }

    private function processSummaryLabel(array $process): string
    {
        $inputs = [];
        foreach ((array) ($process['inputs'] ?? []) as $row) {
            $inputs[] = (string) ($row['item_name'] ?? ('Item #' . (int) ($row['item_id'] ?? 0))) . ' x' . (int) ($row['quantity'] ?? 0);
        }
        $outputs = [];
        foreach ((array) ($process['outputs'] ?? []) as $row) {
            $outputs[] = (string) ($row['item_name'] ?? ('Item #' . (int) ($row['item_id'] ?? 0))) . ' x' . (int) ($row['quantity'] ?? 0);
        }

        return trim(
            (empty($inputs) ? 'Nessun input' : implode(', ', $inputs))
            . ' → '
            . (empty($outputs) ? 'Nessun output' : implode(', ', $outputs)),
        );
    }

    public function adminBootstrap(): array
    {
        $professions = $this->listProfessionRows();
        $processes = $this->listProcessRows();
        $sources = $this->listSourceRows();
        $jobs = $this->listRecentJobs();

        return [
            'summary' => [
                'professions' => count($professions),
                'processes' => count($processes),
                'active_processes' => count(array_filter($processes, static function (array $row): bool {
                    return (int) ($row['is_active'] ?? 0) === 1;
                })),
                'sources' => count($sources),
                'jobs' => count($jobs),
            ],
            'dependencies' => [
                'economy_active' => $this->moduleManager()->isActive('logeon.economy') ? 1 : 0,
                'factions_active' => $this->moduleManager()->isActive('logeon.factions') ? 1 : 0,
                'quests_active' => $this->moduleManager()->isActive('logeon.quests') ? 1 : 0,
            ],
            'options' => [
                'process_types' => [
                    ['value' => self::PROCESS_GATHER, 'label' => $this->processTypeLabel(self::PROCESS_GATHER)],
                    ['value' => self::PROCESS_REFINEMENT, 'label' => $this->processTypeLabel(self::PROCESS_REFINEMENT)],
                    ['value' => self::PROCESS_ASSEMBLY, 'label' => $this->processTypeLabel(self::PROCESS_ASSEMBLY)],
                    ['value' => self::PROCESS_COOKING_ALCHEMY, 'label' => $this->processTypeLabel(self::PROCESS_COOKING_ALCHEMY)],
                    ['value' => self::PROCESS_REPAIR, 'label' => $this->processTypeLabel(self::PROCESS_REPAIR)],
                    ['value' => self::PROCESS_SALVAGE, 'label' => $this->processTypeLabel(self::PROCESS_SALVAGE)],
                    ['value' => self::PROCESS_CONVERSION, 'label' => $this->processTypeLabel(self::PROCESS_CONVERSION)],
                    ['value' => self::PROCESS_LABEL, 'label' => $this->processTypeLabel(self::PROCESS_LABEL)],
                ],
                'visibilities' => [
                    ['value' => self::VIS_PUBLIC, 'label' => $this->visibilityLabel(self::VIS_PUBLIC)],
                    ['value' => self::VIS_RESTRICTED, 'label' => $this->visibilityLabel(self::VIS_RESTRICTED)],
                    ['value' => self::VIS_HIDDEN, 'label' => $this->visibilityLabel(self::VIS_HIDDEN)],
                ],
                'duration_types' => [
                    ['value' => self::DURATION_INSTANT, 'label' => 'Istantanea'],
                    ['value' => self::DURATION_DELAYED, 'label' => 'Ritardata (descrittiva)'],
                ],
                'source_types' => [
                    ['value' => self::SOURCE_AREA, 'label' => $this->sourceTypeLabel(self::SOURCE_AREA)],
                    ['value' => self::SOURCE_SHOP, 'label' => $this->sourceTypeLabel(self::SOURCE_SHOP)],
                    ['value' => self::SOURCE_FACTION, 'label' => $this->sourceTypeLabel(self::SOURCE_FACTION)],
                    ['value' => self::SOURCE_EVENT, 'label' => $this->sourceTypeLabel(self::SOURCE_EVENT)],
                    ['value' => self::SOURCE_SALVAGE, 'label' => $this->sourceTypeLabel(self::SOURCE_SALVAGE)],
                    ['value' => self::SOURCE_ADMIN, 'label' => $this->sourceTypeLabel(self::SOURCE_ADMIN)],
                ],
                'scope_types' => [
                    ['value' => self::SCOPE_GLOBAL, 'label' => $this->scopeLabel(self::SCOPE_GLOBAL)],
                    ['value' => self::SCOPE_AREA, 'label' => $this->scopeLabel(self::SCOPE_AREA)],
                    ['value' => self::SCOPE_FACTION, 'label' => $this->scopeLabel(self::SCOPE_FACTION)],
                    ['value' => self::SCOPE_EVENT, 'label' => $this->scopeLabel(self::SCOPE_EVENT)],
                ],
                'hints' => [
                    'inputs' => 'Formato input: item_id|quantita|consume|nota oppure item_id|quantita|keep|nota',
                    'outputs' => 'Formato output: item_id|quantita|create|nota',
                    'requirements' => 'Formato requisiti: tipo|required|valore|nota. Tipi: profession,faction,area,event,item,station',
                    'source_items' => 'Formato sorgente: item_id|quantita|grant|nota',
                ],
            ],
            'reference_options' => [
                'items' => $this->listItemMap(),
                'areas' => $this->listAreaMap(),
                'factions' => $this->listFactionMap(),
                'events' => $this->listEventMap(),
            ],
            'professions' => $professions,
            'processes' => $processes,
            'sources' => $sources,
            'recent_jobs' => $jobs,
        ];
    }

    public function saveProfession(object $data, int $userId): int
    {
        if (!$this->hasRuntimeArtifacts()) {
            throw AppError::validation('Schema crafting non disponibile.', [], 'crafting_schema_missing');
        }

        $id = (int) ($data->id ?? 0);
        $code = $this->normalizeCode($data->code ?? '');
        $name = $this->normalizeText($data->name ?? '', 120);
        $description = $this->normalizeText($data->description ?? '', 1000);
        $isActive = $this->normalizeBool($data->is_active ?? 1, true);
        $assignedCharacterIds = $this->normalizeIds($data->assigned_character_ids ?? $data->assigned_character_ids_csv ?? []);

        if ($code === '') {
            throw AppError::validation('Codice professione obbligatorio.', [], 'crafting_profession_code_required');
        }
        if ($name === '') {
            throw AppError::validation('Nome professione obbligatorio.', [], 'crafting_profession_name_required');
        }

        $this->begin();
        try {
            if ($id > 0) {
                $this->ensurePositiveId($id, 'Professione non valida.', 'crafting_profession_invalid');
                $this->execPrepared(
                    'UPDATE production_professions
                     SET code = ?,
                         name = ?,
                         description = ?,
                         is_active = ?,
                         date_updated = NOW()
                     WHERE id = ?
                     LIMIT 1',
                    [$code, $name, $description !== '' ? $description : null, $isActive, $id],
                );
                $professionId = $id;
            } else {
                $this->execPrepared(
                    'INSERT INTO production_professions
                        (code, name, description, is_active, date_created)
                     VALUES (?, ?, ?, ?, NOW())',
                    [$code, $name, $description !== '' ? $description : null, $isActive],
                );
                $professionId = (int) $this->db->lastInsertId();
            }

            $this->execPrepared('DELETE FROM production_profession_links WHERE profession_id = ?', [$professionId]);
            foreach ($assignedCharacterIds as $characterId) {
                $this->execPrepared(
                    'INSERT INTO production_profession_links
                        (profession_id, character_id, assigned_by_user_id, date_created)
                     VALUES (?, ?, ?, NOW())',
                    [$professionId, $characterId, $userId > 0 ? $userId : null],
                );
            }

            $this->commit();
            return $professionId;
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function deleteProfession(int $professionId): void
    {
        $this->ensurePositiveId($professionId, 'Professione non valida.', 'crafting_profession_invalid');
        $this->execPrepared('DELETE FROM production_professions WHERE id = ? LIMIT 1', [$professionId]);
    }

    public function saveProcess(object $data, int $userId): int
    {
        if (!$this->hasRuntimeArtifacts()) {
            throw AppError::validation('Schema crafting non disponibile.', [], 'crafting_schema_missing');
        }

        $id = (int) ($data->id ?? 0);
        $name = $this->normalizeText($data->name ?? '', 120);
        $description = $this->normalizeText($data->description ?? '', 2000);
        $processType = $this->normalizeProcessType($data->process_type ?? '');
        $category = $this->normalizeText($data->category ?? '', 80);
        $visibility = $this->normalizeVisibility($data->visibility ?? self::VIS_PUBLIC);
        $stationType = $this->normalizeCode($data->station_type ?? '', 80);
        $durationType = $this->normalizeDurationType($data->duration_type ?? self::DURATION_INSTANT);
        $durationValue = max(0, (int) ($data->duration_value ?? 0));
        $isActive = $this->normalizeBool($data->is_active ?? 1, true);
        $inputs = $this->normalizeInputLines($data->inputs_lines ?? '');
        $outputs = $this->normalizeOutputLines($data->outputs_lines ?? '');
        $requirements = $this->normalizeRequirementLines($data->requirements_lines ?? '');
        $notes = $this->normalizeText($data->notes ?? '', 500);

        if ($name === '') {
            throw AppError::validation('Nome processo obbligatorio.', [], 'crafting_process_name_required');
        }
        if (empty($outputs)) {
            throw AppError::validation('Definisci almeno un output.', [], 'crafting_process_outputs_required');
        }

        $this->begin();
        try {
            if ($id > 0) {
                $this->execPrepared(
                    'UPDATE production_processes
                     SET name = ?,
                         description = ?,
                         process_type = ?,
                         category = ?,
                         is_active = ?,
                         visibility = ?,
                         station_type = ?,
                         duration_type = ?,
                         duration_value = ?,
                         metadata_json = ?,
                         updated_by_user_id = ?,
                         date_updated = NOW()
                     WHERE id = ?
                     LIMIT 1',
                    [
                        $name,
                        $description !== '' ? $description : null,
                        $processType,
                        $category !== '' ? $category : null,
                        $isActive,
                        $visibility,
                        $stationType !== '' ? $stationType : null,
                        $durationType,
                        $durationValue > 0 ? $durationValue : null,
                        $this->jsonEncode(['notes' => $notes]),
                        $userId > 0 ? $userId : null,
                        $id,
                    ],
                );
                $processId = $id;
            } else {
                $this->execPrepared(
                    'INSERT INTO production_processes
                        (name, description, process_type, category, is_active, visibility, station_type, duration_type, duration_value, metadata_json, created_by_user_id, date_created)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                    [
                        $name,
                        $description !== '' ? $description : null,
                        $processType,
                        $category !== '' ? $category : null,
                        $isActive,
                        $visibility,
                        $stationType !== '' ? $stationType : null,
                        $durationType,
                        $durationValue > 0 ? $durationValue : null,
                        $this->jsonEncode(['notes' => $notes]),
                        $userId > 0 ? $userId : null,
                    ],
                );
                $processId = (int) $this->db->lastInsertId();
            }

            $this->execPrepared('DELETE FROM production_process_inputs WHERE process_id = ?', [$processId]);
            foreach ($inputs as $row) {
                $this->execPrepared(
                    'INSERT INTO production_process_inputs
                        (process_id, item_id, quantity, consume_mode, notes, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [
                        $processId,
                        (int) $row['item_id'],
                        (int) $row['quantity'],
                        (string) $row['consume_mode'],
                        $row['notes'] !== '' ? (string) $row['notes'] : null,
                        (int) $row['sort_order'],
                    ],
                );
            }

            $this->execPrepared('DELETE FROM production_process_outputs WHERE process_id = ?', [$processId]);
            foreach ($outputs as $row) {
                $this->execPrepared(
                    'INSERT INTO production_process_outputs
                        (process_id, item_id, quantity, output_mode, notes, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [
                        $processId,
                        (int) $row['item_id'],
                        (int) $row['quantity'],
                        (string) $row['output_mode'],
                        $row['notes'] !== '' ? (string) $row['notes'] : null,
                        (int) $row['sort_order'],
                    ],
                );
            }

            $this->execPrepared('DELETE FROM production_process_requirements WHERE process_id = ?', [$processId]);
            foreach ($requirements as $row) {
                $this->execPrepared(
                    'INSERT INTO production_process_requirements
                        (process_id, requirement_type, operator, requirement_value, notes, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [
                        $processId,
                        (string) $row['requirement_type'],
                        (string) $row['operator'],
                        (string) $row['requirement_value'],
                        $row['notes'] !== '' ? (string) $row['notes'] : null,
                        (int) $row['sort_order'],
                    ],
                );
            }

            $this->commit();
            return $processId;
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function deleteProcess(int $processId): void
    {
        $this->ensurePositiveId($processId, 'Processo non valido.', 'crafting_process_invalid');
        $this->execPrepared('DELETE FROM production_processes WHERE id = ? LIMIT 1', [$processId]);
    }

    public function saveSource(object $data, int $userId): int
    {
        if (!$this->hasRuntimeArtifacts()) {
            throw AppError::validation('Schema crafting non disponibile.', [], 'crafting_schema_missing');
        }

        $id = (int) ($data->id ?? 0);
        $name = $this->normalizeText($data->name ?? '', 120);
        $sourceType = $this->normalizeSourceType($data->source_type ?? self::SOURCE_AREA);
        $description = $this->normalizeText($data->description ?? '', 1000);
        $visibility = $this->normalizeVisibility($data->visibility ?? self::VIS_PUBLIC);
        $scopeType = $this->normalizeScopeType($data->scope_type ?? self::SCOPE_GLOBAL);
        $scopeRefId = (int) ($data->scope_ref_id ?? 0);
        $isActive = $this->normalizeBool($data->is_active ?? 1, true);
        $items = $this->normalizeSourceItemLines($data->items_lines ?? '');
        $notes = $this->normalizeText($data->notes ?? '', 500);

        if ($name === '') {
            throw AppError::validation('Nome sorgente obbligatorio.', [], 'crafting_source_name_required');
        }
        if ($scopeType !== self::SCOPE_GLOBAL && $scopeRefId <= 0) {
            throw AppError::validation('Scope sorgente non valido.', [], 'crafting_source_scope_required');
        }

        $this->begin();
        try {
            if ($id > 0) {
                $this->execPrepared(
                    'UPDATE production_sources
                     SET name = ?,
                         source_type = ?,
                         description = ?,
                         is_active = ?,
                         visibility = ?,
                         scope_type = ?,
                         scope_ref_id = ?,
                         metadata_json = ?,
                         updated_by_user_id = ?,
                         date_updated = NOW()
                     WHERE id = ?
                     LIMIT 1',
                    [
                        $name,
                        $sourceType,
                        $description !== '' ? $description : null,
                        $isActive,
                        $visibility,
                        $scopeType,
                        $scopeType === self::SCOPE_GLOBAL ? null : $scopeRefId,
                        $this->jsonEncode(['notes' => $notes]),
                        $userId > 0 ? $userId : null,
                        $id,
                    ],
                );
                $sourceId = $id;
            } else {
                $this->execPrepared(
                    'INSERT INTO production_sources
                        (name, source_type, description, is_active, visibility, scope_type, scope_ref_id, metadata_json, created_by_user_id, date_created)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                    [
                        $name,
                        $sourceType,
                        $description !== '' ? $description : null,
                        $isActive,
                        $visibility,
                        $scopeType,
                        $scopeType === self::SCOPE_GLOBAL ? null : $scopeRefId,
                        $this->jsonEncode(['notes' => $notes]),
                        $userId > 0 ? $userId : null,
                    ],
                );
                $sourceId = (int) $this->db->lastInsertId();
            }

            $this->execPrepared('DELETE FROM production_source_items WHERE source_id = ?', [$sourceId]);
            foreach ($items as $row) {
                $this->execPrepared(
                    'INSERT INTO production_source_items
                        (source_id, item_id, quantity, acquisition_mode, notes, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [
                        $sourceId,
                        (int) $row['item_id'],
                        (int) $row['quantity'],
                        (string) $row['acquisition_mode'],
                        $row['notes'] !== '' ? (string) $row['notes'] : null,
                        (int) $row['sort_order'],
                    ],
                );
            }

            $this->commit();
            return $sourceId;
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function deleteSource(int $sourceId): void
    {
        $this->ensurePositiveId($sourceId, 'Sorgente non valida.', 'crafting_source_invalid');
        $this->execPrepared('DELETE FROM production_sources WHERE id = ? LIMIT 1', [$sourceId]);
    }

    private function getActiveFactionIdsForCharacter(int $characterId): array
    {
        if ($characterId <= 0 || !$this->moduleManager()->isActive('logeon.factions')) {
            return [];
        }

        try {
            return array_values(array_unique(array_map('intval', FactionProviderRegistry::getMembershipsForCharacter($characterId))));
        } catch (\Throwable $error) {
            return [];
        }
    }

    private function getActiveEventIds(): array
    {
        $ids = [];
        if ($this->hasTable('system_events')) {
            $rows = $this->fetchPrepared(
                'SELECT id
                 FROM system_events
                 WHERE status = ?
                 ORDER BY id ASC',
                ['active'],
            );
            foreach ($rows as $row) {
                $id = (int) ($row->id ?? 0);
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }
        if ($this->hasTable('narrative_events')) {
            $rows = $this->fetchPrepared(
                'SELECT id
                 FROM narrative_events
                 WHERE status = ?
                 ORDER BY id ASC',
                ['open'],
            );
            foreach ($rows as $row) {
                $id = (int) ($row->id ?? 0);
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }

        return array_values($ids);
    }

    private function getCharacterContext(int $characterId): array
    {
        $row = $this->firstPrepared(
            'SELECT id, name, surname, last_location AS area_id
             FROM characters
             WHERE id = ?
             LIMIT 1',
            [$characterId],
        );

        if (empty($row)) {
            throw AppError::validation('Personaggio non valido.', [], 'character_invalid');
        }

        $professionRows = $this->fetchPrepared(
            'SELECT p.id, p.code, p.name
             FROM production_profession_links l
             INNER JOIN production_professions p ON p.id = l.profession_id
             WHERE l.character_id = ?
               AND p.is_active = 1
             ORDER BY p.name ASC, p.id ASC',
            [$characterId],
        );

        $professionIds = [];
        $professionCodes = [];
        $professions = [];
        foreach ($professionRows as $professionRow) {
            $professionId = (int) ($professionRow->id ?? 0);
            $professionCode = trim((string) ($professionRow->code ?? ''));
            if ($professionId > 0) {
                $professionIds[$professionId] = $professionId;
            }
            if ($professionCode !== '') {
                $professionCodes[$professionCode] = $professionCode;
            }
            $professions[] = [
                'id' => $professionId,
                'code' => $professionCode,
                'name' => (string) ($professionRow->name ?? ''),
            ];
        }

        return [
            'character_id' => $characterId,
            'character_name' => trim(trim((string) ($row->name ?? '')) . ' ' . trim((string) ($row->surname ?? ''))),
            'area_id' => (int) ($row->area_id ?? 0),
            'faction_ids' => $this->getActiveFactionIdsForCharacter($characterId),
            'active_event_ids' => $this->getActiveEventIds(),
            'profession_ids' => array_keys($professionIds),
            'profession_codes' => array_keys($professionCodes),
            'professions' => $professions,
        ];
    }

    private function matchesSourceScope(array $source, array $context): bool
    {
        $scopeType = (string) ($source['scope_type'] ?? self::SCOPE_GLOBAL);
        $scopeRefId = (int) ($source['scope_ref_id'] ?? 0);
        if ($scopeType === self::SCOPE_GLOBAL) {
            return true;
        }
        if ($scopeType === self::SCOPE_AREA) {
            return $scopeRefId > 0 && $scopeRefId === (int) ($context['area_id'] ?? 0);
        }
        if ($scopeType === self::SCOPE_FACTION) {
            return in_array($scopeRefId, (array) ($context['faction_ids'] ?? []), true);
        }
        if ($scopeType === self::SCOPE_EVENT) {
            return in_array($scopeRefId, (array) ($context['active_event_ids'] ?? []), true);
        }

        return false;
    }

    private function availableSourcesForContext(array $context): array
    {
        $sources = $this->listSourceRows(true);
        $out = [];
        foreach ($sources as $source) {
            $visibility = (string) ($source['visibility'] ?? self::VIS_PUBLIC);
            if ($visibility === self::VIS_HIDDEN) {
                continue;
            }
            if (!$this->matchesSourceScope($source, $context)) {
                continue;
            }
            $out[] = $source;
        }

        return $out;
    }

    private function requirementReason(array $requirement): string
    {
        $operator = (string) ($requirement['operator'] ?? self::OP_REQUIRED);
        $label = (string) ($requirement['value_label'] ?? $requirement['requirement_value'] ?? '');
        $typeLabel = strtolower((string) ($requirement['requirement_type_label'] ?? $this->requirementLabel((string) ($requirement['requirement_type'] ?? ''))));

        if ($operator === self::OP_BLOCKED) {
            return 'Produzione bloccata: ' . $typeLabel . ' non compatibile (' . $label . ')';
        }

        return 'Produzione non disponibile: richiede ' . $typeLabel . ' ' . $label;
    }

    private function inputReason(array $input): string
    {
        $mode = (string) ($input['consume_mode'] ?? 'consume');
        $verb = $mode === 'keep' ? 'serve' : 'manca';
        return 'Produzione non disponibile: ' . $verb . ' ' . (string) ($input['item_name'] ?? ('Item #' . (int) ($input['item_id'] ?? 0))) . ' x' . (int) ($input['quantity'] ?? 0);
    }

    private function requirementMatches(array $requirement, array $context, string $stationType = ''): bool
    {
        $type = (string) ($requirement['requirement_type'] ?? '');
        $value = trim((string) ($requirement['requirement_value'] ?? ''));
        if ($value === '') {
            return true;
        }

        if ($type === self::REQUIREMENT_PROFESSION) {
            if (ctype_digit($value)) {
                return in_array((int) $value, (array) ($context['profession_ids'] ?? []), true);
            }
            return in_array(strtolower($value), array_map('strtolower', (array) ($context['profession_codes'] ?? [])), true);
        }

        if ($type === self::REQUIREMENT_FACTION) {
            return in_array((int) $value, (array) ($context['faction_ids'] ?? []), true);
        }

        if ($type === self::REQUIREMENT_AREA) {
            return (int) $value > 0 && (int) $value === (int) ($context['area_id'] ?? 0);
        }

        if ($type === self::REQUIREMENT_EVENT) {
            return in_array((int) $value, (array) ($context['active_event_ids'] ?? []), true);
        }

        if ($type === self::REQUIREMENT_ITEM) {
            return $this->inventoryService()->getSellableQuantity((int) ($context['character_id'] ?? 0), (int) $value) > 0;
        }

        if ($type === self::REQUIREMENT_STATION) {
            return strtolower($stationType) === strtolower($value);
        }

        return true;
    }

    private function evaluateProcess(array $process, array $context, string $stationType = ''): array
    {
        $blockingReasons = [];
        $isExecutable = true;
        $visibility = (string) ($process['visibility'] ?? self::VIS_PUBLIC);

        if ((int) ($process['is_active'] ?? 0) !== 1) {
            $isExecutable = false;
            $blockingReasons[] = 'Processo non attivo.';
        }

        if ($visibility === self::VIS_HIDDEN) {
            $isExecutable = false;
            $blockingReasons[] = 'Processo nascosto.';
        }

        $effectiveStationType = $stationType !== '' ? $stationType : trim((string) ($process['station_type'] ?? ''));
        if (trim((string) ($process['station_type'] ?? '')) !== '' && $stationType === '') {
            $effectiveStationType = trim((string) ($process['station_type'] ?? ''));
        }

        foreach ((array) ($process['requirements'] ?? []) as $requirement) {
            $matched = $this->requirementMatches($requirement, $context, $effectiveStationType);
            $operator = (string) ($requirement['operator'] ?? self::OP_REQUIRED);
            if ($operator === self::OP_BLOCKED && $matched) {
                $isExecutable = false;
                $blockingReasons[] = $this->requirementReason($requirement);
                continue;
            }
            if (in_array($operator, [self::OP_REQUIRED, self::OP_ALLOWED], true) && !$matched) {
                $isExecutable = false;
                $blockingReasons[] = $this->requirementReason($requirement);
            }
        }

        $inputStatus = [];
        foreach ((array) ($process['inputs'] ?? []) as $input) {
            $available = $this->inventoryService()->getSellableQuantity((int) ($context['character_id'] ?? 0), (int) ($input['item_id'] ?? 0));
            $required = (int) ($input['quantity'] ?? 0);
            $ok = $available >= $required;
            $input['available_quantity'] = $available;
            $input['is_satisfied'] = $ok ? 1 : 0;
            $inputStatus[] = $input;
            if (!$ok) {
                $isExecutable = false;
                $blockingReasons[] = $this->inputReason($input);
            }
        }

        $process['inputs'] = $inputStatus;
        $process['is_executable'] = $isExecutable ? 1 : 0;
        $process['blocking_reasons'] = array_values(array_unique(array_filter($blockingReasons)));
        $process['explanation'] = $isExecutable
            ? 'Ricetta eseguibile: ' . $this->processSummaryLabel($process)
            : implode(' | ', $process['blocking_reasons']);

        return $process;
    }

    private function loadProcess(int $processId): array
    {
        $processes = $this->listProcessRows();
        foreach ($processes as $process) {
            if ((int) ($process['id'] ?? 0) === $processId) {
                return $process;
            }
        }

        throw AppError::validation('Processo non trovato.', [], 'crafting_process_not_found');
    }

    private function loadBagRowsForItem(int $characterId, int $itemId, int $requiredQty): array
    {
        $page = 1;
        $rows = [];
        $collected = 0;

        while ($collected < $requiredQty) {
            $result = $this->inventoryService()->listBag($characterId, $page, 200, 'name|ASC', (object) []);
            $dataset = is_array($result['dataset'] ?? null) ? $result['dataset'] : [];
            if (empty($dataset)) {
                break;
            }

            foreach ($dataset as $bagRow) {
                if ((int) ($bagRow->item_id ?? 0) !== $itemId) {
                    continue;
                }
                if ((int) ($bagRow->is_equipped ?? 0) === 1) {
                    continue;
                }
                $rows[] = $bagRow;
                $collected += max(1, (int) ($bagRow->quantity ?? 1));
                if ($collected >= $requiredQty) {
                    break;
                }
            }

            if (count($dataset) < 200) {
                break;
            }
            $page += 1;
        }

        usort($rows, static function ($left, $right): int {
            $leftSource = (string) ($left->source ?? '');
            $rightSource = (string) ($right->source ?? '');
            if ($leftSource === $rightSource) {
                return ((int) ($left->id ?? 0)) <=> ((int) ($right->id ?? 0));
            }
            if ($leftSource === 'stack') {
                return -1;
            }
            if ($rightSource === 'stack') {
                return 1;
            }
            return 0;
        });

        return $rows;
    }

    private function buildConsumptionPlan(int $characterId, array $inputs): array
    {
        $plan = [];
        foreach ($inputs as $input) {
            $requiredQty = (int) ($input['quantity'] ?? 0);
            if ($requiredQty <= 0 || (string) ($input['consume_mode'] ?? 'consume') !== 'consume') {
                continue;
            }

            $itemId = (int) ($input['item_id'] ?? 0);
            $itemName = (string) ($input['item_name'] ?? ('Item #' . $itemId));
            $rows = $this->loadBagRowsForItem($characterId, $itemId, $requiredQty);
            $remaining = $requiredQty;

            foreach ($rows as $row) {
                if ($remaining <= 0) {
                    break;
                }

                $source = (string) ($row->source ?? '');
                if ($source === 'stack') {
                    $available = max(0, (int) ($row->quantity ?? 0));
                    if ($available <= 0) {
                        continue;
                    }
                    $consumeQty = min($remaining, $available);
                    $plan[] = [
                        'item_id' => $itemId,
                        'item_name' => $itemName,
                        'quantity' => $consumeQty,
                        'character_item_id' => (int) ($row->character_item_id ?? 0),
                        'character_item_instance_id' => 0,
                    ];
                    $remaining -= $consumeQty;
                    continue;
                }

                $plan[] = [
                    'item_id' => $itemId,
                    'item_name' => $itemName,
                    'quantity' => 1,
                    'character_item_id' => 0,
                    'character_item_instance_id' => (int) ($row->character_item_instance_id ?? 0),
                ];
                $remaining -= 1;
            }

            if ($remaining > 0) {
                throw AppError::validation('Materiali insufficienti per ' . $itemName . '.', [], 'crafting_missing_inputs');
            }
        }

        return $plan;
    }

    private function validateOutputItems(array $outputs): void
    {
        $itemIds = [];
        foreach ($outputs as $output) {
            $itemId = (int) ($output['item_id'] ?? 0);
            if ($itemId > 0) {
                $itemIds[$itemId] = $itemId;
            }
        }

        $ids = array_values($itemIds);
        if (empty($ids) || !$this->hasTable('items')) {
            return;
        }

        $rows = $this->fetchPrepared(
            'SELECT id
             FROM items
             WHERE id IN (' . implode(',', $ids) . ')',
        );

        $found = [];
        foreach ($rows as $row) {
            $found[(int) ($row->id ?? 0)] = true;
        }

        foreach ($ids as $itemId) {
            if (!isset($found[$itemId])) {
                throw AppError::validation('Output con item non valido: #' . $itemId, [], 'crafting_invalid_output_item');
            }
        }
    }

    private function insertJob(int $processId, array $context, array $result, string $status): int
    {
        $this->execPrepared(
            'INSERT INTO production_jobs
                (process_id, character_id, faction_id, status, started_at, completed_at, context_snapshot, result_snapshot)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $processId,
                (int) ($context['character_id'] ?? 0),
                !empty($context['faction_ids']) ? (int) $context['faction_ids'][0] : null,
                $status,
                $this->now(),
                $status === 'completed' ? $this->now() : null,
                $this->jsonEncode($context),
                $this->jsonEncode($result),
            ],
        );

        return (int) $this->db->lastInsertId();
    }

    public function gameBootstrap(int $characterId): array
    {
        if (!$this->hasRuntimeArtifacts()) {
            return [
                'summary' => ['professions' => 0, 'available_processes' => 0, 'blocked_processes' => 0, 'sources' => 0],
                'professions' => [],
                'processes' => [],
                'sources' => [],
                'recent_jobs' => [],
            ];
        }

        $context = $this->getCharacterContext($characterId);
        $processes = [];
        foreach ($this->listProcessRows(true) as $process) {
            if ((string) ($process['visibility'] ?? self::VIS_PUBLIC) === self::VIS_HIDDEN) {
                continue;
            }
            $processes[] = $this->evaluateProcess($process, $context);
        }

        $sources = $this->availableSourcesForContext($context);
        $availableCount = 0;
        $blockedCount = 0;
        foreach ($processes as $process) {
            if ((int) ($process['is_executable'] ?? 0) === 1) {
                $availableCount += 1;
            } else {
                $blockedCount += 1;
            }
        }

        return [
            'summary' => [
                'professions' => count($context['professions']),
                'available_processes' => $availableCount,
                'blocked_processes' => $blockedCount,
                'sources' => count($sources),
            ],
            'professions' => $context['professions'],
            'processes' => $processes,
            'sources' => $sources,
            'recent_jobs' => $this->listRecentJobs($characterId),
        ];
    }

    public function executeProcess(int $processId, int $characterId, string $stationType = ''): array
    {
        $this->ensurePositiveId($processId, 'Processo non valido.', 'crafting_process_invalid');
        $context = $this->getCharacterContext($characterId);
        $process = $this->evaluateProcess($this->loadProcess($processId), $context, $stationType);

        if ((int) ($process['is_executable'] ?? 0) !== 1) {
            throw AppError::validation(
                $process['explanation'] !== '' ? (string) $process['explanation'] : 'Processo non eseguibile.',
                ['blocking_reasons' => $process['blocking_reasons'] ?? []],
                'crafting_process_blocked',
            );
        }

        $this->validateOutputItems((array) ($process['outputs'] ?? []));
        $consumptionPlan = $this->buildConsumptionPlan($characterId, (array) ($process['inputs'] ?? []));
        $consumedAggregate = [];
        $consumed = [];
        $produced = [];

        try {
            foreach ($consumptionPlan as $entry) {
                if ((int) ($entry['character_item_instance_id'] ?? 0) > 0) {
                    $this->inventoryService()->destroyItem($characterId, (object) [
                        'character_item_instance_id' => (int) $entry['character_item_instance_id'],
                    ]);
                } else {
                    $this->inventoryService()->destroyItem($characterId, (object) [
                        'character_item_id' => (int) ($entry['character_item_id'] ?? 0),
                        'quantity' => (int) ($entry['quantity'] ?? 1),
                    ]);
                }

                $itemId = (int) ($entry['item_id'] ?? 0);
                if (!isset($consumedAggregate[$itemId])) {
                    $consumedAggregate[$itemId] = 0;
                }
                $consumedAggregate[$itemId] += (int) ($entry['quantity'] ?? 1);
            }

            foreach ((array) ($process['inputs'] ?? []) as $input) {
                if ((string) ($input['consume_mode'] ?? 'consume') !== 'consume') {
                    continue;
                }
                $consumed[] = [
                    'item_id' => (int) ($input['item_id'] ?? 0),
                    'item_name' => (string) ($input['item_name'] ?? ('Item #' . (int) ($input['item_id'] ?? 0))),
                    'quantity' => (int) ($input['quantity'] ?? 0),
                ];
            }

            foreach ((array) ($process['outputs'] ?? []) as $output) {
                $this->inventoryService()->grantItemReward(
                    $characterId,
                    (int) ($output['item_id'] ?? 0),
                    (int) ($output['quantity'] ?? 1),
                );
                $produced[] = [
                    'item_id' => (int) ($output['item_id'] ?? 0),
                    'item_name' => (string) ($output['item_name'] ?? ('Item #' . (int) ($output['item_id'] ?? 0))),
                    'quantity' => (int) ($output['quantity'] ?? 0),
                    'output_mode' => (string) ($output['output_mode'] ?? 'create'),
                ];
            }

            $jobId = $this->insertJob(
                $processId,
                array_merge($context, ['station_type' => $stationType]),
                [
                    'process_name' => (string) ($process['name'] ?? ''),
                    'process_type' => (string) ($process['process_type'] ?? ''),
                    'consumed_inputs' => $consumed,
                    'produced_outputs' => $produced,
                    'economy_metadata' => [
                        'process_id' => $processId,
                        'process_type' => (string) ($process['process_type'] ?? ''),
                    ],
                ],
                'completed',
            );

            return [
                'job_id' => $jobId,
                'process_id' => $processId,
                'process_name' => (string) ($process['name'] ?? ''),
                'consumed_inputs' => $consumed,
                'produced_outputs' => $produced,
                'explanation' => 'Produzione completata: ' . $this->processSummaryLabel($process),
            ];
        } catch (\Throwable $error) {
            foreach ($consumedAggregate as $itemId => $quantity) {
                try {
                    $this->inventoryService()->grantItemReward($characterId, (int) $itemId, (int) $quantity);
                } catch (\Throwable $rollbackError) {
                }
            }

            try {
                $this->insertJob(
                    $processId,
                    array_merge($context, ['station_type' => $stationType]),
                    [
                        'process_name' => (string) ($process['name'] ?? ''),
                        'status' => 'cancelled',
                        'error' => $error->getMessage(),
                    ],
                    'cancelled',
                );
            } catch (\Throwable $logError) {
            }

            throw $error;
        }
    }
}
