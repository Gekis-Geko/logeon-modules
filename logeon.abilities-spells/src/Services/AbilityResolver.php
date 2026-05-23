<?php

declare(strict_types=1);

namespace Modules\Logeon\AbilitiesSpells\Services;

use App\Services\AttributeProviderRegistry;
use App\Services\CapabilityRegistry;
use App\Services\CoreCharacterRankProvider;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Hooks;
use Modules\Logeon\Archetypes\Services\ArchetypeProviderRegistry;

class AbilityResolver
{
    private DbAdapterInterface $db;
    private CoreCharacterRankProvider $rankProvider;
    private \App\Contracts\CapabilityRegistryInterface $capabilityRegistry;

    public function __construct(
        DbAdapterInterface $db = null,
        CoreCharacterRankProvider $rankProvider = null,
        \App\Contracts\CapabilityRegistryInterface $capabilityRegistry = null,
    ) {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->rankProvider = $rankProvider ?: new CoreCharacterRankProvider();
        $this->capabilityRegistry = $capabilityRegistry ?: CapabilityRegistry::provider();
    }

    public function resolveForCharacter(int $characterId): array
    {
        if ($characterId <= 0) {
            return [];
        }

        $abilities = $this->fetchAbilities();
        if (empty($abilities)) {
            return [];
        }

        $progressionRows = $this->fetchProgressions($characterId);
        $progressionByAbilityId = [];
        foreach ($progressionRows as $row) {
            $progressionByAbilityId[(int) ($row->ability_id ?? 0)] = $row;
        }

        $sources = $this->collectCharacterSources($characterId);
        $grantsByAbilityId = $this->groupByAbilityId($this->fetchGrantsForSources($sources), 'ability_id');
        $requirementsByAbilityId = $this->groupByAbilityId($this->fetchRequirements(), 'ability_id');
        $effectsByAbilityId = $this->groupByAbilityId($this->fetchEffects(), 'ability_id');
        $levelRulesByAbilityId = $this->groupByAbilityId($this->fetchLevelRules(), 'ability_id');

        $resolved = [];
        foreach ($abilities as $ability) {
            $abilityId = (int) ($ability->id ?? 0);
            if ($abilityId <= 0) {
                continue;
            }

            $progression = $progressionByAbilityId[$abilityId] ?? null;
            $abilityGrants = $grantsByAbilityId[$abilityId] ?? [];
            $abilityRequirements = $requirementsByAbilityId[$abilityId] ?? [];
            $abilityEffects = $effectsByAbilityId[$abilityId] ?? [];
            $abilityLevelRules = $levelRulesByAbilityId[$abilityId] ?? [];

            $activeGrantRows = $this->matchActiveGrants($abilityGrants, $sources, $characterId);
            $sourcePayloads = $this->buildSourcePayloads($activeGrantRows, $sources);
            $currentLevel = max(0, (int) ($progression->level ?? 0));
            $targetLevel = max(1, $currentLevel > 0 ? $currentLevel : 1);
            $nextLevel = max(1, $currentLevel + 1);
            $maxLevel = max(1, (int) ($ability->max_level ?? 1));
            $baseStatus = $this->normalizeStatus((string) ($progression->status ?? ''));
            $hasProgression = $progression !== null;
            $progressionIsActive = !$hasProgression || (int) ($progression->is_active ?? 0) === 1;
            $hasActiveSources = !empty($activeGrantRows);
            $hasGrantRules = !empty($abilityGrants);
            $isUnlocked = $hasActiveSources || $hasProgression;
            $retention = $this->bestRetentionPolicy($abilityGrants);

            $missingCurrentRequirements = $this->evaluateRequirements(
                $characterId,
                $ability,
                $abilityRequirements,
                $targetLevel,
                $progressionByAbilityId,
            );
            $missingNextRequirements = $this->evaluateRequirements(
                $characterId,
                $ability,
                $abilityRequirements,
                min($maxLevel, $nextLevel),
                $progressionByAbilityId,
            );

            $effectiveStatus = $this->resolveStatus(
                $ability,
                $baseStatus,
                $hasProgression,
                $progressionIsActive,
                $hasGrantRules,
                $hasActiveSources,
                $retention,
                !empty($missingCurrentRequirements),
            );

            $requiresLearning = (int) ($ability->requires_learning ?? 0) === 1;
            $isAbilityActive = (int) ($ability->is_active ?? 0) === 1;
            $visible = $this->isVisible($ability, $effectiveStatus, $isUnlocked);
            $pendingUpgrade = $effectiveStatus === 'pending_approval' && $currentLevel > 0;
            $usable = $isAbilityActive
                && ($effectiveStatus === 'learned' || $pendingUpgrade)
                && empty($missingCurrentRequirements);
            $available = $isAbilityActive
                && $effectiveStatus !== 'disabled'
                && $effectiveStatus !== 'locked';
            $learnable = $available
                && $requiresLearning
                && $effectiveStatus !== 'learned'
                && $effectiveStatus !== 'pending_approval'
                && empty($missingCurrentRequirements);
            $upgradeable = $usable
                && !$pendingUpgrade
                && $currentLevel > 0
                && $currentLevel < $maxLevel
                && empty($missingNextRequirements);

            $resolvedEffects = $this->normalizeEffects(
                $ability,
                $abilityEffects,
                $currentLevel > 0 ? $currentLevel : 1,
                $usable,
                $effectiveStatus === 'learned' || $pendingUpgrade,
            );

            $pointsForNextLevel = $this->pointsRequiredForLevel($abilityLevelRules, $nextLevel);
            $pointCategorySlug = (string) ($ability->point_category_slug ?? '');
            $pointCategoryName = (string) ($ability->point_category_name ?? '');

            $resolved[] = [
                'id' => $abilityId,
                'name' => (string) ($ability->name ?? ''),
                'slug' => (string) ($ability->slug ?? ''),
                'description' => (string) ($ability->description ?? ''),
                'type' => (string) ($ability->type ?? 'ability'),
                'target_type' => (string) ($ability->target_type ?? 'self'),
                'effect_mode' => (string) ($ability->effect_mode ?? 'none'),
                'narrative_state_id' => (int) ($ability->narrative_state_id ?? 0),
                'cooldown_seconds' => (int) ($ability->cooldown_seconds ?? 0),
                'sort_order' => (int) ($ability->sort_order ?? 100),
                'applies_state_name' => (string) ($ability->narrative_state_name ?? ''),
                'assignment_id' => (int) ($progression->id ?? 0),
                'assignment_sort_order' => (int) ($progression->sort_order ?? 100),
                'assignment_active' => (int) ($progression->is_active ?? 0),
                'status' => $effectiveStatus,
                'level' => $currentLevel,
                'next_level' => $currentLevel < $maxLevel ? $nextLevel : null,
                'max_level' => $maxLevel,
                'approval_status' => (string) ($progression->approval_status ?? 'approved'),
                'pending_upgrade' => $pendingUpgrade,
                'pending_points' => (int) ($progression->pending_points ?? 0),
                'spent_points' => (int) ($progression->spent_points ?? 0),
                'suspended_reason' => (string) ($progression->suspended_reason ?? ''),
                'visible' => $visible,
                'available' => $available,
                'learnable' => $learnable,
                'upgradeable' => $upgradeable,
                'usable' => $usable,
                'requires_learning' => $requiresLearning,
                'requires_staff_approval' => (int) ($ability->requires_staff_approval ?? 0) === 1,
                'point_category' => $pointCategorySlug,
                'point_category_id' => (int) ($ability->point_category_id ?? 0),
                'point_category_name' => $pointCategoryName,
                'points_required' => $pointsForNextLevel,
                'points_invested' => (int) ($progression->spent_points ?? 0),
                'points_remaining_to_next_level' => max(0, $pointsForNextLevel - (int) ($progression->pending_points ?? 0)),
                'sources' => $sourcePayloads,
                'missing_requirements' => $missingCurrentRequirements,
                'effects' => $resolvedEffects,
            ];
        }

        usort($resolved, static function (array $left, array $right): int {
            $leftStatus = (string) ($left['status'] ?? '');
            $rightStatus = (string) ($right['status'] ?? '');
            $leftLearned = $leftStatus === 'learned' ? 0 : 1;
            $rightLearned = $rightStatus === 'learned' ? 0 : 1;
            if ($leftLearned !== $rightLearned) {
                return $leftLearned <=> $rightLearned;
            }

            $leftSort = (int) ($left['assignment_sort_order'] ?? $left['sort_order'] ?? 100);
            $rightSort = (int) ($right['assignment_sort_order'] ?? $right['sort_order'] ?? 100);
            if ($leftSort !== $rightSort) {
                return $leftSort <=> $rightSort;
            }

            return strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });

        return $resolved;
    }

    private function fetchAbilities(): array
    {
        return $this->db->fetchAllPrepared(
            'SELECT a.id,
                    a.name,
                    a.slug,
                    a.description,
                    a.type,
                    a.category_id,
                    a.point_category_id,
                    a.target_type,
                    a.effect_mode,
                    a.narrative_state_id,
                    a.cooldown_seconds,
                    a.sort_order,
                    a.is_active,
                    a.is_public,
                    a.is_hidden_when_locked,
                    a.requires_learning,
                    a.requires_staff_approval,
                    a.max_level,
                    a.metadata_json,
                    ns.name AS narrative_state_name,
                    pc.slug AS point_category_slug,
                    pc.name AS point_category_name
             FROM lf_abilities_spells_abilities a
             LEFT JOIN narrative_states ns ON ns.id = a.narrative_state_id
             LEFT JOIN lf_abilities_spells_point_categories pc ON pc.id = a.point_category_id
             ORDER BY a.sort_order ASC, a.name ASC, a.id ASC'
        ) ?: [];
    }

    private function fetchProgressions(int $characterId): array
    {
        return $this->db->fetchAllPrepared(
            'SELECT id,
                    character_id,
                    ability_id,
                    status,
                    level,
                    pending_points,
                    spent_points,
                    approval_status,
                    approved_by_user_id,
                    approved_at,
                    suspended_reason,
                    sort_order,
                    is_active
             FROM lf_abilities_spells_character_abilities
             WHERE character_id = ?',
            [$characterId],
        ) ?: [];
    }

    private function fetchGrantsForSources(array $sources): array
    {
        if (empty($sources)) {
            return [];
        }

        $pairs = [];
        foreach ($sources as $source) {
            $sourceType = trim((string) ($source['type'] ?? ''));
            $sourceId = (int) ($source['id'] ?? 0);
            if ($sourceType === '' || $sourceId <= 0) {
                continue;
            }
            $pairs[] = [$sourceType, $sourceId];
        }

        if (empty($pairs)) {
            return [];
        }

        $where = [];
        $params = [];
        foreach ($pairs as [$sourceType, $sourceId]) {
            $where[] = '(source_type = ? AND source_id = ?)';
            $params[] = $sourceType;
            $params[] = $sourceId;
        }

        $sql = 'SELECT id,
                       ability_id,
                       source_type,
                       source_id,
                       grant_mode,
                       retention_policy,
                       min_rank,
                       max_rank,
                       is_active,
                       priority,
                       metadata_json
                FROM lf_abilities_spells_grants
                WHERE is_active = 1
                  AND (' . implode(' OR ', $where) . ')
                ORDER BY priority ASC, id ASC';

        return $this->db->fetchAllPrepared($sql, $params) ?: [];
    }

    private function rowValue($row, string $field, $default = null)
    {
        if (is_array($row)) {
            return array_key_exists($field, $row) ? $row[$field] : $default;
        }
        if (is_object($row) && property_exists($row, $field)) {
            return $row->{$field};
        }
        return $default;
    }

    private function fetchRequirements(): array
    {
        return $this->db->fetchAllPrepared(
            'SELECT id,
                    ability_id,
                    level,
                    requirement_type,
                    requirement_key,
                    operator,
                    required_value,
                    policy_when_unavailable,
                    is_hidden,
                    is_active,
                    metadata_json
             FROM lf_abilities_spells_requirements
             WHERE is_active = 1
             ORDER BY ability_id ASC, level ASC, id ASC'
        ) ?: [];
    }

    private function fetchEffects(): array
    {
        return $this->db->fetchAllPrepared(
            'SELECT id,
                    ability_id,
                    level,
                    effect_type,
                    target_system,
                    target_key,
                    operation,
                    value,
                    activation_policy,
                    policy_when_unavailable,
                    is_active,
                    metadata_json
             FROM lf_abilities_spells_effects
             WHERE is_active = 1
             ORDER BY ability_id ASC, level ASC, id ASC'
        ) ?: [];
    }

    private function fetchLevelRules(): array
    {
        return $this->db->fetchAllPrepared(
            'SELECT id,
                    ability_id,
                    level,
                    points_required,
                    min_rank,
                    requires_staff_approval,
                    metadata_json
             FROM lf_abilities_spells_level_rules
             ORDER BY ability_id ASC, level ASC, id ASC'
        ) ?: [];
    }

    private function collectCharacterSources(int $characterId): array
    {
        $sources = [
            'character:' . $characterId => [
                'type' => 'character',
                'id' => $characterId,
                'label' => 'Personaggio #' . $characterId,
                'metadata' => [],
            ],
        ];

        if ($this->capabilityRegistry->has('character.archetypes')) {
            try {
                $archetypeProvider = ArchetypeProviderRegistry::provider();
                foreach ($archetypeProvider->getCharacterArchetypes($characterId) as $archetype) {
                    $archetypeId = (int) $this->rowValue($archetype, 'id', 0);
                    if ($archetypeId <= 0) {
                        continue;
                    }

                    $label = trim((string) $this->rowValue($archetype, 'name', $this->rowValue($archetype, 'label', '')));
                    $slug = trim((string) $this->rowValue($archetype, 'slug', ''));
                    $sources['archetype:' . $archetypeId] = [
                        'type' => 'archetype',
                        'id' => $archetypeId,
                        'label' => $label !== '' ? $label : ('Archetipo #' . $archetypeId),
                        'slug' => $slug,
                        'metadata' => [],
                    ];
                }
            } catch (\Throwable $e) {
                // Optional system: fall back silently when Archetypes is disabled or unavailable.
            }
        }

        if (class_exists('\\Core\\Hooks')) {
            $filtered = Hooks::filter('character.abilities.sources', array_values($sources), $characterId);
            if (is_array($filtered)) {
                $sources = [];
                foreach ($filtered as $source) {
                    if (!is_array($source)) {
                        continue;
                    }
                    $sourceType = trim((string) ($source['type'] ?? ''));
                    $sourceId = (int) ($source['id'] ?? 0);
                    if ($sourceType === '' || $sourceId <= 0) {
                        continue;
                    }
                    $sources[$sourceType . ':' . $sourceId] = $source;
                }
            }
        }

        return $sources;
    }

    private function groupByAbilityId(array $rows, string $field): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $abilityId = (int) ($row->{$field} ?? 0);
            if ($abilityId <= 0) {
                continue;
            }
            if (!isset($grouped[$abilityId])) {
                $grouped[$abilityId] = [];
            }
            $grouped[$abilityId][] = $row;
        }
        return $grouped;
    }

    private function matchActiveGrants(array $grants, array $sources, int $characterId): array
    {
        if (empty($grants) || empty($sources)) {
            return [];
        }

        $currentRank = null;
        if ($this->capabilityRegistry->has('character.rank')) {
            $currentRank = $this->rankProvider->getRank($characterId);
        }

        $active = [];
        foreach ($grants as $grant) {
            $sourceType = trim((string) ($grant->source_type ?? ''));
            $sourceId = (int) ($grant->source_id ?? 0);
            $key = $sourceType . ':' . $sourceId;
            if (!isset($sources[$key])) {
                continue;
            }

            $minRank = (int) ($grant->min_rank ?? 0);
            $maxRank = (int) ($grant->max_rank ?? 0);
            if ($currentRank !== null && $minRank > 0 && $currentRank < $minRank) {
                continue;
            }
            if ($currentRank !== null && $maxRank > 0 && $currentRank > $maxRank) {
                continue;
            }

            $grantMode = strtolower(trim((string) ($grant->grant_mode ?? 'unlock')));
            if ($grantMode === 'forbid') {
                continue;
            }

            $active[] = $grant;
        }

        return $active;
    }

    private function buildSourcePayloads(array $grants, array $sources): array
    {
        $dataset = [];
        foreach ($grants as $grant) {
            $key = trim((string) ($grant->source_type ?? '')) . ':' . (int) ($grant->source_id ?? 0);
            if (!isset($sources[$key])) {
                continue;
            }

            $source = $sources[$key];
            $dataset[] = [
                'type' => (string) ($source['type'] ?? ''),
                'id' => (int) ($source['id'] ?? 0),
                'label' => (string) ($source['label'] ?? ''),
                'grant_mode' => (string) ($grant->grant_mode ?? 'unlock'),
                'retention_policy' => (string) ($grant->retention_policy ?? 'keep_when_lost'),
            ];
        }

        return $dataset;
    }

    private function bestRetentionPolicy(array $grants): string
    {
        $retention = 'keep_when_lost';
        foreach ($grants as $grant) {
            $candidate = strtolower(trim((string) ($grant->retention_policy ?? '')));
            if ($candidate === 'while_source_active' || $candidate === 'disable_when_lost') {
                return $candidate;
            }
            if ($candidate !== '') {
                $retention = $candidate;
            }
        }
        return $retention;
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === '') {
            return 'available';
        }

        $allowed = ['available', 'learning', 'pending_approval', 'learned', 'suspended', 'disabled'];
        return in_array($status, $allowed, true) ? $status : 'available';
    }

    private function resolveStatus(
        object $ability,
        string $baseStatus,
        bool $hasProgression,
        bool $progressionIsActive,
        bool $hasGrantRules,
        bool $hasActiveSources,
        string $retention,
        bool $hasMissingRequirements,
    ): string {
        if ((int) ($ability->is_active ?? 0) !== 1) {
            return 'disabled';
        }

        if ($hasProgression && (int) ($ability->requires_learning ?? 0) !== 1 && $baseStatus === 'available') {
            $baseStatus = 'learned';
        }

        if ($hasProgression && !$progressionIsActive) {
            return 'disabled';
        }

        if (!$hasProgression && !$hasActiveSources) {
            return 'locked';
        }

        if ($hasGrantRules && !$hasActiveSources && $hasProgression) {
            if ($retention === 'while_source_active' || $retention === 'disable_when_lost') {
                return 'suspended';
            }
        }

        if ($baseStatus === 'learned' && $hasMissingRequirements) {
            return 'suspended';
        }

        if ($baseStatus !== '') {
            return $baseStatus;
        }

        return $hasProgression ? 'learned' : 'available';
    }

    private function isVisible(object $ability, string $status, bool $isUnlocked): bool
    {
        if (in_array($status, ['learned', 'learning', 'pending_approval', 'suspended'], true)) {
            return true;
        }

        $isPublic = (int) ($ability->is_public ?? 0) === 1;
        $hideWhenLocked = (int) ($ability->is_hidden_when_locked ?? 0) === 1;
        if ($status === 'locked' && $hideWhenLocked) {
            return false;
        }

        return $isPublic || $isUnlocked;
    }

    private function evaluateRequirements(
        int $characterId,
        object $ability,
        array $requirements,
        int $targetLevel,
        array $progressionByAbilityId,
    ): array {
        $missing = [];
        foreach ($requirements as $requirement) {
            $requirementLevel = max(1, (int) ($requirement->level ?? 1));
            if ($requirementLevel > $targetLevel) {
                continue;
            }

            $failure = $this->evaluateRequirement($characterId, $requirement, $progressionByAbilityId);
            if ($failure !== null) {
                $missing[] = $failure;
            }
        }

        $minRank = (int) ($this->levelRuleMinRankForLevel((int) ($ability->id ?? 0), $targetLevel) ?? 0);
        if ($minRank > 0) {
            $rankFailure = $this->evaluateSyntheticRankRequirement($characterId, $minRank);
            if ($rankFailure !== null) {
                $missing[] = $rankFailure;
            }
        }

        return $missing;
    }

    private function evaluateRequirement(int $characterId, object $requirement, array $progressionByAbilityId): ?array
    {
        $type = strtolower(trim((string) ($requirement->requirement_type ?? '')));
        $key = trim((string) ($requirement->requirement_key ?? ''));
        $operator = trim((string) ($requirement->operator ?? '>=')); 
        $requiredValueRaw = (string) ($requirement->required_value ?? '');
        $policy = strtolower(trim((string) ($requirement->policy_when_unavailable ?? 'block')));
        $hidden = (int) ($requirement->is_hidden ?? 0) === 1;

        if ($type === 'rank') {
            if (!$this->capabilityRegistry->has('character.rank')) {
                return $this->policyFailure($type, $key, $operator, $requiredValueRaw, $policy, 'rank_unavailable');
            }

            $currentRank = (int) ($this->rankProvider->getRank($characterId) ?? 0);
            if ($this->compareValues($currentRank, $operator, (float) $requiredValueRaw)) {
                return null;
            }

            return $this->buildRequirementFailure($type, $key, $operator, $requiredValueRaw, $currentRank, 'rank_requirement_failed', $hidden);
        }

        if ($type === 'attribute') {
            if (!$this->capabilityRegistry->has('character.attributes')) {
                return $this->policyFailure($type, $key, $operator, $requiredValueRaw, $policy, 'attribute_unavailable');
            }

            $current = AttributeProviderRegistry::getValue($characterId, $key);
            if ($current !== null && $this->compareValues((float) $current, $operator, (float) $requiredValueRaw)) {
                return null;
            }

            return $this->buildRequirementFailure($type, $key, $operator, $requiredValueRaw, $current, 'attribute_requirement_failed', $hidden);
        }

        if ($type === 'ability') {
            $requiredAbility = $this->findAbilityProgressionLevel($key, $requiredValueRaw, $progressionByAbilityId);
            if ($this->compareValues((float) ($requiredAbility['current_level'] ?? 0), $operator, (float) ($requiredAbility['required_level'] ?? 0))) {
                return null;
            }

            return $this->buildRequirementFailure(
                $type,
                (string) ($requiredAbility['ability_slug'] ?? $key),
                $operator,
                (string) ($requiredAbility['required_level'] ?? $requiredValueRaw),
                (int) ($requiredAbility['current_level'] ?? 0),
                'ability_requirement_failed',
                $hidden,
            );
        }

        if ($type === 'archetype') {
            if (!$this->capabilityRegistry->has('character.archetypes')) {
                return $this->policyFailure($type, $key, $operator, $requiredValueRaw, $policy, 'archetype_unavailable');
            }

            try {
                $rows = ArchetypeProviderRegistry::provider()->getCharacterArchetypes($characterId);
            } catch (\Throwable $e) {
                return $this->policyFailure($type, $key, $operator, $requiredValueRaw, $policy, 'archetype_unavailable');
            }

            $current = $this->matchesArchetypeRequirement($rows, $key) ? 1 : 0;
            $required = $requiredValueRaw === '' ? 1 : (float) $requiredValueRaw;
            if ($this->compareValues((float) $current, $operator, $required)) {
                return null;
            }

            return $this->buildRequirementFailure($type, $key, $operator, (string) $required, $current, 'archetype_requirement_failed', $hidden);
        }

        return null;
    }

    private function evaluateSyntheticRankRequirement(int $characterId, int $minRank): ?array
    {
        if (!$this->capabilityRegistry->has('character.rank')) {
            return $this->policyFailure('rank', 'character_rank', '>=', (string) $minRank, 'block', 'rank_unavailable');
        }

        $currentRank = (int) ($this->rankProvider->getRank($characterId) ?? 0);
        if ($currentRank >= $minRank) {
            return null;
        }

        return $this->buildRequirementFailure('rank', 'character_rank', '>=', (string) $minRank, $currentRank, 'rank_requirement_failed');
    }

    private function policyFailure(
        string $type,
        string $key,
        string $operator,
        string $requiredValue,
        string $policy,
        string $reason,
    ): ?array {
        if ($policy === 'ignore') {
            return null;
        }

        return $this->buildRequirementFailure($type, $key, $operator, $requiredValue, null, $reason, $policy === 'hide');
    }

    private function buildRequirementFailure(
        string $type,
        string $key,
        string $operator,
        string $requiredValue,
        $currentValue,
        string $reason,
        bool $hidden = false,
    ): array {
        return [
            'type' => $type,
            'key' => $key,
            'operator' => $operator,
            'required' => $requiredValue,
            'current' => $currentValue,
            'reason' => $reason,
            'hidden' => $hidden,
        ];
    }

    private function compareValues(float $currentValue, string $operator, float $requiredValue): bool
    {
        switch ($operator) {
            case '=':
            case '==':
                return $currentValue === $requiredValue;
            case '!=':
                return $currentValue !== $requiredValue;
            case '>':
                return $currentValue > $requiredValue;
            case '>=':
                return $currentValue >= $requiredValue;
            case '<':
                return $currentValue < $requiredValue;
            case '<=':
                return $currentValue <= $requiredValue;
            default:
                return $currentValue >= $requiredValue;
        }
    }

    private function findAbilityProgressionLevel(string $abilityKey, string $requiredValueRaw, array $progressionByAbilityId): array
    {
        $requiredLevel = max(1, (int) $requiredValueRaw);
        $currentLevel = 0;
        $resolvedSlug = $abilityKey;

        if ($abilityKey !== '') {
            $ability = $this->db->fetchOnePrepared(
                'SELECT id, slug
                 FROM lf_abilities_spells_abilities
                 WHERE slug = ?
                    OR id = ?
                 LIMIT 1',
                [$abilityKey, (int) $abilityKey],
            );
            $abilityId = (int) ($ability->id ?? 0);
            if ($abilityId > 0) {
                $resolvedSlug = (string) ($ability->slug ?? $abilityKey);
                $progression = $progressionByAbilityId[$abilityId] ?? null;
                $currentLevel = max(0, (int) ($progression->level ?? 0));
            }
        }

        return [
            'ability_slug' => $resolvedSlug,
            'required_level' => $requiredLevel,
            'current_level' => $currentLevel,
        ];
    }

    private function matchesArchetypeRequirement(array $rows, string $key): bool
    {
        $needle = strtolower(trim($key));
        foreach ($rows as $row) {
            $id = (string) $this->rowValue($row, 'id', '');
            $slug = strtolower(trim((string) $this->rowValue($row, 'slug', '')));
            $name = strtolower(trim((string) $this->rowValue($row, 'name', '')));
            if ($needle !== '' && ($needle === strtolower($id) || $needle === $slug || $needle === $name)) {
                return true;
            }
        }
        return false;
    }

    private function normalizeEffects(
        object $ability,
        array $effectRows,
        int $currentLevel,
        bool $usable,
        bool $learned,
    ): array {
        $effects = [];

        foreach ($effectRows as $row) {
            $effectLevel = max(1, (int) ($row->level ?? 1));
            if ($effectLevel > max(1, $currentLevel)) {
                continue;
            }

            $activationPolicy = strtolower(trim((string) ($row->activation_policy ?? 'while_ability_usable')));
            $active = false;
            if ($activationPolicy === 'while_ability_learned') {
                $active = $learned;
            } elseif ($activationPolicy === 'while_ability_usable') {
                $active = $usable;
            }

            $effects[] = [
                'id' => (int) ($row->id ?? 0),
                'type' => (string) ($row->effect_type ?? 'modifier'),
                'target_system' => (string) ($row->target_system ?? ''),
                'target_key' => (string) ($row->target_key ?? ''),
                'operation' => (string) ($row->operation ?? 'add'),
                'value' => (float) ($row->value ?? 0),
                'activation_policy' => $activationPolicy,
                'policy_when_unavailable' => (string) ($row->policy_when_unavailable ?? 'ignore'),
                'active' => $active,
                'source' => 'generic',
            ];
        }

        $legacyMode = strtolower(trim((string) ($ability->effect_mode ?? 'none')));
        $legacyStateId = (int) ($ability->narrative_state_id ?? 0);
        if ($legacyMode !== 'none' && $legacyStateId > 0) {
            $effects[] = [
                'id' => 0,
                'type' => 'narrative_state',
                'target_system' => 'narrative_states',
                'target_key' => (string) $legacyStateId,
                'operation' => $legacyMode === 'remove_state' ? 'remove' : 'apply',
                'value' => 1.0,
                'activation_policy' => 'on_use',
                'policy_when_unavailable' => 'block',
                'active' => $usable,
                'source' => 'legacy',
            ];
        }

        return $effects;
    }

    private function pointsRequiredForLevel(array $levelRules, int $level): int
    {
        foreach ($levelRules as $rule) {
            if ((int) ($rule->level ?? 0) === $level) {
                return max(0, (int) ($rule->points_required ?? 0));
            }
        }

        return 0;
    }

    private function levelRuleMinRankForLevel(int $abilityId, int $level): ?int
    {
        if ($abilityId <= 0) {
            return null;
        }

        $rule = $this->db->fetchOnePrepared(
            'SELECT min_rank
             FROM lf_abilities_spells_level_rules
             WHERE ability_id = ?
               AND level = ?
             LIMIT 1',
            [$abilityId, $level],
        );

        return $rule !== null ? (int) ($rule->min_rank ?? 0) : null;
    }
}
