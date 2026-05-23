<?php

declare(strict_types=1);

namespace Modules\Logeon\AdvancedItems\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class AdvancedItemsService
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var bool|null */
    private $narrativeStateTablesAvailable = null;
    /** @var object|null|false */
    private $narrativeStateRuntime = null;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
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

    private function failValidation(string $message, string $errorCode = 'validation_error'): void
    {
        throw AppError::validation($message, [], $errorCode);
    }

    private function normalizeText($value): string
    {
        return trim((string) $value);
    }

    private function normalizeSlug($value, string $fallback = ''): string
    {
        $candidate = strtolower(trim((string) $value));
        if ($candidate === '' && $fallback !== '') {
            $candidate = strtolower(trim($fallback));
        }
        $candidate = (string) preg_replace('/[^a-z0-9]+/i', '-', $candidate);
        return trim($candidate, '-');
    }

    private function normalizeEnum($value, array $allowed, string $fallback): string
    {
        $candidate = strtolower(trim((string) $value));
        return in_array($candidate, $allowed, true) ? $candidate : $fallback;
    }

    private function normalizeInt($value, int $default = 0): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        return (int) $value;
    }

    private function normalizeFloat($value, float $default = 0.0): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $raw = str_replace(',', '.', trim((string) $value));
        if ($raw === '' || !is_numeric($raw)) {
            return $default;
        }

        return (float) $raw;
    }

    private function normalizeBool($value, int $default = 0): int
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

    private function normalizeNullableInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $numeric = (int) $value;
        return $numeric > 0 ? $numeric : null;
    }

    private function limitValue(int $value, int $min, int $max): int
    {
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }
        return $value;
    }

    private function hasNarrativeStateTables(): bool
    {
        if ($this->narrativeStateTablesAvailable !== null) {
            return $this->narrativeStateTablesAvailable;
        }

        try {
            $this->firstPrepared('SELECT id FROM narrative_states LIMIT 1');
            $this->firstPrepared('SELECT id FROM applied_narrative_states LIMIT 1');
            $this->narrativeStateTablesAvailable = true;
        } catch (\Throwable) {
            $this->narrativeStateTablesAvailable = false;
        }

        return $this->narrativeStateTablesAvailable;
    }

    private function narrativeStateRuntime()
    {
        if ($this->narrativeStateRuntime !== null) {
            return $this->narrativeStateRuntime ?: null;
        }

        if (!$this->hasNarrativeStateTables() || !class_exists('\\App\\Services\\NarrativeStateApplicationService')) {
            $this->narrativeStateRuntime = false;
            return null;
        }

        try {
            $runtime = new \App\Services\NarrativeStateApplicationService($this->db);
            if (!method_exists($runtime, 'applyState')) {
                $this->narrativeStateRuntime = false;
                return null;
            }
            $this->narrativeStateRuntime = $runtime;
            return $runtime;
        } catch (\Throwable) {
            $this->narrativeStateRuntime = false;
            return null;
        }
    }

    private function ensureNarrativeStateExists(?int $stateId): void
    {
        if ($stateId === null || $stateId <= 0) {
            return;
        }

        if (!$this->hasNarrativeStateTables()) {
            $this->failValidation('Gli stati narrativi non sono disponibili in questa istanza', 'advanced_profile_narrative_state_runtime_unavailable');
        }

        $row = $this->firstPrepared(
            'SELECT id
             FROM narrative_states
             WHERE id = ?
             LIMIT 1',
            [$stateId],
        );

        if (empty($row) || (int) ($row->id ?? 0) <= 0) {
            $this->failValidation('Stato narrativo non trovato', 'advanced_profile_narrative_state_not_found');
        }
    }

    private function ensureCharacterExists(int $characterId): void
    {
        if ($characterId <= 0) {
            $this->failValidation('Character ID non valido', 'character_invalid');
        }

        $row = $this->firstPrepared(
            'SELECT id
             FROM characters
             WHERE id = ?
             LIMIT 1',
            [$characterId],
        );

        if (empty($row) || (int) ($row->id ?? 0) <= 0) {
            $this->failValidation('Personaggio non trovato', 'character_not_found');
        }
    }

    private function ensureItemExists(?int $itemId): void
    {
        if ($itemId === null || $itemId <= 0) {
            return;
        }

        $row = $this->firstPrepared(
            'SELECT id
             FROM items
             WHERE id = ?
             LIMIT 1',
            [$itemId],
        );

        if (empty($row) || (int) ($row->id ?? 0) <= 0) {
            $this->failValidation('Oggetto core non trovato', 'linked_item_not_found');
        }
    }

    private function profileResourceModeLabel(string $mode): string
    {
        $labels = [
            'none' => 'Nessuna',
            'charges' => 'Cariche',
            'durability' => 'Durabilita',
            'ammo' => 'Munizioni',
        ];

        return $labels[$mode] ?? ucfirst($mode);
    }

    /** @return array<string,mixed> */
    private function buildResourceSummary(string $mode, int $current, int $max): array
    {
        if ($mode === 'charges') {
            $tone = $current <= 0 ? 'danger' : (($current * 100) <= max(1, $max * 35) ? 'warning' : 'success');
            return [
                'resource_label' => 'Cariche ' . $current . '/' . $max,
                'resource_current' => $current,
                'resource_max' => $max,
                'resource_status' => $current <= 0 ? 'Esaurito' : ($current < $max ? 'Parziale' : 'Pieno'),
                'resource_tone' => $tone,
            ];
        }

        if ($mode === 'durability') {
            $tone = $current <= 0 ? 'danger' : (($current * 100) <= max(1, $max * 35) ? 'warning' : 'success');
            return [
                'resource_label' => 'Durabilita ' . $current . '/' . $max,
                'resource_current' => $current,
                'resource_max' => $max,
                'resource_status' => $current <= 0 ? 'Rotto' : ($current < $max ? 'Usurato' : 'Integro'),
                'resource_tone' => $tone,
            ];
        }

        if ($mode === 'ammo') {
            $tone = $current <= 0 ? 'danger' : (($current * 100) <= max(1, $max * 35) ? 'warning' : 'success');
            return [
                'resource_label' => 'Munizioni ' . $current . '/' . $max,
                'resource_current' => $current,
                'resource_max' => $max,
                'resource_status' => $current <= 0 ? 'Scarico' : ($current < $max ? 'Basse' : 'Carico'),
                'resource_tone' => $tone,
            ];
        }

        return [
            'resource_label' => 'Nessuna risorsa',
            'resource_current' => 0,
            'resource_max' => 0,
            'resource_status' => 'Informativo',
            'resource_tone' => 'secondary',
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeProfilePayload(object $data, ?int $profileId = null): array
    {
        $name = $this->normalizeText($data->name ?? '');
        $slug = $this->normalizeSlug($data->slug ?? '', $name);
        $description = $this->normalizeText($data->description ?? '');
        $category = $this->normalizeEnum(
            $data->category ?? 'gear',
            ['gear', 'weapon', 'consumable', 'ammo', 'focus', 'relic', 'tool', 'other'],
            'gear',
        );
        $linkedItemId = $this->normalizeNullableInt($data->linked_item_id ?? null);
        $resourceMode = $this->normalizeEnum(
            $data->resource_mode ?? 'none',
            ['none', 'charges', 'durability', 'ammo'],
            'none',
        );
        $maxCharges = max(0, $this->normalizeInt($data->max_charges ?? 0, 0));
        $maxDurability = max(0, $this->normalizeInt($data->max_durability ?? 0, 0));
        $maxAmmo = max(0, $this->normalizeInt($data->max_ammo ?? 0, 0));
        $useCost = max(0, $this->normalizeInt($data->use_cost ?? 1, 1));
        $restoreAmount = max(0, $this->normalizeInt($data->restore_amount ?? 1, 1));
        $narrativeStateId = $this->normalizeNullableInt($data->narrative_state_id ?? null);
        $narrativeStateThreshold = max(0, $this->normalizeInt($data->narrative_state_threshold ?? 0, 0));
        $narrativeStateAction = $this->normalizeEnum(
            $data->narrative_state_action ?? 'apply',
            ['apply', 'remove'],
            'apply',
        );
        $stateIntensity = $this->normalizeFloat($data->state_intensity ?? 1, 1.0);
        if ($stateIntensity <= 0) {
            $stateIntensity = 1.0;
        }
        $stateDurationValue = max(0, $this->normalizeInt($data->state_duration_value ?? 0, 0));
        $stateDurationUnit = $this->normalizeEnum(
            $data->state_duration_unit ?? 'scene',
            ['scene', 'turn', 'minute', 'hour', 'day'],
            'scene',
        );
        $rarityLabel = $this->normalizeText($data->rarity_label ?? '');
        $sortOrder = $this->normalizeInt($data->sort_order ?? 100, 100);
        $isActive = $this->normalizeBool($data->is_active ?? 1, 1);

        if ($name === '') {
            $this->failValidation('Nome profilo obbligatorio', 'advanced_profile_name_required');
        }
        if ($slug === '') {
            $this->failValidation('Slug profilo non valido', 'advanced_profile_slug_invalid');
        }

        if ($resourceMode === 'charges' && $maxCharges <= 0) {
            $this->failValidation('Le cariche massime devono essere maggiori di zero', 'advanced_profile_charges_invalid');
        }
        if ($resourceMode === 'durability' && $maxDurability <= 0) {
            $this->failValidation('La durabilita massima deve essere maggiore di zero', 'advanced_profile_durability_invalid');
        }
        if ($resourceMode === 'ammo' && $maxAmmo <= 0) {
            $this->failValidation('Le munizioni massime devono essere maggiori di zero', 'advanced_profile_ammo_invalid');
        }
        if ($resourceMode === 'none') {
            $useCost = 0;
            $restoreAmount = 0;
            $maxCharges = 0;
            $maxDurability = 0;
            $maxAmmo = 0;
        }

        if ($narrativeStateId !== null && $narrativeStateThreshold <= 0) {
            $this->failValidation('Imposta una soglia usi maggiore di zero per la gestione dello stato narrativo', 'advanced_profile_narrative_state_threshold_invalid');
        }
        if ($narrativeStateId === null) {
            $narrativeStateAction = 'apply';
            $narrativeStateThreshold = 0;
            $stateIntensity = 1.0;
            $stateDurationValue = 0;
            $stateDurationUnit = 'scene';
        }

        $this->ensureItemExists($linkedItemId);
        $this->ensureNarrativeStateExists($narrativeStateId);

        $duplicate = $this->firstPrepared(
            'SELECT id
             FROM lf_advanced_items_profiles
             WHERE slug = ?
               AND (? IS NULL OR id <> ?)
             LIMIT 1',
            [$slug, $profileId, $profileId],
        );
        if (!empty($duplicate)) {
            $this->failValidation('Slug gia in uso', 'advanced_profile_slug_duplicate');
        }

        return [
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'category' => $category,
            'linked_item_id' => $linkedItemId,
            'resource_mode' => $resourceMode,
            'max_charges' => $maxCharges,
            'max_durability' => $maxDurability,
            'max_ammo' => $maxAmmo,
            'use_cost' => $useCost,
            'restore_amount' => $restoreAmount,
            'narrative_state_id' => $narrativeStateId,
            'narrative_state_threshold' => $narrativeStateThreshold,
            'narrative_state_action' => $narrativeStateAction,
            'state_intensity' => $stateIntensity,
            'state_duration_value' => $stateDurationValue,
            'state_duration_unit' => $stateDurationUnit,
            'rarity_label' => $rarityLabel,
            'sort_order' => $sortOrder,
            'is_active' => $isActive,
        ];
    }

    private function findProfileById(int $profileId)
    {
        if ($profileId <= 0) {
            return null;
        }

        return $this->firstPrepared(
            'SELECT p.*,
                    i.name AS linked_item_name,
                    i.slug AS linked_item_slug
             FROM lf_advanced_items_profiles p
             LEFT JOIN items i ON i.id = p.linked_item_id
             WHERE p.id = ?
             LIMIT 1',
            [$profileId],
        );
    }

    private function findAssignmentById(int $assignmentId)
    {
        if ($assignmentId <= 0) {
            return null;
        }

        return $this->firstPrepared(
            'SELECT a.*,
                    p.name AS profile_name,
                    p.slug AS profile_slug,
                    p.description AS profile_description,
                    p.category,
                    p.linked_item_id,
                    p.resource_mode,
                    p.max_charges,
                    p.max_durability,
                    p.max_ammo,
                    p.use_cost,
                    p.restore_amount,
                    p.narrative_state_id,
                    p.narrative_state_threshold,
                    p.narrative_state_action,
                    p.state_intensity,
                    p.state_duration_value,
                    p.state_duration_unit,
                    p.rarity_label,
                    p.is_active AS profile_is_active,
                    i.name AS linked_item_name,
                    c.name AS character_name,
                    c.surname AS character_surname
             FROM lf_advanced_items_character_items a
             INNER JOIN lf_advanced_items_profiles p ON p.id = a.profile_id
             INNER JOIN characters c ON c.id = a.character_id
             LEFT JOIN items i ON i.id = p.linked_item_id
             WHERE a.id = ?
             LIMIT 1',
            [$assignmentId],
        );
    }

    /** @return array<string,mixed> */
    private function serializeProfileRow($row): array
    {
        $data = is_object($row) ? get_object_vars($row) : (array) $row;
        $mode = (string) ($data['resource_mode'] ?? 'none');
        $itemName = trim((string) ($data['linked_item_name'] ?? ''));
        $linkedItemId = (int) ($data['linked_item_id'] ?? 0);

        $data['resource_mode_label'] = $this->profileResourceModeLabel($mode);
        $data['resource_config_label'] = match ($mode) {
            'charges' => 'Max ' . (int) ($data['max_charges'] ?? 0) . ' - costo ' . (int) ($data['use_cost'] ?? 0),
            'durability' => 'Max ' . (int) ($data['max_durability'] ?? 0) . ' - costo ' . (int) ($data['use_cost'] ?? 0),
            'ammo' => 'Max ' . (int) ($data['max_ammo'] ?? 0) . ' - costo ' . (int) ($data['use_cost'] ?? 0),
            default => 'Informativo',
        };
        $narrativeStateId = (int) ($data['narrative_state_id'] ?? 0);
        $narrativeThreshold = (int) ($data['narrative_state_threshold'] ?? 0);
        $narrativeAction = strtolower(trim((string) ($data['narrative_state_action'] ?? 'apply')));
        if (!in_array($narrativeAction, ['apply', 'remove'], true)) {
            $narrativeAction = 'apply';
        }
        if ($narrativeStateId > 0 && $narrativeThreshold > 0) {
            $verb = ($narrativeAction === 'remove') ? 'rimuove' : 'applica';
            $data['narrative_effect_label'] = 'Ogni ' . $narrativeThreshold . ' usi -> ' . $verb . ' stato #' . $narrativeStateId;
        } else {
            $data['narrative_effect_label'] = '';
        }
        $data['linked_item_label'] = $itemName !== '' ? $itemName : ($linkedItemId > 0 ? '#' . $linkedItemId : '');

        return $data;
    }

    /** @return array<string,mixed> */
    private function serializeAssignmentRow($row): array
    {
        $data = is_object($row) ? get_object_vars($row) : (array) $row;
        $mode = (string) ($data['resource_mode'] ?? 'none');
        $current = 0;
        $max = 0;
        if ($mode === 'charges') {
            $current = (int) ($data['charges_current'] ?? 0);
            $max = (int) ($data['max_charges'] ?? 0);
        } elseif ($mode === 'durability') {
            $current = (int) ($data['durability_current'] ?? 0);
            $max = (int) ($data['max_durability'] ?? 0);
        } elseif ($mode === 'ammo') {
            $current = (int) ($data['ammo_current'] ?? 0);
            $max = (int) ($data['max_ammo'] ?? 0);
        }

        $resource = $this->buildResourceSummary($mode, $current, $max);
        $displayName = trim((string) ($data['custom_name'] ?? ''));
        if ($displayName === '') {
            $displayName = trim((string) ($data['profile_name'] ?? ''));
        }

        return array_merge($data, $resource, [
            'display_name' => $displayName,
            'resource_mode_label' => $this->profileResourceModeLabel($mode),
            'linked_item_label' => trim((string) ($data['linked_item_name'] ?? '')),
            'character_label' => trim(((string) ($data['character_name'] ?? '')) . ' ' . ((string) ($data['character_surname'] ?? ''))),
            'can_use' => ($mode !== 'none' && $resource['resource_current'] > 0) ? 1 : 0,
            'can_restore' => ($mode !== 'none' && $resource['resource_current'] < $resource['resource_max']) ? 1 : 0,
        ]);
    }

    private function resolveAssignmentCurrentValue($value, int $fallback, int $max): int
    {
        $resolved = ($value === null || $value === '') ? $fallback : max(0, (int) $value);
        return $this->limitValue($resolved, 0, max(0, $max));
    }

    /** @return array<string,mixed> */
    private function normalizeAssignmentPayload(object $data, $profileRow, $currentRow = null): array
    {
        $profile = is_object($profileRow) ? get_object_vars($profileRow) : (array) $profileRow;
        $current = is_object($currentRow) ? get_object_vars($currentRow) : (array) $currentRow;

        $characterId = $this->normalizeInt($data->character_id ?? ($current['character_id'] ?? 0), 0);
        $customName = $this->normalizeText($data->custom_name ?? ($current['custom_name'] ?? ''));
        $sortOrder = $this->normalizeInt($data->sort_order ?? ($current['sort_order'] ?? 100), 100);
        $isEquipped = $this->normalizeBool($data->is_equipped ?? ($current['is_equipped'] ?? 0), (int) ($current['is_equipped'] ?? 0));
        $isActive = $this->normalizeBool($data->is_active ?? ($current['is_active'] ?? 1), (int) ($current['is_active'] ?? 1));
        $note = $this->normalizeText($data->note ?? ($current['note'] ?? ''));

        $maxCharges = (int) ($profile['max_charges'] ?? 0);
        $maxDurability = (int) ($profile['max_durability'] ?? 0);
        $maxAmmo = (int) ($profile['max_ammo'] ?? 0);

        $chargesCurrent = $this->resolveAssignmentCurrentValue(
            $data->charges_current ?? null,
            array_key_exists('charges_current', $current) ? (int) $current['charges_current'] : $maxCharges,
            $maxCharges,
        );
        $durabilityCurrent = $this->resolveAssignmentCurrentValue(
            $data->durability_current ?? null,
            array_key_exists('durability_current', $current) ? (int) $current['durability_current'] : $maxDurability,
            $maxDurability,
        );
        $ammoCurrent = $this->resolveAssignmentCurrentValue(
            $data->ammo_current ?? null,
            array_key_exists('ammo_current', $current) ? (int) $current['ammo_current'] : $maxAmmo,
            $maxAmmo,
        );

        $this->ensureCharacterExists($characterId);

        return [
            'character_id' => $characterId,
            'custom_name' => $customName,
            'charges_current' => $chargesCurrent,
            'durability_current' => $durabilityCurrent,
            'ammo_current' => $ammoCurrent,
            'is_equipped' => $isEquipped,
            'note' => $note,
            'sort_order' => $sortOrder,
            'is_active' => $isActive,
        ];
    }

    public function adminListProfiles(): array
    {
        $rows = $this->fetchPrepared(
            'SELECT p.*,
                    i.name AS linked_item_name,
                    i.slug AS linked_item_slug
             FROM lf_advanced_items_profiles p
             LEFT JOIN items i ON i.id = p.linked_item_id
             ORDER BY p.sort_order ASC, p.name ASC, p.id ASC',
        );

        return array_map(fn($row) => $this->serializeProfileRow($row), $rows ?: []);
    }

    public function adminCreateProfile(object $data): int
    {
        $payload = $this->normalizeProfilePayload($data);

        $this->execPrepared(
            'INSERT INTO lf_advanced_items_profiles
                (name, slug, description, category, linked_item_id, resource_mode, max_charges, max_durability, max_ammo, use_cost, restore_amount, narrative_state_id, narrative_state_threshold, narrative_state_action, state_intensity, state_duration_value, state_duration_unit, rarity_label, sort_order, is_active, date_created, date_updated)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $payload['name'],
                $payload['slug'],
                $payload['description'],
                $payload['category'],
                $payload['linked_item_id'],
                $payload['resource_mode'],
                $payload['max_charges'],
                $payload['max_durability'],
                $payload['max_ammo'],
                $payload['use_cost'],
                $payload['restore_amount'],
                $payload['narrative_state_id'],
                $payload['narrative_state_threshold'],
                $payload['narrative_state_action'],
                $payload['state_intensity'],
                $payload['state_duration_value'],
                $payload['state_duration_unit'],
                $payload['rarity_label'],
                $payload['sort_order'],
                $payload['is_active'],
            ],
        );

        return (int) $this->db->lastInsertId();
    }

    public function adminUpdateProfile(object $data): void
    {
        $id = $this->normalizeInt($data->id ?? 0, 0);
        if ($id <= 0) {
            $this->failValidation('ID profilo non valido', 'advanced_profile_id_invalid');
        }

        if (empty($this->findProfileById($id))) {
            $this->failValidation('Profilo non trovato', 'advanced_profile_not_found');
        }

        $payload = $this->normalizeProfilePayload($data, $id);

        $this->execPrepared(
            'UPDATE lf_advanced_items_profiles
             SET name = ?,
                 slug = ?,
                 description = ?,
                 category = ?,
                 linked_item_id = ?,
                 resource_mode = ?,
                 max_charges = ?,
                 max_durability = ?,
                 max_ammo = ?,
                 use_cost = ?,
                 restore_amount = ?,
                 narrative_state_id = ?,
                 narrative_state_threshold = ?,
                 narrative_state_action = ?,
                 state_intensity = ?,
                 state_duration_value = ?,
                 state_duration_unit = ?,
                 rarity_label = ?,
                 sort_order = ?,
                 is_active = ?,
                 date_updated = NOW()
             WHERE id = ?',
            [
                $payload['name'],
                $payload['slug'],
                $payload['description'],
                $payload['category'],
                $payload['linked_item_id'],
                $payload['resource_mode'],
                $payload['max_charges'],
                $payload['max_durability'],
                $payload['max_ammo'],
                $payload['use_cost'],
                $payload['restore_amount'],
                $payload['narrative_state_id'],
                $payload['narrative_state_threshold'],
                $payload['narrative_state_action'],
                $payload['state_intensity'],
                $payload['state_duration_value'],
                $payload['state_duration_unit'],
                $payload['rarity_label'],
                $payload['sort_order'],
                $payload['is_active'],
                $id,
            ],
        );
    }

    public function adminDeleteProfile(int $profileId): void
    {
        if ($profileId <= 0) {
            $this->failValidation('ID profilo non valido', 'advanced_profile_id_invalid');
        }

        $this->execPrepared(
            'DELETE FROM lf_advanced_items_character_items
             WHERE profile_id = ?',
            [$profileId],
        );

        $this->execPrepared(
            'DELETE FROM lf_advanced_items_profiles
             WHERE id = ?',
            [$profileId],
        );
    }

    public function adminListAssignments(int $characterId): array
    {
        $this->ensureCharacterExists($characterId);

        $rows = $this->fetchPrepared(
            'SELECT a.*,
                    p.name AS profile_name,
                    p.slug AS profile_slug,
                    p.description AS profile_description,
                    p.category,
                    p.linked_item_id,
                    p.resource_mode,
                    p.max_charges,
                    p.max_durability,
                    p.max_ammo,
                    p.use_cost,
                    p.restore_amount,
                    p.narrative_state_id,
                    p.narrative_state_threshold,
                    p.narrative_state_action,
                    p.state_intensity,
                    p.state_duration_value,
                    p.state_duration_unit,
                    p.rarity_label,
                    i.name AS linked_item_name,
                    c.name AS character_name,
                    c.surname AS character_surname
             FROM lf_advanced_items_character_items a
             INNER JOIN lf_advanced_items_profiles p ON p.id = a.profile_id
             INNER JOIN characters c ON c.id = a.character_id
             LEFT JOIN items i ON i.id = p.linked_item_id
             WHERE a.character_id = ?
             ORDER BY a.is_equipped DESC, a.sort_order ASC, a.id ASC',
            [$characterId],
        );

        return array_map(fn($row) => $this->serializeAssignmentRow($row), $rows ?: []);
    }

    public function adminCreateAssignment(object $data): int
    {
        $profileId = $this->normalizeInt($data->profile_id ?? 0, 0);
        $profile = $this->findProfileById($profileId);
        if (empty($profile)) {
            $this->failValidation('Profilo non trovato', 'advanced_profile_not_found');
        }

        $payload = $this->normalizeAssignmentPayload($data, $profile);

        $this->execPrepared(
            'INSERT INTO lf_advanced_items_character_items
                (character_id, profile_id, custom_name, charges_current, durability_current, ammo_current, is_equipped, note, sort_order, is_active, date_created, date_updated)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $payload['character_id'],
                $profileId,
                $payload['custom_name'],
                $payload['charges_current'],
                $payload['durability_current'],
                $payload['ammo_current'],
                $payload['is_equipped'],
                $payload['note'],
                $payload['sort_order'],
                $payload['is_active'],
            ],
        );

        return (int) $this->db->lastInsertId();
    }

    public function adminUpdateAssignment(object $data): void
    {
        $id = $this->normalizeInt($data->id ?? 0, 0);
        if ($id <= 0) {
            $this->failValidation('ID assegnazione non valido', 'advanced_assignment_id_invalid');
        }

        $current = $this->findAssignmentById($id);
        if (empty($current)) {
            $this->failValidation('Assegnazione non trovata', 'advanced_assignment_not_found');
        }

        $profileId = $this->normalizeInt($data->profile_id ?? ($current->profile_id ?? 0), 0);
        $profile = $this->findProfileById($profileId);
        if (empty($profile)) {
            $this->failValidation('Profilo non trovato', 'advanced_profile_not_found');
        }

        $payload = $this->normalizeAssignmentPayload($data, $profile, $current);

        $this->execPrepared(
            'UPDATE lf_advanced_items_character_items
             SET character_id = ?,
                 profile_id = ?,
                 custom_name = ?,
                 charges_current = ?,
                 durability_current = ?,
                 ammo_current = ?,
                 is_equipped = ?,
                 note = ?,
                 sort_order = ?,
                 is_active = ?,
                 date_updated = NOW()
             WHERE id = ?',
            [
                $payload['character_id'],
                $profileId,
                $payload['custom_name'],
                $payload['charges_current'],
                $payload['durability_current'],
                $payload['ammo_current'],
                $payload['is_equipped'],
                $payload['note'],
                $payload['sort_order'],
                $payload['is_active'],
                $id,
            ],
        );
    }

    public function adminDeleteAssignment(int $assignmentId): void
    {
        if ($assignmentId <= 0) {
            $this->failValidation('ID assegnazione non valido', 'advanced_assignment_id_invalid');
        }

        $this->execPrepared(
            'DELETE FROM lf_advanced_items_character_items
             WHERE id = ?',
            [$assignmentId],
        );
    }

    public function adminSearchCharacters(string $query): array
    {
        $needle = trim($query);
        if ($needle === '') {
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
            $label = trim(((string) ($row->name ?? '')) . ' ' . ((string) ($row->surname ?? '')));
            $dataset[] = [
                'id' => (int) ($row->id ?? 0),
                'name' => (string) ($row->name ?? ''),
                'surname' => (string) ($row->surname ?? ''),
                'label' => $label !== '' ? $label : ('#' . (int) ($row->id ?? 0)),
            ];
        }

        return $dataset;
    }

    public function adminSearchCoreItems(string $query): array
    {
        $needle = trim($query);
        if ($needle === '') {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT id, name, slug, type, rarity
             FROM items
             WHERE name LIKE ?
                OR slug LIKE ?
             ORDER BY name ASC, id ASC
             LIMIT 12',
            ['%' . $needle . '%', '%' . $needle . '%'],
        );

        $dataset = [];
        foreach ($rows as $row) {
            $dataset[] = [
                'id' => (int) ($row->id ?? 0),
                'name' => (string) ($row->name ?? ''),
                'slug' => (string) ($row->slug ?? ''),
                'type' => (string) ($row->type ?? ''),
                'rarity' => (string) ($row->rarity ?? ''),
            ];
        }

        return $dataset;
    }

    public function listCharacterItems(int $characterId): array
    {
        $this->ensureCharacterExists($characterId);

        $rows = $this->fetchPrepared(
            'SELECT a.*,
                    p.name AS profile_name,
                    p.slug AS profile_slug,
                    p.description AS profile_description,
                    p.category,
                    p.linked_item_id,
                    p.resource_mode,
                    p.max_charges,
                    p.max_durability,
                    p.max_ammo,
                    p.use_cost,
                    p.restore_amount,
                    p.narrative_state_id,
                    p.narrative_state_threshold,
                    p.narrative_state_action,
                    p.state_intensity,
                    p.state_duration_value,
                    p.state_duration_unit,
                    p.rarity_label,
                    i.name AS linked_item_name
             FROM lf_advanced_items_character_items a
             INNER JOIN lf_advanced_items_profiles p ON p.id = a.profile_id
             LEFT JOIN items i ON i.id = p.linked_item_id
             WHERE a.character_id = ?
               AND a.is_active = 1
               AND p.is_active = 1
             ORDER BY a.is_equipped DESC, a.sort_order ASC, a.id ASC',
            [$characterId],
        );

        return array_map(fn($row) => $this->serializeAssignmentRow($row), $rows ?: []);
    }

    /** @return array<string,mixed> */
    private function applyNarrativeStateEffectOnUse(int $characterId, object $assignment, int $nextUseCounter): array
    {
        $stateId = (int) ($assignment->narrative_state_id ?? 0);
        $threshold = (int) ($assignment->narrative_state_threshold ?? 0);
        $action = strtolower(trim((string) ($assignment->narrative_state_action ?? 'apply')));
        if (!in_array($action, ['apply', 'remove'], true)) {
            $action = 'apply';
        }
        if ($stateId <= 0 || $threshold <= 0) {
            return [
                'configured' => false,
                'triggered' => false,
                'applied' => false,
                'message' => '',
            ];
        }

        if ($nextUseCounter <= 0 || ($nextUseCounter % $threshold) !== 0) {
            return [
                'configured' => true,
                'triggered' => false,
                'applied' => false,
                'message' => '',
            ];
        }

        $runtime = $this->narrativeStateRuntime();
        $runtimeMethod = ($action === 'remove') ? 'removeState' : 'applyState';
        if (!is_object($runtime) || !method_exists($runtime, $runtimeMethod)) {
            return [
                'configured' => true,
                'triggered' => true,
                'applied' => false,
                'message' => 'Soglia raggiunta ma runtime stati narrativi non disponibile.',
            ];
        }

        $intensity = (float) ($assignment->state_intensity ?? 1.0);
        if ($intensity <= 0) {
            $intensity = 1.0;
        }
        $durationValue = max(0, (int) ($assignment->state_duration_value ?? 0));
        $durationUnit = strtolower(trim((string) ($assignment->state_duration_unit ?? 'scene')));
        if (!in_array($durationUnit, ['scene', 'turn', 'minute', 'hour', 'day'], true)) {
            $durationUnit = 'scene';
        }

        try {
            if ($action === 'remove') {
                $runtime->removeState([
                    'state_id' => $stateId,
                    'target_type' => 'character',
                    'target_id' => $characterId,
                    'reason' => 'advanced_item_use_threshold',
                ]);
            } else {
                $runtime->applyState([
                    'state_id' => $stateId,
                    'target_type' => 'character',
                    'target_id' => $characterId,
                    'applier_character_id' => $characterId,
                    'intensity' => $intensity,
                    'duration_value' => $durationValue,
                    'duration_unit' => $durationUnit,
                    'meta_json' => json_encode([
                        'source' => 'advanced_item_use',
                        'assignment_id' => (int) ($assignment->id ?? 0),
                        'use_counter' => $nextUseCounter,
                    ], JSON_UNESCAPED_UNICODE),
                ]);
            }

            return [
                'configured' => true,
                'triggered' => true,
                'applied' => true,
                'message' => $action === 'remove'
                    ? 'Soglia raggiunta: stato narrativo rimosso.'
                    : 'Soglia raggiunta: stato narrativo applicato.',
            ];
        } catch (\Throwable $error) {
            return [
                'configured' => true,
                'triggered' => true,
                'applied' => false,
                'message' => ($action === 'remove'
                    ? 'Soglia raggiunta ma rimozione stato fallita: '
                    : 'Soglia raggiunta ma applicazione stato fallita: ')
                    . $error->getMessage(),
            ];
        }
    }

    /** @return array<string,mixed> */
    private function consumeAssignmentResource(int $characterId, object $data, bool $restore = false): array
    {
        $assignmentId = $this->normalizeInt($data->assignment_id ?? 0, 0);
        if ($assignmentId <= 0) {
            $this->failValidation('Assegnazione non valida', 'advanced_assignment_id_invalid');
        }

        $assignment = $this->findAssignmentById($assignmentId);
        if (empty($assignment) || (int) ($assignment->character_id ?? 0) !== $characterId) {
            $this->failValidation('Assegnazione non trovata per questo personaggio', 'advanced_assignment_not_found');
        }
        if ((int) ($assignment->is_active ?? 0) !== 1 || (int) ($assignment->profile_is_active ?? 1) !== 1) {
            $this->failValidation('L\'oggetto avanzato non e attivo', 'advanced_assignment_inactive');
        }

        $mode = (string) ($assignment->resource_mode ?? 'none');
        if ($mode === 'none') {
            $this->failValidation('Questo profilo non usa risorse consumabili', 'advanced_assignment_not_consumable');
        }

        $multiplier = max(1, $this->normalizeInt($data->amount ?? 1, 1));
        $step = $restore
            ? max(1, (int) ($assignment->restore_amount ?? 1))
            : max(1, (int) ($assignment->use_cost ?? 1));
        $delta = $multiplier * $step;

        $column = 'charges_current';
        $current = (int) ($assignment->charges_current ?? 0);
        $max = (int) ($assignment->max_charges ?? 0);
        $verb = $restore ? 'ripristinate' : 'consumate';
        $subject = 'cariche';
        if ($mode === 'durability') {
            $column = 'durability_current';
            $current = (int) ($assignment->durability_current ?? 0);
            $max = (int) ($assignment->max_durability ?? 0);
            $verb = $restore ? 'riparata' : 'consumata';
            $subject = 'durabilita';
        } elseif ($mode === 'ammo') {
            $column = 'ammo_current';
            $current = (int) ($assignment->ammo_current ?? 0);
            $max = (int) ($assignment->max_ammo ?? 0);
            $verb = $restore ? 'ricaricate' : 'consumate';
            $subject = 'munizioni';
        }

        if ($restore) {
            $next = $this->limitValue($current + $delta, 0, max(0, $max));
            if ($next === $current) {
                $this->failValidation('La risorsa e gia al massimo', 'advanced_assignment_resource_full');
            }
        } else {
            if ($current <= 0) {
                $this->failValidation('La risorsa e esaurita', 'advanced_assignment_resource_empty');
            }
            if ($current < $delta) {
                $this->failValidation('Risorsa insufficiente per questo utilizzo', 'advanced_assignment_resource_insufficient');
            }
            $next = $current - $delta;
        }

        $currentUseCounter = max(0, (int) ($assignment->use_counter ?? 0));
        $nextUseCounter = $restore ? $currentUseCounter : ($currentUseCounter + $multiplier);

        $this->execPrepared(
            'UPDATE lf_advanced_items_character_items
             SET ' . $column . ' = ?,
                 use_counter = ?,
                 date_updated = NOW()
             WHERE id = ?',
            [$next, $nextUseCounter, $assignmentId],
        );

        $updated = $this->findAssignmentById($assignmentId);
        $serialized = $this->serializeAssignmentRow($updated);
        $narrativeResult = [
            'configured' => false,
            'triggered' => false,
            'applied' => false,
            'message' => '',
        ];

        if (!$restore && is_object($updated)) {
            $narrativeResult = $this->applyNarrativeStateEffectOnUse($characterId, $updated, $nextUseCounter);
        }

        $message = 'Stato aggiornato: ' . $subject . ' ' . $verb . '.';
        if (!$restore && !empty($narrativeResult['message'])) {
            $message .= ' ' . (string) $narrativeResult['message'];
        }

        return [
            'assignment' => $serialized,
            'narrative_state' => $narrativeResult,
            'message' => $message,
        ];
    }

    public function useAssignment(int $characterId, object $data): array
    {
        return $this->consumeAssignmentResource($characterId, $data, false);
    }

    public function restoreAssignment(int $characterId, object $data): array
    {
        return $this->consumeAssignmentResource($characterId, $data, true);
    }
}
