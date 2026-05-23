<?php

declare(strict_types=1);

namespace Modules\Logeon\ArchetypeAttributes\Services;

use Core\Http\AppError;
use Modules\Logeon\Archetypes\Services\ArchetypeService;
use Modules\Logeon\Attributes\Services\CharacterAttributesBaseService;
use Modules\Logeon\Attributes\Services\CharacterAttributesFacadeService;

class ArchetypeAttributesService extends CharacterAttributesBaseService
{
    /** @var CharacterAttributesFacadeService */
    private $attributeFacade;
    /** @var ArchetypeService */
    private $archetypeService;

    public function __construct(
        \Core\Database\DbAdapterInterface $db = null,
        CharacterAttributesFacadeService $attributeFacade = null,
        ArchetypeService $archetypeService = null,
    ) {
        parent::__construct($db);
        $this->attributeFacade = $attributeFacade ?: new CharacterAttributesFacadeService($this->db);
        $this->archetypeService = $archetypeService ?: new ArchetypeService($this->db);
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

    /** @return array<string,string> */
    public function ruleTypeLabels(): array
    {
        return [
            'fixed_value' => 'Valore fisso',
            'min_value' => 'Valore minimo',
            'max_value' => 'Valore massimo',
            'bonus' => 'Bonus',
            'suggestion' => 'Suggerimento',
        ];
    }

    private function normalizeText($value, bool $allowEmpty = true): string
    {
        $text = trim((string) $value);
        if ($text === '' && !$allowEmpty) {
            return '';
        }
        return $text;
    }

    private function normalizeIntValue($value, int $default = 0): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        return (int) $value;
    }

    private function normalizeArchetypeIds(array $rawIds): array
    {
        $ids = [];
        foreach ($rawIds as $rawId) {
            $id = (int) $rawId;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    private function characterCreateAttributeValues(object $payload): array
    {
        $index = [];
        $entries = isset($payload->attribute_values) && is_array($payload->attribute_values)
            ? $payload->attribute_values
            : [];

        foreach ($entries as $entry) {
            $row = is_object($entry) ? $entry : (object) $entry;
            $attributeId = (int) ($row->attribute_id ?? 0);
            if ($attributeId <= 0) {
                continue;
            }
            $index[$attributeId] = $row->base_value ?? null;
        }

        return $index;
    }

    private function attributeDefinitionsIndex(): array
    {
        $rows = $this->fetchPrepared(
            'SELECT
                id,
                slug,
                name,
                description,
                value_type,
                default_value,
                min_value,
                max_value,
                round_mode,
                allow_manual_override
             FROM character_attribute_definitions
             WHERE is_active = 1
             ORDER BY position ASC, id ASC',
        );

        $index = [];
        foreach ($rows ?: [] as $row) {
            $id = (int) ($row->id ?? 0);
            if ($id <= 0) {
                continue;
            }
            $index[$id] = [
                'attribute_id' => $id,
                'slug' => (string) ($row->slug ?? ''),
                'name' => (string) ($row->name ?? ''),
                'description' => (string) ($row->description ?? ''),
                'value_type' => (string) ($row->value_type ?? 'number'),
                'default_value' => isset($row->default_value) ? (float) $row->default_value : null,
                'min_value' => isset($row->min_value) ? (float) $row->min_value : null,
                'max_value' => isset($row->max_value) ? (float) $row->max_value : null,
                'round_mode' => (string) ($row->round_mode ?? 'none'),
                'allow_manual_override' => (int) ($row->allow_manual_override ?? 0),
            ];
        }

        return $index;
    }

    private function activeArchetypes(): array
    {
        $rows = $this->fetchPrepared(
            'SELECT id, name, slug, description, is_selectable
             FROM archetypes
             WHERE is_active = 1
             ORDER BY sort_order ASC, name ASC, id ASC',
        );

        $dataset = [];
        foreach ($rows ?: [] as $row) {
            $dataset[] = [
                'id' => (int) ($row->id ?? 0),
                'name' => (string) ($row->name ?? ''),
                'slug' => (string) ($row->slug ?? ''),
                'description' => (string) ($row->description ?? ''),
                'is_selectable' => (int) ($row->is_selectable ?? 0),
            ];
        }

        return $dataset;
    }

    private function findArchetypeExists(int $archetypeId): void
    {
        $row = $this->firstPrepared(
            'SELECT id
             FROM archetypes
             WHERE id = ?
             LIMIT 1',
            [$archetypeId],
        );
        if (empty($row) || (int) ($row->id ?? 0) <= 0) {
            $this->failValidation('Archetipo non trovato', 'archetype_not_found');
        }
    }

    private function findAttributeExists(int $attributeId): void
    {
        $row = $this->firstPrepared(
            'SELECT id
             FROM character_attribute_definitions
             WHERE id = ?
             LIMIT 1',
            [$attributeId],
        );
        if (empty($row) || (int) ($row->id ?? 0) <= 0) {
            $this->failValidation('Attributo non trovato', 'attribute_definition_not_found');
        }
    }

    public function ensureAttributesEnabled(): bool
    {
        return $this->attributeFacade->isEnabled();
    }

    private function serializeRuleRow($row): array
    {
        $labels = $this->ruleTypeLabels();
        return [
            'id' => (int) ($row->id ?? 0),
            'archetype_id' => (int) ($row->archetype_id ?? 0),
            'archetype_name' => (string) ($row->archetype_name ?? ''),
            'attribute_id' => (int) ($row->attribute_id ?? 0),
            'attribute_name' => (string) ($row->attribute_name ?? ''),
            'attribute_slug' => (string) ($row->attribute_slug ?? ''),
            'rule_type' => (string) ($row->rule_type ?? ''),
            'rule_type_label' => $labels[(string) ($row->rule_type ?? '')] ?? (string) ($row->rule_type ?? ''),
            'value' => (string) ($row->value ?? ''),
            'is_enforced' => (int) ($row->is_enforced ?? 0),
            'priority' => (int) ($row->priority ?? 100),
            'notes' => (string) ($row->notes ?? ''),
            'date_created' => (string) ($row->date_created ?? ''),
            'date_updated' => (string) ($row->date_updated ?? ''),
        ];
    }

    private function rulesByArchetypes(array $archetypeIds): array
    {
        if ($archetypeIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($archetypeIds), '?'));
        return $this->fetchPrepared(
            'SELECT
                r.*,
                a.name AS archetype_name,
                d.name AS attribute_name,
                d.slug AS attribute_slug,
                d.description AS attribute_description,
                d.value_type,
                d.default_value,
                d.min_value AS definition_min_value,
                d.max_value AS definition_max_value,
                d.round_mode,
                d.allow_manual_override
             FROM lf_archetype_attribute_rules r
             INNER JOIN archetypes a ON a.id = r.archetype_id
             INNER JOIN character_attribute_definitions d ON d.id = r.attribute_id
             WHERE r.archetype_id IN (' . $placeholders . ')
             ORDER BY r.attribute_id ASC, r.priority DESC, r.id ASC',
            $archetypeIds,
        );
    }

    private function resolveRuleSet(array $archetypeIds): array
    {
        $ids = $this->normalizeArchetypeIds($archetypeIds);
        if ($ids === []) {
            return [];
        }

        $definitions = $this->attributeDefinitionsIndex();
        $rows = $this->rulesByArchetypes($ids);
        if ($rows === []) {
            return [];
        }

        $grouped = [];
        foreach ($rows as $row) {
            $attributeId = (int) ($row->attribute_id ?? 0);
            if ($attributeId <= 0 || !isset($definitions[$attributeId])) {
                continue;
            }
            if (!isset($grouped[$attributeId])) {
                $grouped[$attributeId] = [];
            }
            $grouped[$attributeId][] = $row;
        }

        $resolved = [];
        foreach ($grouped as $attributeId => $ruleRows) {
            $definition = $definitions[$attributeId];
            $fixedValue = null;
            $minValue = null;
            $maxValue = null;
            $bonusTotal = 0.0;
            $suggestions = [];
            $rules = [];
            $hintParts = [];

            foreach ($ruleRows as $row) {
                $type = trim((string) ($row->rule_type ?? ''));
                $valueRaw = trim((string) ($row->value ?? ''));
                $isEnforced = ((int) ($row->is_enforced ?? 0)) === 1;
                $notes = trim((string) ($row->notes ?? ''));
                $rules[] = $this->serializeRuleRow($row);

                if ($type === 'suggestion') {
                    $text = $valueRaw !== '' ? $valueRaw : $notes;
                    if ($text !== '') {
                        $suggestions[] = $text;
                    }
                    continue;
                }

                if (!is_numeric(str_replace(',', '.', $valueRaw))) {
                    if ($notes !== '') {
                        $suggestions[] = $notes;
                    }
                    continue;
                }

                $numeric = round((float) str_replace(',', '.', $valueRaw), 2);
                if ($type === 'fixed_value') {
                    if ($isEnforced && $fixedValue === null) {
                        $fixedValue = $numeric;
                    }
                    $hintParts[] = 'Fisso ' . rtrim(rtrim((string) $numeric, '0'), '.');
                    continue;
                }
                if ($type === 'min_value') {
                    if ($isEnforced) {
                        $minValue = ($minValue === null) ? $numeric : max($minValue, $numeric);
                    }
                    $hintParts[] = 'Min ' . rtrim(rtrim((string) $numeric, '0'), '.');
                    continue;
                }
                if ($type === 'max_value') {
                    if ($isEnforced) {
                        $maxValue = ($maxValue === null) ? $numeric : min($maxValue, $numeric);
                    }
                    $hintParts[] = 'Max ' . rtrim(rtrim((string) $numeric, '0'), '.');
                    continue;
                }
                if ($type === 'bonus') {
                    $bonusTotal += $numeric;
                    $hintParts[] = ($numeric >= 0 ? '+' : '') . rtrim(rtrim((string) $numeric, '0'), '.') . ' bonus';
                }
            }

            if ($fixedValue !== null) {
                $minValue = $fixedValue;
                $maxValue = $fixedValue;
            }

            $resolved[$attributeId] = array_merge($definition, [
                'rule_count' => count($rules),
                'fixed_value' => $fixedValue,
                'enforced_min_value' => $minValue,
                'enforced_max_value' => $maxValue,
                'bonus_total' => round($bonusTotal, 2),
                'display_hint' => implode(' | ', array_values(array_unique($hintParts))),
                'suggestions' => array_values(array_unique($suggestions)),
                'rules' => $rules,
            ]);
        }

        return $resolved;
    }

    private function validateBaseValueAgainstRules(array $resolvedAttribute, ?float $baseValue): float
    {
        $final = $baseValue;
        if ($resolvedAttribute['fixed_value'] !== null) {
            $final = (float) $resolvedAttribute['fixed_value'];
        } elseif ($final === null) {
            $final = $resolvedAttribute['default_value'] !== null
                ? (float) $resolvedAttribute['default_value']
                : 0.0;
        }

        if ($resolvedAttribute['enforced_min_value'] !== null && $final < (float) $resolvedAttribute['enforced_min_value']) {
            $this->failValidation(
                'Il valore di ' . $resolvedAttribute['name'] . ' e inferiore al minimo consentito',
                'attribute_range_invalid',
            );
        }

        if ($resolvedAttribute['enforced_max_value'] !== null && $final > (float) $resolvedAttribute['enforced_max_value']) {
            $this->failValidation(
                'Il valore di ' . $resolvedAttribute['name'] . ' supera il massimo consentito',
                'attribute_range_invalid',
            );
        }

        return $this->clampAndRound(
            (float) $final,
            $resolvedAttribute['min_value'],
            $resolvedAttribute['max_value'],
            (string) ($resolvedAttribute['round_mode'] ?? 'none'),
        );
    }

    private function buildCharacterCreateEntries(object $payload, array $resolvedSet): array
    {
        $inputMap = $this->characterCreateAttributeValues($payload);
        $entries = [];

        foreach ($resolvedSet as $attributeId => $resolvedAttribute) {
            $rawValue = $inputMap[$attributeId] ?? null;
            $baseValue = null;
            if ($rawValue !== null && $rawValue !== '') {
                $baseValue = $this->normalizeDecimalValue(
                    $rawValue,
                    (string) ($resolvedAttribute['name'] ?? 'attributo'),
                    'attribute_range_invalid',
                    true,
                );
            }

            $normalizedBase = $this->validateBaseValueAgainstRules($resolvedAttribute, $baseValue);

            $entries[] = [
                'attribute_id' => (int) $attributeId,
                'base_value' => $normalizedBase,
            ];
        }

        return $entries;
    }

    private function assignedArchetypeIdsForCharacter(int $characterId): array
    {
        $rows = $this->archetypeService->getCharacterArchetypes($characterId);
        $ids = [];
        foreach ($rows as $row) {
            $id = is_object($row) ? (int) ($row->id ?? 0) : (int) (($row['id'] ?? 0));
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    private function profileUpdateEntries(array $entries, array $resolvedSet): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $row = is_object($entry) ? $entry : (object) $entry;
            $attributeId = (int) ($row->attribute_id ?? 0);
            if ($attributeId <= 0) {
                continue;
            }

            $item = ['attribute_id' => $attributeId];
            if (property_exists($row, 'base_value')) {
                $baseValue = $this->normalizeDecimalValue(
                    $row->base_value,
                    'valore base',
                    'attribute_range_invalid',
                    true,
                );
                if (isset($resolvedSet[$attributeId])) {
                    $baseValue = $this->validateBaseValueAgainstRules($resolvedSet[$attributeId], $baseValue);
                }
                $item['base_value'] = $baseValue;
            }

            if (property_exists($row, 'override_value')) {
                $item['override_value'] = $row->override_value;
            }

            $out[] = $item;
        }

        return $out;
    }

    public function adminMeta(): array
    {
        return [
            'archetypes' => $this->activeArchetypes(),
            'attributes' => array_values($this->attributeDefinitionsIndex()),
            'rule_types' => $this->ruleTypeLabels(),
        ];
    }

    public function listRules(): array
    {
        $rows = $this->fetchPrepared(
            'SELECT
                r.*,
                a.name AS archetype_name,
                d.name AS attribute_name,
                d.slug AS attribute_slug
             FROM lf_archetype_attribute_rules r
             INNER JOIN archetypes a ON a.id = r.archetype_id
             INNER JOIN character_attribute_definitions d ON d.id = r.attribute_id
             ORDER BY a.name ASC, d.name ASC, r.priority DESC, r.id ASC',
        );

        $dataset = [];
        foreach ($rows ?: [] as $row) {
            $dataset[] = $this->serializeRuleRow($row);
        }
        return $dataset;
    }

    public function upsertRule(object $payload): int
    {
        $id = $this->normalizeIntValue($payload->id ?? 0, 0);
        $archetypeId = $this->normalizeIntValue($payload->archetype_id ?? 0, 0);
        $attributeId = $this->normalizeIntValue($payload->attribute_id ?? 0, 0);
        $ruleType = $this->normalizeText($payload->rule_type ?? '', false);
        $value = $this->normalizeText($payload->value ?? '', true);
        $isEnforced = $this->normalizeBool($payload->is_enforced ?? 0, 0);
        $priority = $this->normalizeIntValue($payload->priority ?? 100, 100);
        $notes = $this->normalizeText($payload->notes ?? '', true);

        if ($archetypeId <= 0) {
            $this->failValidation('Archetipo obbligatorio', 'archetype_not_found');
        }
        if ($attributeId <= 0) {
            $this->failValidation('Attributo obbligatorio', 'attribute_definition_not_found');
        }

        $allowedTypes = array_keys($this->ruleTypeLabels());
        if (!in_array($ruleType, $allowedTypes, true)) {
            $this->failValidation('Tipo regola non valido', 'attribute_rule_invalid');
        }
        if ($value === '') {
            $this->failValidation('Valore regola obbligatorio', 'attribute_rule_invalid');
        }
        if ($ruleType !== 'suggestion' && !is_numeric(str_replace(',', '.', $value))) {
            $this->failValidation('Il valore regola deve essere numerico', 'attribute_rule_invalid');
        }

        $this->findArchetypeExists($archetypeId);
        $this->findAttributeExists($attributeId);

        if ($id > 0) {
            $current = $this->firstPrepared(
                'SELECT id
                 FROM lf_archetype_attribute_rules
                 WHERE id = ?
                 LIMIT 1',
                [$id],
            );
            if (empty($current)) {
                $this->failValidation('Regola non trovata', 'attribute_rule_invalid');
            }

            $this->execPrepared(
                'UPDATE lf_archetype_attribute_rules
                 SET archetype_id = ?,
                     attribute_id = ?,
                     rule_type = ?,
                     value = ?,
                     is_enforced = ?,
                     priority = ?,
                     notes = ?,
                     date_updated = NOW()
                 WHERE id = ?',
                [$archetypeId, $attributeId, $ruleType, $value, $isEnforced, $priority, $notes, $id],
            );
            return $id;
        }

        $this->execPrepared(
            'INSERT INTO lf_archetype_attribute_rules
                (archetype_id, attribute_id, rule_type, value, is_enforced, priority, notes, date_created, date_updated)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [$archetypeId, $attributeId, $ruleType, $value, $isEnforced, $priority, $notes],
        );

        return (int) $this->db->lastInsertId();
    }

    public function deleteRule(int $id): void
    {
        if ($id <= 0) {
            $this->failValidation('Regola non valida', 'attribute_rule_invalid');
        }

        $this->execPrepared(
            'DELETE FROM lf_archetype_attribute_rules
             WHERE id = ?',
            [$id],
        );
    }

    public function characterCreateBootstrap(): array
    {
        return [
            'enabled' => $this->ensureAttributesEnabled() ? 1 : 0,
        ];
    }

    public function characterCreateRules(array $archetypeIds): array
    {
        if (!$this->ensureAttributesEnabled()) {
            return [
                'enabled' => 0,
                'dataset' => [],
            ];
        }

        $resolved = $this->resolveRuleSet($archetypeIds);
        return [
            'enabled' => 1,
            'dataset' => array_values($resolved),
        ];
    }

    public function validateCharacterCreate(object $payload): void
    {
        if (!$this->ensureAttributesEnabled()) {
            return;
        }

        $rawIds = [];
        if (isset($payload->archetype_ids) && is_array($payload->archetype_ids)) {
            $rawIds = $payload->archetype_ids;
        } elseif (isset($payload->archetype_id)) {
            $rawIds = [$payload->archetype_id];
        }

        $resolved = $this->resolveRuleSet($rawIds);
        if ($resolved === []) {
            return;
        }

        $this->buildCharacterCreateEntries($payload, $resolved);
    }

    public function applyCharacterCreate(int $characterId, object $payload): void
    {
        if ($characterId <= 0 || !$this->ensureAttributesEnabled()) {
            return;
        }

        $rawIds = [];
        if (isset($payload->archetype_ids) && is_array($payload->archetype_ids)) {
            $rawIds = $payload->archetype_ids;
        } elseif (isset($payload->archetype_id)) {
            $rawIds = [$payload->archetype_id];
        }

        $resolved = $this->resolveRuleSet($rawIds);
        if ($resolved === []) {
            return;
        }

        $entries = $this->buildCharacterCreateEntries($payload, $resolved);
        if ($entries === []) {
            return;
        }

        $this->attributeFacade->updateCharacterValues($characterId, $entries);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getCharacterAttributeModifiers(int $characterId): array
    {
        if ($characterId <= 0 || !$this->ensureAttributesEnabled()) {
            return [];
        }

        $archetypeIds = $this->assignedArchetypeIdsForCharacter($characterId);
        if ($archetypeIds === []) {
            return [];
        }

        $rows = $this->rulesByArchetypes($archetypeIds);
        if ($rows === []) {
            return [];
        }

        $modifiers = [];
        foreach ($rows as $row) {
            $type = trim((string) ($row->rule_type ?? ''));
            if ($type !== 'bonus') {
                continue;
            }

            $valueRaw = trim((string) ($row->value ?? ''));
            if (!is_numeric(str_replace(',', '.', $valueRaw))) {
                continue;
            }

            $attributeSlug = trim((string) ($row->attribute_slug ?? ''));
            if ($attributeSlug === '') {
                continue;
            }

            $numeric = round((float) str_replace(',', '.', $valueRaw), 2);
            if (abs($numeric) < 0.0000001) {
                continue;
            }

            $archetypeName = trim((string) ($row->archetype_name ?? ''));
            $attributeName = trim((string) ($row->attribute_name ?? ''));
            $label = $archetypeName !== ''
                ? $archetypeName . ($attributeName !== '' ? ' -> ' . $attributeName : '')
                : ($attributeName !== '' ? $attributeName : 'Archetype bonus');

            $modifiers[] = [
                'source_system' => 'archetype_attributes',
                'source_type' => 'archetype',
                'source_id' => (int) ($row->archetype_id ?? 0),
                'source_label' => $label,
                'attribute_slug' => $attributeSlug,
                'operation' => 'add',
                'value' => $numeric,
                'priority' => (int) ($row->priority ?? 100),
                'stack_group' => null,
                'is_active' => 1,
                'metadata' => [
                    'rule_id' => (int) ($row->id ?? 0),
                    'rule_type' => 'bonus',
                    'attribute_id' => (int) ($row->attribute_id ?? 0),
                    'archetype_name' => $archetypeName,
                    'attribute_name' => $attributeName,
                ],
            ];
        }

        return $modifiers;
    }

    public function profileRules(int $characterId): array
    {
        if ($characterId <= 0 || !$this->ensureAttributesEnabled()) {
            return [
                'enabled' => 0,
                'character_id' => $characterId,
                'archetypes' => [],
                'dataset' => [],
            ];
        }

        $archetypes = $this->archetypeService->getCharacterArchetypes($characterId);
        $archetypeIds = $this->assignedArchetypeIdsForCharacter($characterId);
        $resolved = $this->resolveRuleSet($archetypeIds);

        $normalizedArchetypes = [];
        foreach ($archetypes as $row) {
            $normalizedArchetypes[] = is_object($row) ? get_object_vars($row) : (array) $row;
        }

        return [
            'enabled' => 1,
            'character_id' => $characterId,
            'archetypes' => $normalizedArchetypes,
            'dataset' => array_values($resolved),
        ];
    }

    public function profileUpdateValues(int $characterId, array $entries): array
    {
        if ($characterId <= 0) {
            $this->failValidation('Personaggio non valido', 'character_invalid');
        }

        $this->attributeFacade->requireEnabled();
        $resolved = $this->resolveRuleSet($this->assignedArchetypeIdsForCharacter($characterId));
        $normalizedEntries = $this->profileUpdateEntries($entries, $resolved);

        if ($normalizedEntries === []) {
            $this->failValidation('Nessun valore da aggiornare', 'attribute_update_forbidden');
        }

        return $this->attributeFacade->updateCharacterValues($characterId, $normalizedEntries);
    }
}
