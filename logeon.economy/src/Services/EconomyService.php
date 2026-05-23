<?php

declare(strict_types=1);

namespace Modules\Logeon\Economy\Services;

use App\Services\FactionProviderRegistry;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;
use Core\ModuleManager;

class EconomyService
{
    private const EFFECT_PRICE_PERCENT = 'price_percent_modifier';
    private const EFFECT_PRICE_FLAT = 'price_flat_modifier';
    private const EFFECT_AVAILABILITY = 'availability_override';
    private const EFFECT_STOCK_LIMIT = 'stock_limit';
    private const EFFECT_FACTION_ACCESS = 'faction_access';
    private const EFFECT_FACTION_PRICE = 'faction_price_modifier';
    private const EFFECT_LABEL = 'label';

    private const SCOPE_GLOBAL = 'global';
    private const SCOPE_AREA = 'area';
    private const SCOPE_SHOP = 'shop';
    private const SCOPE_FACTION = 'faction';
    private const SCOPE_EVENT = 'event';

    private const TARGET_ALL = 'all';
    private const TARGET_ITEM = 'item';
    private const TARGET_CATEGORY = 'category';

    /** @var DbAdapterInterface */
    private $db;
    /** @var ModuleManager|null */
    private $moduleManager = null;
    /** @var array<string,bool> */
    private $tableExists = [];

    public function __construct(DbAdapterInterface $db = null, ModuleManager $moduleManager = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->moduleManager = $moduleManager;
    }

    private function moduleManager(): ModuleManager
    {
        if ($this->moduleManager instanceof ModuleManager) {
            return $this->moduleManager;
        }

        $this->moduleManager = new ModuleManager($this->db);
        return $this->moduleManager;
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
        return $this->hasTable('economy_effects') && $this->hasTable('economy_effect_links');
    }

    private function normalizeText($value, int $max = 255): string
    {
        return mb_substr(trim((string) $value), 0, $max);
    }

    private function normalizeDateTime($value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            throw AppError::validation('Formato data/ora non valido.', [], 'economy_invalid_datetime');
        }

        return date('Y-m-d H:i:s', $timestamp);
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

    private function normalizeEffectType($value): string
    {
        $value = strtolower(trim((string) $value));
        $allowed = [
            self::EFFECT_PRICE_PERCENT,
            self::EFFECT_PRICE_FLAT,
            self::EFFECT_AVAILABILITY,
            self::EFFECT_STOCK_LIMIT,
            self::EFFECT_FACTION_ACCESS,
            self::EFFECT_FACTION_PRICE,
            self::EFFECT_LABEL,
        ];

        return in_array($value, $allowed, true) ? $value : self::EFFECT_LABEL;
    }

    private function normalizeScopeType($value): string
    {
        $value = strtolower(trim((string) $value));
        $allowed = [
            self::SCOPE_GLOBAL,
            self::SCOPE_AREA,
            self::SCOPE_SHOP,
            self::SCOPE_FACTION,
            self::SCOPE_EVENT,
        ];

        return in_array($value, $allowed, true) ? $value : self::SCOPE_GLOBAL;
    }

    private function normalizeTargetType($value): string
    {
        $value = strtolower(trim((string) $value));
        $allowed = [self::TARGET_ALL, self::TARGET_ITEM, self::TARGET_CATEGORY];
        return in_array($value, $allowed, true) ? $value : self::TARGET_ALL;
    }

    private function normalizeAvailabilityMode($value): string
    {
        $value = strtolower(trim((string) $value));
        $allowed = ['default', 'available', 'unavailable'];
        return in_array($value, $allowed, true) ? $value : 'default';
    }

    private function normalizeFactionAccessMode($value): string
    {
        $value = strtolower(trim((string) $value));
        $allowed = ['default', 'allowed', 'blocked'];
        return in_array($value, $allowed, true) ? $value : 'default';
    }

    private function normalizeIds($raw): array
    {
        if (is_object($raw)) {
            $raw = (array) $raw;
        }

        if (!is_array($raw)) {
            if ($raw === null || $raw === '') {
                return [];
            }
            $raw = preg_split('/[\s,;|]+/', (string) $raw) ?: [];
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

    private function ensurePositiveId(int $id, string $message, string $code): void
    {
        if ($id <= 0) {
            throw AppError::validation($message, [], $code);
        }
    }

    private function normalizePayload(object $data): array
    {
        $effectType = $this->normalizeEffectType($data->effect_type ?? self::EFFECT_LABEL);
        $scopeType = $this->normalizeScopeType($data->scope_type ?? self::SCOPE_GLOBAL);
        $targetType = $this->normalizeTargetType($data->target_type ?? self::TARGET_ALL);
        $targetRefId = (int) ($data->target_ref_id ?? 0);
        if ($targetType === self::TARGET_ALL) {
            $targetRefId = 0;
        }

        $name = $this->normalizeText($data->name ?? '', 120);
        $description = $this->normalizeText($data->description ?? '', 1000);
        $labelText = $this->normalizeText($data->label_text ?? '', 255);
        $pricePercentValue = (int) ($data->price_percent_value ?? 0);
        $priceFlatValue = (int) ($data->price_flat_value ?? 0);
        $stockLimitValue = (int) ($data->stock_limit_value ?? 0);
        $factionPricePercentValue = (int) ($data->faction_price_percent_value ?? 0);
        $priority = (int) ($data->priority ?? 100);
        $links = $this->normalizeIds($data->link_ids ?? []);

        if ($name === '') {
            throw AppError::validation('Il nome dell\'effetto e obbligatorio.', [], 'economy_name_required');
        }

        if ($scopeType !== self::SCOPE_GLOBAL && empty($links)) {
            throw AppError::validation('Seleziona almeno un collegamento per lo scope scelto.', [], 'economy_scope_link_required');
        }

        if ($targetType !== self::TARGET_ALL && $targetRefId <= 0) {
            throw AppError::validation('Seleziona un target valido per l\'effetto.', [], 'economy_target_required');
        }

        if (in_array($effectType, [self::EFFECT_PRICE_PERCENT, self::EFFECT_FACTION_PRICE], true)) {
            if ($pricePercentValue === 0 && $effectType === self::EFFECT_PRICE_PERCENT) {
                throw AppError::validation('Inserisci una percentuale diversa da zero.', [], 'economy_percent_required');
            }
            if ($factionPricePercentValue === 0 && $effectType === self::EFFECT_FACTION_PRICE) {
                throw AppError::validation('Inserisci una percentuale diversa da zero.', [], 'economy_faction_percent_required');
            }
        }

        if ($effectType === self::EFFECT_PRICE_FLAT && $priceFlatValue === 0) {
            throw AppError::validation('Inserisci un modificatore fisso diverso da zero.', [], 'economy_flat_required');
        }

        if ($effectType === self::EFFECT_STOCK_LIMIT && $stockLimitValue <= 0) {
            throw AppError::validation('Lo stock limit deve essere maggiore di zero.', [], 'economy_stock_limit_required');
        }

        if ($effectType === self::EFFECT_AVAILABILITY && $this->normalizeAvailabilityMode($data->availability_mode ?? '') === 'default') {
            throw AppError::validation('Seleziona una disponibilita valida.', [], 'economy_availability_required');
        }

        if ($effectType === self::EFFECT_FACTION_ACCESS && $this->normalizeFactionAccessMode($data->faction_access_mode ?? '') === 'default') {
            throw AppError::validation('Seleziona una regola di accesso fazione.', [], 'economy_faction_access_required');
        }

        if ($effectType === self::EFFECT_LABEL && $labelText === '') {
            throw AppError::validation('Inserisci il testo narrativo dell\'effetto.', [], 'economy_label_required');
        }

        $startAt = $this->normalizeDateTime($data->start_at ?? null);
        $endAt = $this->normalizeDateTime($data->end_at ?? null);
        if ($startAt !== null && $endAt !== null && $endAt <= $startAt) {
            throw AppError::validation('La fine effetto deve essere successiva all\'inizio.', [], 'economy_invalid_schedule');
        }

        return [
            'id' => (int) ($data->id ?? 0),
            'name' => $name,
            'description' => $description,
            'effect_type' => $effectType,
            'scope_type' => $scopeType,
            'target_type' => $targetType,
            'target_ref_id' => $targetRefId > 0 ? $targetRefId : null,
            'priority' => $priority,
            'is_active' => $this->normalizeBool($data->is_active ?? 1, true),
            'visible_to_players' => $this->normalizeBool($data->visible_to_players ?? 1, true),
            'price_percent_value' => $pricePercentValue,
            'price_flat_value' => $priceFlatValue,
            'availability_mode' => $this->normalizeAvailabilityMode($data->availability_mode ?? 'default'),
            'stock_limit_value' => $stockLimitValue > 0 ? $stockLimitValue : null,
            'faction_access_mode' => $this->normalizeFactionAccessMode($data->faction_access_mode ?? 'default'),
            'faction_price_percent_value' => $factionPricePercentValue,
            'label_text' => $labelText,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'meta_json' => json_encode([
                'notes' => $this->normalizeText($data->notes ?? '', 500),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'links' => $links,
        ];
    }

    private function listLinksForEffects(array $effectIds = []): array
    {
        if (empty($effectIds)) {
            $rows = $this->fetchPrepared(
                'SELECT id, effect_id, entity_type, entity_id
                 FROM economy_effect_links
                 ORDER BY entity_type ASC, entity_id ASC, id ASC',
            );
        } else {
            $ids = $this->normalizeIds($effectIds);
            if (empty($ids)) {
                return [];
            }
            $rows = $this->fetchPrepared(
                'SELECT id, effect_id, entity_type, entity_id
                 FROM economy_effect_links
                 WHERE effect_id IN (' . implode(',', $ids) . ')
                 ORDER BY entity_type ASC, entity_id ASC, id ASC',
            );
        }

        $out = [];
        foreach ($rows as $row) {
            $item = $this->rowToArray($row);
            $effectId = (int) ($item['effect_id'] ?? 0);
            if ($effectId <= 0) {
                continue;
            }
            if (!isset($out[$effectId])) {
                $out[$effectId] = [];
            }
            $out[$effectId][] = [
                'id' => (int) ($item['id'] ?? 0),
                'effect_id' => $effectId,
                'entity_type' => (string) ($item['entity_type'] ?? ''),
                'entity_id' => (int) ($item['entity_id'] ?? 0),
            ];
        }

        return $out;
    }

    private function decodeMetaJson($value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function labelEffectType(string $effectType): string
    {
        return match ($effectType) {
            self::EFFECT_PRICE_PERCENT => 'Prezzo %',
            self::EFFECT_PRICE_FLAT => 'Prezzo fisso',
            self::EFFECT_AVAILABILITY => 'Disponibilita',
            self::EFFECT_STOCK_LIMIT => 'Stock limit',
            self::EFFECT_FACTION_ACCESS => 'Accesso fazione',
            self::EFFECT_FACTION_PRICE => 'Prezzo fazione',
            self::EFFECT_LABEL => 'Etichetta',
            default => $effectType,
        };
    }

    private function labelScopeType(string $scopeType): string
    {
        return match ($scopeType) {
            self::SCOPE_GLOBAL => 'Globale',
            self::SCOPE_AREA => 'Area',
            self::SCOPE_SHOP => 'Shop',
            self::SCOPE_FACTION => 'Fazione',
            self::SCOPE_EVENT => 'Evento',
            default => $scopeType,
        };
    }

    private function labelTargetType(string $targetType): string
    {
        return match ($targetType) {
            self::TARGET_ALL => 'Tutti i beni',
            self::TARGET_ITEM => 'Item specifico',
            self::TARGET_CATEGORY => 'Categoria item',
            default => $targetType,
        };
    }

    private function describeEffect(array $effect): string
    {
        $type = (string) ($effect['effect_type'] ?? '');
        return match ($type) {
            self::EFFECT_PRICE_PERCENT => (($effect['price_percent_value'] ?? 0) >= 0 ? '+' : '') . (int) ($effect['price_percent_value'] ?? 0) . '% sul prezzo',
            self::EFFECT_PRICE_FLAT => (($effect['price_flat_value'] ?? 0) >= 0 ? '+' : '') . (int) ($effect['price_flat_value'] ?? 0) . ' fisso sul prezzo',
            self::EFFECT_AVAILABILITY => ((string) ($effect['availability_mode'] ?? '') === 'unavailable')
                ? 'Bene non disponibile'
                : 'Bene reso disponibile',
            self::EFFECT_STOCK_LIMIT => 'Disponibilita limitata a ' . (int) ($effect['stock_limit_value'] ?? 0),
            self::EFFECT_FACTION_ACCESS => ((string) ($effect['faction_access_mode'] ?? '') === 'blocked')
                ? 'Accesso bloccato alla fazione'
                : 'Accesso riservato alla fazione',
            self::EFFECT_FACTION_PRICE => (($effect['faction_price_percent_value'] ?? 0) >= 0 ? '+' : '') . (int) ($effect['faction_price_percent_value'] ?? 0) . '% per la fazione',
            self::EFFECT_LABEL => (string) ($effect['label_text'] ?? 'Etichetta narrativa'),
            default => 'Effetto economico',
        };
    }

    private function listReferenceMap(string $entityType): array
    {
        if ($entityType === self::SCOPE_SHOP && $this->hasTable('shops')) {
            $rows = $this->fetchPrepared(
                'SELECT s.id, s.name, s.type, l.name AS location_name
                 FROM shops s
                 LEFT JOIN locations l ON l.id = s.location_id
                 WHERE s.is_active = 1
                 ORDER BY s.name ASC, s.id ASC',
            );
            $out = [];
            foreach ($rows as $row) {
                $out[(int) ($row->id ?? 0)] = trim((string) ($row->name ?? 'Shop #' . (int) ($row->id ?? 0)) . ((string) ($row->location_name ?? '') !== '' ? ' · ' . (string) ($row->location_name ?? '') : ''));
            }
            return $out;
        }

        if ($entityType === self::SCOPE_AREA && $this->hasTable('locations')) {
            $rows = $this->fetchPrepared(
                'SELECT l.id, l.name, m.name AS map_name
                 FROM locations l
                 LEFT JOIN maps m ON m.id = l.map_id
                 WHERE l.date_deleted IS NULL
                 ORDER BY l.name ASC, l.id ASC',
            );
            $out = [];
            foreach ($rows as $row) {
                $out[(int) ($row->id ?? 0)] = trim((string) ($row->name ?? 'Area #' . (int) ($row->id ?? 0)) . ((string) ($row->map_name ?? '') !== '' ? ' · ' . (string) ($row->map_name ?? '') : ''));
            }
            return $out;
        }

        if ($entityType === self::SCOPE_FACTION && $this->moduleManager()->isActive('logeon.factions') && $this->hasTable('factions')) {
            $rows = $this->fetchPrepared(
                'SELECT id, name, code
                 FROM factions
                 WHERE is_active = 1
                 ORDER BY name ASC, id ASC',
            );
            $out = [];
            foreach ($rows as $row) {
                $out[(int) ($row->id ?? 0)] = trim((string) ($row->name ?? 'Fazione #' . (int) ($row->id ?? 0)) . ((string) ($row->code ?? '') !== '' ? ' · ' . (string) ($row->code ?? '') : ''));
            }
            return $out;
        }

        if ($entityType === self::SCOPE_EVENT) {
            $out = [];
            if ($this->hasTable('system_events')) {
                $rows = $this->fetchPrepared(
                    'SELECT id, title, type, status
                     FROM system_events
                     ORDER BY COALESCE(starts_at, date_created) DESC, id DESC
                     LIMIT 150',
                );
                foreach ($rows as $row) {
                    $out[(int) ($row->id ?? 0)] = '[SYS] ' . trim((string) ($row->title ?? 'Evento #' . (int) ($row->id ?? 0)));
                }
            }
            if ($this->hasTable('narrative_events')) {
                $rows = $this->fetchPrepared(
                    'SELECT id, title, event_type, status
                     FROM narrative_events
                     ORDER BY COALESCE(created_at, date_created) DESC, id DESC
                     LIMIT 150',
                );
                foreach ($rows as $row) {
                    $id = (int) ($row->id ?? 0);
                    if (!isset($out[$id])) {
                        $out[$id] = '[NAR] ' . trim((string) ($row->title ?? 'Evento #' . $id));
                    }
                }
            }
            return $out;
        }

        return [];
    }

    private function targetReferenceMap(string $targetType): array
    {
        if ($targetType === self::TARGET_ITEM && $this->hasTable('items')) {
            $rows = $this->fetchPrepared(
                'SELECT i.id, i.name, c.name AS category_name
                 FROM items i
                 LEFT JOIN item_categories c ON c.id = i.category_id
                 ORDER BY i.name ASC, i.id ASC
                 LIMIT 500',
            );
            $out = [];
            foreach ($rows as $row) {
                $out[(int) ($row->id ?? 0)] = trim((string) ($row->name ?? 'Item #' . (int) ($row->id ?? 0)) . ((string) ($row->category_name ?? '') !== '' ? ' · ' . (string) ($row->category_name ?? '') : ''));
            }
            return $out;
        }

        if ($targetType === self::TARGET_CATEGORY && $this->hasTable('item_categories')) {
            $rows = $this->fetchPrepared(
                'SELECT id, name
                 FROM item_categories
                 ORDER BY sort_order ASC, name ASC, id ASC',
            );
            $out = [];
            foreach ($rows as $row) {
                $out[(int) ($row->id ?? 0)] = (string) ($row->name ?? 'Categoria #' . (int) ($row->id ?? 0));
            }
            return $out;
        }

        return [];
    }

    private function listEffects(bool $onlyActive = false): array
    {
        if (!$this->hasRuntimeArtifacts()) {
            return [];
        }

        $sql = 'SELECT *
                FROM economy_effects';
        $params = [];
        if ($onlyActive) {
            $sql .= ' WHERE is_active = 1
                      AND (start_at IS NULL OR start_at <= ?)
                      AND (end_at IS NULL OR end_at >= ?)';
            $params[] = $this->now();
            $params[] = $this->now();
        }
        $sql .= ' ORDER BY scope_type ASC, priority ASC, id ASC';

        $rows = $this->fetchPrepared($sql, $params);
        $effectIds = [];
        foreach ($rows as $row) {
            $effectIds[] = (int) ($row->id ?? 0);
        }
        $linksMap = $this->listLinksForEffects($effectIds);
        $referenceMaps = [
            self::SCOPE_SHOP => $this->listReferenceMap(self::SCOPE_SHOP),
            self::SCOPE_AREA => $this->listReferenceMap(self::SCOPE_AREA),
            self::SCOPE_FACTION => $this->listReferenceMap(self::SCOPE_FACTION),
            self::SCOPE_EVENT => $this->listReferenceMap(self::SCOPE_EVENT),
            self::TARGET_ITEM => $this->targetReferenceMap(self::TARGET_ITEM),
            self::TARGET_CATEGORY => $this->targetReferenceMap(self::TARGET_CATEGORY),
        ];

        $out = [];
        foreach ($rows as $row) {
            $item = $this->rowToArray($row);
            $item['id'] = (int) ($item['id'] ?? 0);
            $item['priority'] = (int) ($item['priority'] ?? 100);
            $item['is_active'] = (int) ($item['is_active'] ?? 0);
            $item['visible_to_players'] = (int) ($item['visible_to_players'] ?? 0);
            $item['price_percent_value'] = (int) ($item['price_percent_value'] ?? 0);
            $item['price_flat_value'] = (int) ($item['price_flat_value'] ?? 0);
            $item['stock_limit_value'] = (int) ($item['stock_limit_value'] ?? 0);
            $item['faction_price_percent_value'] = (int) ($item['faction_price_percent_value'] ?? 0);
            $item['meta_json'] = $this->decodeMetaJson($item['meta_json'] ?? null);
            $item['effect_type_label'] = $this->labelEffectType((string) ($item['effect_type'] ?? ''));
            $item['scope_type_label'] = $this->labelScopeType((string) ($item['scope_type'] ?? ''));
            $item['target_type_label'] = $this->labelTargetType((string) ($item['target_type'] ?? ''));
            $item['target_label'] = 'Tutti i beni';
            if ((string) ($item['target_type'] ?? '') === self::TARGET_ITEM) {
                $item['target_label'] = $referenceMaps[self::TARGET_ITEM][(int) ($item['target_ref_id'] ?? 0)] ?? ('Item #' . (int) ($item['target_ref_id'] ?? 0));
            } elseif ((string) ($item['target_type'] ?? '') === self::TARGET_CATEGORY) {
                $item['target_label'] = $referenceMaps[self::TARGET_CATEGORY][(int) ($item['target_ref_id'] ?? 0)] ?? ('Categoria #' . (int) ($item['target_ref_id'] ?? 0));
            }
            $item['summary_label'] = $this->describeEffect($item);
            $item['links'] = $linksMap[$item['id']] ?? [];
            $item['link_labels'] = [];
            foreach ($item['links'] as $link) {
                $scope = (string) ($link['entity_type'] ?? '');
                $entityId = (int) ($link['entity_id'] ?? 0);
                $item['link_labels'][] = $referenceMaps[$scope][$entityId] ?? ($this->labelScopeType($scope) . ' #' . $entityId);
            }
            $out[] = $item;
        }

        return $out;
    }

    public function adminBootstrap(): array
    {
        $effects = $this->listEffects(false);

        return [
            'summary' => [
                'total' => count($effects),
                'active' => count(array_filter($effects, static function (array $row): bool {
                    return (int) ($row['is_active'] ?? 0) === 1;
                })),
                'scheduled' => count(array_filter($effects, function (array $row): bool {
                    $startAt = trim((string) ($row['start_at'] ?? ''));
                    return $startAt !== '' && $startAt > $this->now();
                })),
            ],
            'effects' => $effects,
            'scope_options' => [
                ['value' => self::SCOPE_GLOBAL, 'label' => 'Globale'],
                ['value' => self::SCOPE_AREA, 'label' => 'Area'],
                ['value' => self::SCOPE_SHOP, 'label' => 'Shop'],
                ['value' => self::SCOPE_FACTION, 'label' => 'Fazione'],
                ['value' => self::SCOPE_EVENT, 'label' => 'Evento'],
            ],
            'effect_type_options' => [
                ['value' => self::EFFECT_PRICE_PERCENT, 'label' => 'PRICE_PERCENT_MODIFIER'],
                ['value' => self::EFFECT_PRICE_FLAT, 'label' => 'PRICE_FLAT_MODIFIER'],
                ['value' => self::EFFECT_AVAILABILITY, 'label' => 'AVAILABILITY_OVERRIDE'],
                ['value' => self::EFFECT_STOCK_LIMIT, 'label' => 'STOCK_LIMIT'],
                ['value' => self::EFFECT_FACTION_ACCESS, 'label' => 'FACTION_ACCESS'],
                ['value' => self::EFFECT_FACTION_PRICE, 'label' => 'FACTION_PRICE_MODIFIER'],
                ['value' => self::EFFECT_LABEL, 'label' => 'LABEL'],
            ],
            'target_type_options' => [
                ['value' => self::TARGET_ALL, 'label' => 'Tutti i beni'],
                ['value' => self::TARGET_ITEM, 'label' => 'Item specifico'],
                ['value' => self::TARGET_CATEGORY, 'label' => 'Categoria item'],
            ],
            'reference_options' => [
                'shops' => $this->listReferenceMap(self::SCOPE_SHOP),
                'areas' => $this->listReferenceMap(self::SCOPE_AREA),
                'factions' => $this->listReferenceMap(self::SCOPE_FACTION),
                'events' => $this->listReferenceMap(self::SCOPE_EVENT),
                'items' => $this->targetReferenceMap(self::TARGET_ITEM),
                'categories' => $this->targetReferenceMap(self::TARGET_CATEGORY),
            ],
            'dependencies' => [
                'factions_active' => $this->moduleManager()->isActive('logeon.factions') ? 1 : 0,
                'multi_currency_active' => $this->moduleManager()->isActive('logeon.multi-currency') ? 1 : 0,
                'quests_active' => $this->moduleManager()->isActive('logeon.quests') ? 1 : 0,
                'social_status_active' => $this->moduleManager()->isActive('logeon.social-status') ? 1 : 0,
            ],
        ];
    }

    public function saveEffect(object $data, int $userId): int
    {
        if (!$this->hasRuntimeArtifacts()) {
            throw AppError::validation('Le tabelle del modulo economia non sono disponibili.', [], 'economy_storage_missing');
        }

        $payload = $this->normalizePayload($data);
        $id = (int) ($payload['id'] ?? 0);

        $this->db->query('START TRANSACTION');
        try {
            if ($id > 0) {
                $this->ensurePositiveId($id, 'Effetto non valido.', 'economy_effect_required');
                $existing = $this->firstPrepared('SELECT id FROM economy_effects WHERE id = ? LIMIT 1', [$id]);
                if (empty($existing)) {
                    throw AppError::notFound('Effetto economico non trovato.', [], 'economy_effect_not_found');
                }

                $this->execPrepared(
                    'UPDATE economy_effects
                     SET name = ?,
                         description = ?,
                         effect_type = ?,
                         scope_type = ?,
                         target_type = ?,
                         target_ref_id = ?,
                         priority = ?,
                         is_active = ?,
                         visible_to_players = ?,
                         price_percent_value = ?,
                         price_flat_value = ?,
                         availability_mode = ?,
                         stock_limit_value = ?,
                         faction_access_mode = ?,
                         faction_price_percent_value = ?,
                         label_text = ?,
                         start_at = ?,
                         end_at = ?,
                         meta_json = ?,
                         updated_by = ?,
                         date_updated = NOW()
                     WHERE id = ?
                     LIMIT 1',
                    [
                        $payload['name'],
                        $payload['description'] !== '' ? $payload['description'] : null,
                        $payload['effect_type'],
                        $payload['scope_type'],
                        $payload['target_type'],
                        $payload['target_ref_id'],
                        $payload['priority'],
                        $payload['is_active'],
                        $payload['visible_to_players'],
                        $payload['price_percent_value'],
                        $payload['price_flat_value'],
                        $payload['availability_mode'],
                        $payload['stock_limit_value'],
                        $payload['faction_access_mode'],
                        $payload['faction_price_percent_value'],
                        $payload['label_text'] !== '' ? $payload['label_text'] : null,
                        $payload['start_at'],
                        $payload['end_at'],
                        $payload['meta_json'],
                        $userId > 0 ? $userId : null,
                        $id,
                    ],
                );
                $effectId = $id;
            } else {
                $this->execPrepared(
                    'INSERT INTO economy_effects
                        (name, description, effect_type, scope_type, target_type, target_ref_id, priority, is_active, visible_to_players,
                         price_percent_value, price_flat_value, availability_mode, stock_limit_value, faction_access_mode,
                         faction_price_percent_value, label_text, start_at, end_at, meta_json, created_by, updated_by, date_created, date_updated)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                    [
                        $payload['name'],
                        $payload['description'] !== '' ? $payload['description'] : null,
                        $payload['effect_type'],
                        $payload['scope_type'],
                        $payload['target_type'],
                        $payload['target_ref_id'],
                        $payload['priority'],
                        $payload['is_active'],
                        $payload['visible_to_players'],
                        $payload['price_percent_value'],
                        $payload['price_flat_value'],
                        $payload['availability_mode'],
                        $payload['stock_limit_value'],
                        $payload['faction_access_mode'],
                        $payload['faction_price_percent_value'],
                        $payload['label_text'] !== '' ? $payload['label_text'] : null,
                        $payload['start_at'],
                        $payload['end_at'],
                        $payload['meta_json'],
                        $userId > 0 ? $userId : null,
                        $userId > 0 ? $userId : null,
                    ],
                );
                $effectId = (int) $this->db->lastInsertId();
            }

            $this->execPrepared('DELETE FROM economy_effect_links WHERE effect_id = ?', [$effectId]);
            foreach ($payload['links'] as $entityId) {
                $this->execPrepared(
                    'INSERT INTO economy_effect_links
                        (effect_id, entity_type, entity_id, date_created)
                     VALUES (?, ?, ?, NOW())',
                    [$effectId, $payload['scope_type'], $entityId],
                );
            }

            $this->db->query('COMMIT');
            return $effectId;
        } catch (\Throwable $error) {
            try {
                $this->db->query('ROLLBACK');
            } catch (\Throwable $rollbackError) {
            }
            throw $error;
        }
    }

    public function deleteEffect(int $effectId): void
    {
        if (!$this->hasRuntimeArtifacts()) {
            return;
        }

        $this->ensurePositiveId($effectId, 'Effetto non valido.', 'economy_effect_required');
        $row = $this->firstPrepared('SELECT id FROM economy_effects WHERE id = ? LIMIT 1', [$effectId]);
        if (empty($row)) {
            throw AppError::notFound('Effetto economico non trovato.', [], 'economy_effect_not_found');
        }

        $this->execPrepared('DELETE FROM economy_effects WHERE id = ? LIMIT 1', [$effectId]);
    }

    public function previewEffectDraft(object $data): array
    {
        $payload = $this->normalizePayload($data);
        $scopeLabels = $this->listReferenceMap($payload['scope_type']);
        $targetLabels = $this->targetReferenceMap($payload['target_type']);

        $links = [];
        foreach ($payload['links'] as $linkId) {
            $links[] = $scopeLabels[$linkId] ?? ($this->labelScopeType($payload['scope_type']) . ' #' . $linkId);
        }

        $targetLabel = $this->labelTargetType($payload['target_type']);
        if ($payload['target_type'] === self::TARGET_ITEM) {
            $targetLabel = $targetLabels[(int) ($payload['target_ref_id'] ?? 0)] ?? ('Item #' . (int) ($payload['target_ref_id'] ?? 0));
        } elseif ($payload['target_type'] === self::TARGET_CATEGORY) {
            $targetLabel = $targetLabels[(int) ($payload['target_ref_id'] ?? 0)] ?? ('Categoria #' . (int) ($payload['target_ref_id'] ?? 0));
        }

        return [
            'name' => $payload['name'],
            'effect_type' => $payload['effect_type'],
            'effect_type_label' => $this->labelEffectType($payload['effect_type']),
            'scope_type' => $payload['scope_type'],
            'scope_type_label' => $this->labelScopeType($payload['scope_type']),
            'target_label' => $targetLabel,
            'links' => $links,
            'summary_label' => $this->describeEffect($payload),
            'player_visibility' => (int) ($payload['visible_to_players'] ?? 0) === 1 ? 'Visibile ai giocatori' : 'Solo contesto staff/sistema',
            'schedule_label' => ($payload['start_at'] || $payload['end_at'])
                ? trim('Da ' . ($payload['start_at'] ?: 'subito') . ' a ' . ($payload['end_at'] ?: 'fino a disattivazione'))
                : 'Sempre attivo se abilitato',
        ];
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

    private function normalizeRuntimeItem(array $item): array
    {
        $itemId = (int) ($item['item_id'] ?? ($item['id'] ?? 0));
        $categoryId = (int) ($item['category_id'] ?? 0);

        if ($itemId > 0 && $categoryId <= 0 && $this->hasTable('items')) {
            $row = $this->firstPrepared(
                'SELECT id, category_id, name
                 FROM items
                 WHERE id = ?
                 LIMIT 1',
                [$itemId],
            );
            if (!empty($row)) {
                $categoryId = (int) ($row->category_id ?? 0);
                if (empty($item['name']) && isset($row->name)) {
                    $item['name'] = (string) $row->name;
                }
            }
        }

        $item['item_id'] = $itemId;
        $item['category_id'] = $categoryId;
        $item['shop_item_id'] = (int) ($item['shop_item_id'] ?? $item['id'] ?? 0);
        $item['price'] = (int) ($item['price'] ?? 0);
        $item['max_purchase'] = array_key_exists('max_purchase', $item) && $item['max_purchase'] !== null
            ? (int) $item['max_purchase']
            : null;

        return $item;
    }

    private function buildRuntimeContext(array $payload): array
    {
        $shop = $this->rowToArray($payload['shop'] ?? []);
        $item = $this->normalizeRuntimeItem($this->rowToArray($payload['item'] ?? $payload['shop_item'] ?? []));
        $characterId = (int) ($payload['character_id'] ?? 0);
        $shopId = (int) ($shop['id'] ?? ($item['shop_id'] ?? 0));
        $areaId = (int) ($shop['location_id'] ?? 0);

        return [
            'character_id' => $characterId,
            'shop' => $shop,
            'shop_id' => $shopId,
            'area_id' => $areaId,
            'item' => $item,
            'item_id' => (int) ($item['item_id'] ?? 0),
            'category_id' => (int) ($item['category_id'] ?? 0),
            'quantity' => max(1, (int) ($payload['quantity'] ?? 1)),
            'unit_price' => max(0, (int) ($payload['unit_price'] ?? ($item['price'] ?? 0))),
            'faction_ids' => $this->getActiveFactionIdsForCharacter($characterId),
            'active_event_ids' => $this->getActiveEventIds(),
        ];
    }

    private function effectMatchesTarget(array $effect, array $context): bool
    {
        $targetType = (string) ($effect['target_type'] ?? self::TARGET_ALL);
        $targetRefId = (int) ($effect['target_ref_id'] ?? 0);
        if ($targetType === self::TARGET_ALL) {
            return true;
        }
        if ($targetType === self::TARGET_ITEM) {
            return $targetRefId > 0 && $targetRefId === (int) ($context['item_id'] ?? 0);
        }
        if ($targetType === self::TARGET_CATEGORY) {
            return $targetRefId > 0 && $targetRefId === (int) ($context['category_id'] ?? 0);
        }

        return false;
    }

    private function effectMatchesScope(array $effect, array $links, array $context): bool
    {
        $scopeType = (string) ($effect['scope_type'] ?? self::SCOPE_GLOBAL);
        if ($scopeType === self::SCOPE_GLOBAL) {
            return true;
        }

        if (empty($links)) {
            return false;
        }

        $linkIds = [];
        foreach ($links as $link) {
            $entityId = (int) ($link['entity_id'] ?? 0);
            if ($entityId > 0) {
                $linkIds[$entityId] = $entityId;
            }
        }

        if ($scopeType === self::SCOPE_AREA) {
            return isset($linkIds[(int) ($context['area_id'] ?? 0)]);
        }
        if ($scopeType === self::SCOPE_SHOP) {
            return isset($linkIds[(int) ($context['shop_id'] ?? 0)]);
        }
        if ($scopeType === self::SCOPE_FACTION) {
            foreach ((array) ($context['faction_ids'] ?? []) as $factionId) {
                if (isset($linkIds[(int) $factionId])) {
                    return true;
                }
            }
            return false;
        }
        if ($scopeType === self::SCOPE_EVENT) {
            foreach ((array) ($context['active_event_ids'] ?? []) as $eventId) {
                if (isset($linkIds[(int) $eventId])) {
                    return true;
                }
            }
            return false;
        }

        return false;
    }

    private function scopeRank(string $scopeType): int
    {
        return match ($scopeType) {
            self::SCOPE_GLOBAL => 10,
            self::SCOPE_AREA => 20,
            self::SCOPE_SHOP => 30,
            self::SCOPE_FACTION => 40,
            self::SCOPE_EVENT => 50,
            default => 90,
        };
    }

    private function activeEffectsForContext(array $context): array
    {
        $effects = $this->listEffects(true);
        $out = [];
        foreach ($effects as $effect) {
            $links = is_array($effect['links'] ?? null) ? $effect['links'] : [];
            if (!$this->effectMatchesTarget($effect, $context)) {
                continue;
            }
            if (!$this->effectMatchesScope($effect, $links, $context)) {
                continue;
            }
            $out[] = $effect;
        }

        usort($out, function (array $left, array $right): int {
            $scopeOrder = $this->scopeRank((string) ($left['scope_type'] ?? '')) <=> $this->scopeRank((string) ($right['scope_type'] ?? ''));
            if ($scopeOrder !== 0) {
                return $scopeOrder;
            }
            $priorityOrder = ((int) ($left['priority'] ?? 100)) <=> ((int) ($right['priority'] ?? 100));
            if ($priorityOrder !== 0) {
                return $priorityOrder;
            }
            return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
        });

        return $out;
    }

    private function resolveContext(array $context): array
    {
        $effects = $this->activeEffectsForContext($context);
        $unitPrice = max(0, (int) ($context['unit_price'] ?? 0));
        $maxQuantity = null;
        $isAvailable = true;
        $availabilityReason = '';
        $priceExplanations = [];
        $labels = [];
        $meta = ['effect_ids' => []];

        foreach ($effects as $effect) {
            $effectId = (int) ($effect['id'] ?? 0);
            if ($effectId > 0) {
                $meta['effect_ids'][] = $effectId;
            }

            $type = (string) ($effect['effect_type'] ?? '');
            $summary = $this->describeEffect($effect);
            $visible = (int) ($effect['visible_to_players'] ?? 0) === 1;

            if ($type === self::EFFECT_PRICE_PERCENT) {
                $percent = (int) ($effect['price_percent_value'] ?? 0);
                if ($percent !== 0) {
                    $delta = (int) floor(($unitPrice * $percent) / 100);
                    $unitPrice = max(0, $unitPrice + $delta);
                    if ($visible) {
                        $priceExplanations[] = $summary;
                    }
                }
                continue;
            }

            if ($type === self::EFFECT_PRICE_FLAT) {
                $flat = (int) ($effect['price_flat_value'] ?? 0);
                if ($flat !== 0) {
                    $unitPrice = max(0, $unitPrice + $flat);
                    if ($visible) {
                        $priceExplanations[] = $summary;
                    }
                }
                continue;
            }

            if ($type === self::EFFECT_FACTION_PRICE) {
                $percent = (int) ($effect['faction_price_percent_value'] ?? 0);
                if ($percent !== 0) {
                    $delta = (int) floor(($unitPrice * $percent) / 100);
                    $unitPrice = max(0, $unitPrice + $delta);
                    if ($visible) {
                        $priceExplanations[] = $summary;
                    }
                }
                continue;
            }

            if ($type === self::EFFECT_STOCK_LIMIT) {
                $limit = (int) ($effect['stock_limit_value'] ?? 0);
                if ($limit > 0) {
                    $maxQuantity = ($maxQuantity === null) ? $limit : min($maxQuantity, $limit);
                    if ($visible) {
                        $labels[] = 'Disponibilita limitata';
                        $priceExplanations[] = $summary;
                    }
                }
                continue;
            }

            if ($type === self::EFFECT_AVAILABILITY) {
                $mode = (string) ($effect['availability_mode'] ?? 'default');
                if ($mode === 'unavailable') {
                    $isAvailable = false;
                    $maxQuantity = 0;
                    if ($visible) {
                        $availabilityReason = $summary;
                    }
                } elseif ($mode === 'available') {
                    $isAvailable = true;
                    if ($visible) {
                        $labels[] = 'Disponibile per contesto';
                    }
                }
                continue;
            }

            if ($type === self::EFFECT_FACTION_ACCESS) {
                $mode = (string) ($effect['faction_access_mode'] ?? 'default');
                if ($mode === 'blocked') {
                    $isAvailable = false;
                    $maxQuantity = 0;
                    if ($visible) {
                        $availabilityReason = $summary;
                    }
                } elseif ($mode === 'allowed') {
                    $isAvailable = true;
                    if ($visible) {
                        $labels[] = 'Accesso di fazione';
                    }
                }
                continue;
            }

            if ($type === self::EFFECT_LABEL) {
                $label = trim((string) ($effect['label_text'] ?? ''));
                if ($visible && $label !== '') {
                    $labels[] = $label;
                }
            }
        }

        if ($maxQuantity !== null && $maxQuantity <= 0) {
            $isAvailable = false;
        }

        $meta['effect_ids'] = array_values(array_unique(array_map('intval', $meta['effect_ids'])));

        return [
            'unit_price' => $unitPrice,
            'is_available' => $isAvailable ? 1 : 0,
            'max_quantity' => $maxQuantity,
            'availability_reason' => $availabilityReason,
            'price_explanation' => implode(' | ', array_values(array_unique(array_filter($priceExplanations)))),
            'labels' => array_values(array_unique(array_filter($labels))),
            'meta' => $meta,
        ];
    }

    public function filterCatalogItem($payload)
    {
        if (!$this->hasRuntimeArtifacts() || !is_array($payload)) {
            return $payload;
        }

        $context = $this->buildRuntimeContext($payload);
        if ((int) ($context['item_id'] ?? 0) <= 0 || (int) ($context['shop_id'] ?? 0) <= 0) {
            return $payload;
        }

        $resolution = $this->resolveContext($context);
        $item = $this->rowToArray($payload['item'] ?? []);
        $item['price'] = (int) ($resolution['unit_price'] ?? ($item['price'] ?? 0));
        $item['is_available'] = (int) ($resolution['is_available'] ?? 1);
        $item['availability_reason'] = (string) ($resolution['availability_reason'] ?? '');
        $item['price_explanation'] = (string) ($resolution['price_explanation'] ?? '');
        $item['runtime_labels'] = $resolution['labels'] ?? [];
        $item['runtime_meta'] = $resolution['meta'] ?? [];

        $currentMax = array_key_exists('max_purchase', $item) && $item['max_purchase'] !== null ? (int) $item['max_purchase'] : null;
        $resolvedMax = $resolution['max_quantity'] ?? null;
        if ($resolvedMax !== null) {
            $item['max_purchase'] = ($currentMax === null) ? (int) $resolvedMax : min($currentMax, (int) $resolvedMax);
        }
        if ((int) $item['is_available'] !== 1) {
            $item['max_purchase'] = 0;
        }

        $payload['item'] = $item;
        $payload['is_available'] = (int) $item['is_available'] === 1;
        $payload['max_quantity'] = $item['max_purchase'] ?? null;
        $payload['availability_reason'] = $item['availability_reason'];
        $payload['price_explanation'] = $item['price_explanation'];
        $payload['labels'] = $item['runtime_labels'];
        $payload['meta'] = $item['runtime_meta'];

        return $payload;
    }

    public function resolvePurchasePayload($payload)
    {
        if (!$this->hasRuntimeArtifacts() || !is_array($payload)) {
            return $payload;
        }

        $context = $this->buildRuntimeContext($payload);
        if ((int) ($context['item_id'] ?? 0) <= 0 || (int) ($context['shop_id'] ?? 0) <= 0) {
            return $payload;
        }

        $resolution = $this->resolveContext($context);
        $payload['unit_price'] = (int) ($resolution['unit_price'] ?? ($payload['unit_price'] ?? 0));
        $payload['is_available'] = (int) ($resolution['is_available'] ?? 1) === 1;
        $payload['availability_reason'] = (string) ($resolution['availability_reason'] ?? '');
        $payload['price_explanation'] = (string) ($resolution['price_explanation'] ?? '');
        $payload['labels'] = $resolution['labels'] ?? [];
        $payload['meta'] = $resolution['meta'] ?? [];
        if (($resolution['max_quantity'] ?? null) !== null) {
            $payload['max_quantity'] = (int) $resolution['max_quantity'];
        }

        if (empty($payload['is_available'])) {
            $payload['error_code'] = 'shop_item_unavailable';
            $payload['error_message'] = $payload['availability_reason'] !== ''
                ? $payload['availability_reason']
                : 'Oggetto non disponibile nel contesto economico attuale.';
        } elseif (isset($payload['max_quantity']) && (int) $payload['max_quantity'] > 0 && (int) ($payload['quantity'] ?? 1) > (int) $payload['max_quantity']) {
            $payload['error_code'] = 'quantity_invalid';
            $payload['error_message'] = 'La quantita richiesta supera la disponibilita contestuale di questo bene.';
        }

        return $payload;
    }

    public function resolveSellPayload($payload)
    {
        if (!$this->hasRuntimeArtifacts() || !is_array($payload)) {
            return $payload;
        }

        $item = $this->normalizeRuntimeItem($this->rowToArray($payload['item'] ?? []));
        if ((int) ($item['item_id'] ?? 0) <= 0) {
            return $payload;
        }

        $context = [
            'character_id' => (int) ($payload['character_id'] ?? 0),
            'shop' => $this->rowToArray($payload['shop'] ?? []),
            'shop_id' => (int) (($payload['shop']['id'] ?? 0)),
            'area_id' => (int) (($payload['shop']['location_id'] ?? 0)),
            'item' => $item,
            'item_id' => (int) ($item['item_id'] ?? 0),
            'category_id' => (int) ($item['category_id'] ?? 0),
            'quantity' => max(1, (int) ($payload['quantity'] ?? 1)),
            'unit_price' => max(0, (int) ($payload['unit_price'] ?? 0)),
            'faction_ids' => $this->getActiveFactionIdsForCharacter((int) ($payload['character_id'] ?? 0)),
            'active_event_ids' => $this->getActiveEventIds(),
        ];

        $effects = $this->activeEffectsForContext($context);
        $unitPrice = max(0, (int) ($payload['unit_price'] ?? 0));
        $labels = [];
        $priceExplanations = [];
        $meta = ['effect_ids' => []];

        foreach ($effects as $effect) {
            $effectId = (int) ($effect['id'] ?? 0);
            if ($effectId > 0) {
                $meta['effect_ids'][] = $effectId;
            }
            $visible = (int) ($effect['visible_to_players'] ?? 0) === 1;
            if ((string) ($effect['effect_type'] ?? '') === self::EFFECT_LABEL && $visible) {
                $label = trim((string) ($effect['label_text'] ?? ''));
                if ($label !== '') {
                    $labels[] = $label;
                }
            }
            if ((string) ($effect['effect_type'] ?? '') === self::EFFECT_PRICE_FLAT) {
                $flat = (int) ($effect['price_flat_value'] ?? 0);
                if ($flat !== 0) {
                    $unitPrice = max(0, $unitPrice + $flat);
                    if ($visible) {
                        $priceExplanations[] = $this->describeEffect($effect);
                    }
                }
            }
        }

        $payload['unit_price'] = $unitPrice;
        $payload['price_explanation'] = implode(' | ', array_values(array_unique(array_filter($priceExplanations))));
        $payload['labels'] = array_values(array_unique(array_filter($labels)));
        $payload['meta'] = [
            'effect_ids' => array_values(array_unique(array_map('intval', $meta['effect_ids']))),
        ];

        return $payload;
    }
}
