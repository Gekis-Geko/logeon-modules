<?php

declare(strict_types=1);

namespace Modules\Logeon\CombatEnvironment\Services;

use App\Services\ConflictService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;
use Core\ModuleManager;

class CombatEnvironmentService
{
    private const MODULE_ID = 'logeon.combat-environment';
    private const DEPENDENCY_MODULE_ID = 'logeon.narrative-combat';
    private const SETTING_COMPLEXITY_MODE = 'environment_complexity_mode';
    private const REQUIRED_BASE_TIER = 2;

    /** @var DbAdapterInterface */
    private $db;
    /** @var ModuleManager|null */
    private $moduleManager = null;
    /** @var ConflictService|null */
    private $conflictService = null;

    public function __construct(
        DbAdapterInterface $db = null,
        ModuleManager $moduleManager = null,
        ConflictService $conflictService = null,
    ) {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->moduleManager = $moduleManager;
        $this->conflictService = $conflictService;
    }

    private function moduleManager(): ModuleManager
    {
        if ($this->moduleManager instanceof ModuleManager) {
            return $this->moduleManager;
        }

        $this->moduleManager = new ModuleManager($this->db);
        return $this->moduleManager;
    }

    private function conflictService(): ConflictService
    {
        if ($this->conflictService instanceof ConflictService) {
            return $this->conflictService;
        }

        $this->conflictService = new ConflictService($this->db);
        return $this->conflictService;
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

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function jsonEncode($value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : '[]';
    }

    private function decodeJsonArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeText($value, int $max = 255): string
    {
        return mb_substr(trim((string) $value), 0, $max);
    }

    private function clampInt(int $value, int $min, int $max): int
    {
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }
        return $value;
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

    private function complexityMode(): string
    {
        $raw = strtolower(trim((string) $this->moduleManager()->getSetting(
            self::MODULE_ID,
            self::SETTING_COMPLEXITY_MODE,
            'standard',
        )));

        if (!in_array($raw, ['minimal', 'standard', 'advanced'], true)) {
            return 'standard';
        }

        return $raw;
    }

    private function rawBaseCombatTierLevel(): int
    {
        $tier = (int) $this->moduleManager()->getSetting(
            self::DEPENDENCY_MODULE_ID,
            'combat_depth',
            self::REQUIRED_BASE_TIER,
        );

        return $tier <= 1 ? 1 : self::REQUIRED_BASE_TIER;
    }

    private function baseCombatTierLevel(): int
    {
        $tier = $this->rawBaseCombatTierLevel();
        if ($tier < self::REQUIRED_BASE_TIER && $this->moduleManager()->isActive(self::MODULE_ID)) {
            return self::REQUIRED_BASE_TIER;
        }

        return $tier;
    }

    private function ensureBaseTier2Enabled(): void
    {
        if ($this->baseCombatTierLevel() >= self::REQUIRED_BASE_TIER) {
            return;
        }

        throw AppError::validation(
            'Il modulo ambiente avanzato richiede che logeon.narrative-combat sia impostato almeno su Tier 2.',
            [],
            'combat_environment_requires_tier2',
        );
    }

    public function enforceActivationTierAlignment(): array
    {
        $rawTier = $this->rawBaseCombatTierLevel();
        if ($rawTier >= self::REQUIRED_BASE_TIER) {
            return [
                'base_tier_promoted' => false,
                'contexts_upgraded' => false,
            ];
        }

        $this->moduleManager()->setSetting(
            self::DEPENDENCY_MODULE_ID,
            'combat_depth',
            self::REQUIRED_BASE_TIER,
        );

        $contextsUpgraded = false;
        if ($this->hasTable('combat_contexts')) {
            $this->execPrepared(
                'UPDATE combat_contexts
                 SET tier_level = ?, updated_at = ?
                 WHERE tier_level < ?',
                [
                    self::REQUIRED_BASE_TIER,
                    $this->now(),
                    self::REQUIRED_BASE_TIER,
                ],
            );
            $contextsUpgraded = true;
        }

        return [
            'base_tier_promoted' => true,
            'contexts_upgraded' => $contextsUpgraded,
        ];
    }

    private function ensureFeatureTables(): void
    {
        $featureTable = $this->firstPrepared(
            'SELECT COUNT(*) AS c
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?
             LIMIT 1',
            ['combat_environment_features'],
        );
        if (empty($featureTable) || (int) ($featureTable->c ?? 0) <= 0) {
            throw AppError::validation(
                'Schema modulo ambiente avanzato non disponibile. Attiva o reinstalla il modulo.',
                [],
                'combat_environment_schema_missing',
            );
        }
    }

    private function normalizeFeatureType($value): string
    {
        $type = strtolower(trim((string) $value));
        $allowed = ['cover', 'hazard', 'utility', 'chokepoint', 'terrain'];
        if (!in_array($type, $allowed, true)) {
            return 'utility';
        }

        return $type;
    }

    private function normalizeStateKey($value): string
    {
        $state = strtolower(trim((string) $value));
        $allowed = ['active', 'fortified', 'compromised', 'escalated', 'stabilized', 'blocked', 'open'];
        if (!in_array($state, $allowed, true)) {
            return 'active';
        }

        return $state;
    }

    private function normalizeAffordanceTags($raw): array
    {
        if (is_array($raw)) {
            $source = $raw;
        } else {
            $source = preg_split('/[\s,;|]+/', (string) $raw) ?: [];
        }

        $tags = [];
        foreach ($source as $value) {
            $tag = strtolower(trim((string) $value));
            $tag = (string) preg_replace('/[^a-z0-9_-]+/', '-', $tag);
            $tag = trim($tag, '-');
            if ($tag !== '') {
                $tags[$tag] = $tag;
            }
        }

        return array_values($tags);
    }

    private function ensureConflictAccessible(int $conflictId, int $viewerCharacterId, bool $isStaff): array
    {
        $detail = $this->conflictService()->getConflict($conflictId);
        if ($isStaff) {
            return $detail;
        }

        $allowed = false;
        $openedBy = (int) (($detail['conflict']->opened_by ?? 0));
        if ($viewerCharacterId > 0 && $openedBy === $viewerCharacterId) {
            $allowed = true;
        }

        if (!$allowed) {
            foreach ((array) ($detail['participants'] ?? []) as $participant) {
                if ((int) ($participant->character_id ?? 0) === $viewerCharacterId) {
                    $allowed = true;
                    break;
                }
            }
        }

        if (
            !$allowed
            && $viewerCharacterId > 0
            && !empty($detail['conflict'])
            && (int) ($detail['conflict']->location_id ?? 0) > 0
        ) {
            $access = (new \Locations())->canAccess((int) ($detail['conflict']->location_id ?? 0), $viewerCharacterId);
            $allowed = !empty($access['allowed']);
        }

        if ($allowed) {
            return $detail;
        }

        throw AppError::unauthorized('Operazione non autorizzata sul contesto ambientale di combattimento.', [], 'combat_environment_access_forbidden');
    }

    private function listCombatContexts(): array
    {
        $rows = $this->fetchPrepared(
            'SELECT cc.conflict_id, cc.status AS combat_status, cc.tier_level, c.location_id, c.status AS conflict_status
             FROM combat_contexts cc
             LEFT JOIN conflicts c ON c.id = cc.conflict_id
             ORDER BY cc.updated_at DESC, cc.conflict_id DESC',
        );

        $dataset = [];
        foreach ($rows as $row) {
            $dataset[] = [
                'conflict_id' => (int) ($row->conflict_id ?? 0),
                'label' => 'Conflitto #' . (int) ($row->conflict_id ?? 0),
                'combat_status' => (string) ($row->combat_status ?? 'active'),
                'conflict_status' => (string) ($row->conflict_status ?? ''),
                'location_id' => (int) ($row->location_id ?? 0),
                'tier_level' => (int) ($row->tier_level ?? 1),
            ];
        }

        return $dataset;
    }

    private function featureLabel(string $type): string
    {
        $map = [
            'cover' => 'Copertura',
            'hazard' => 'Pericolo',
            'utility' => 'Interazione',
            'chokepoint' => 'Chokepoint',
            'terrain' => 'Terreno',
        ];

        return $map[$type] ?? ucfirst($type);
    }

    private function stateLabel(string $state): string
    {
        $map = [
            'active' => 'Attivo',
            'fortified' => 'Fortificato',
            'compromised' => 'Compromesso',
            'escalated' => 'Escalato',
            'stabilized' => 'Stabilizzato',
            'blocked' => 'Bloccato',
            'open' => 'Aperto',
        ];

        return $map[$state] ?? ucfirst($state);
    }

    private function featureDataset(array $rows): array
    {
        $dataset = [];
        foreach ($rows as $row) {
            $featureType = $this->normalizeFeatureType($row->feature_type ?? 'utility');
            $stateKey = $this->normalizeStateKey($row->state_key ?? 'active');
            $dataset[] = [
                'id' => (int) ($row->id ?? 0),
                'conflict_id' => (int) ($row->conflict_id ?? 0),
                'zone_key' => (string) ($row->zone_key ?? ''),
                'feature_name' => (string) ($row->feature_name ?? ''),
                'feature_type' => $featureType,
                'feature_type_label' => $this->featureLabel($featureType),
                'state_key' => $stateKey,
                'state_label' => $this->stateLabel($stateKey),
                'control_side_key' => (string) ($row->control_side_key ?? ''),
                'description' => (string) ($row->description ?? ''),
                'visibility_impact' => (int) ($row->visibility_impact ?? 0),
                'mobility_impact' => (int) ($row->mobility_impact ?? 0),
                'hazard_impact' => (int) ($row->hazard_impact ?? 0),
                'cover_impact' => (int) ($row->cover_impact ?? 0),
                'affordance_tags' => $this->decodeJsonArray($row->affordance_tags_json ?? '[]'),
                'is_active' => (int) ($row->is_active ?? 0),
                'created_by' => (int) ($row->created_by ?? 0),
                'created_at' => (string) ($row->created_at ?? ''),
                'updated_at' => (string) ($row->updated_at ?? ''),
            ];
        }

        return $dataset;
    }

    private function listFeatures(int $conflictId = 0, bool $onlyActive = false): array
    {
        $sql = 'SELECT *
                FROM combat_environment_features
                WHERE 1 = 1';
        $params = [];
        if ($conflictId > 0) {
            $sql .= ' AND conflict_id = ?';
            $params[] = $conflictId;
        }
        if ($onlyActive) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY conflict_id DESC, zone_key ASC, feature_name ASC, id ASC';

        return $this->featureDataset($this->fetchPrepared($sql, $params));
    }

    private function opportunityActions(array $feature, string $actorTeamKey): array
    {
        $stateKey = (string) ($feature['state_key'] ?? 'active');
        $type = (string) ($feature['feature_type'] ?? 'utility');
        $sideKey = (string) ($feature['control_side_key'] ?? '');
        $actions = [];

        if ($type === 'cover' || $type === 'chokepoint') {
            if ($stateKey !== 'fortified') {
                $actions[] = ['key' => 'secure', 'label' => 'Consolida'];
            }
            if ($stateKey !== 'compromised') {
                $actions[] = ['key' => 'compromise', 'label' => 'Comprometti'];
            }
        }

        if ($type === 'hazard') {
            if ($stateKey !== 'escalated') {
                $actions[] = ['key' => 'escalate', 'label' => 'Escala pericolo'];
            }
            if ($stateKey !== 'stabilized') {
                $actions[] = ['key' => 'stabilize', 'label' => 'Stabilizza'];
            }
        }

        if ($sideKey !== $actorTeamKey || $sideKey === '') {
            $actions[] = ['key' => 'claim', 'label' => 'Rivendica controllo'];
        }

        return $actions;
    }

    private function buildOpportunities(array $features, string $actorTeamKey): array
    {
        $out = [];
        foreach ($features as $feature) {
            if ((int) ($feature['is_active'] ?? 0) !== 1) {
                continue;
            }
            foreach ($this->opportunityActions($feature, $actorTeamKey) as $action) {
                $out[] = [
                    'feature_id' => (int) ($feature['id'] ?? 0),
                    'feature_name' => (string) ($feature['feature_name'] ?? ''),
                    'zone_key' => (string) ($feature['zone_key'] ?? ''),
                    'action_key' => (string) ($action['key'] ?? ''),
                    'action_label' => (string) ($action['label'] ?? ''),
                    'description' => (string) ($feature['feature_type_label'] ?? '') . ' in stato ' . (string) ($feature['state_label'] ?? ''),
                ];
            }
        }

        return $out;
    }

    private function buildZoneSummary(array $features): array
    {
        $zones = [];
        foreach ($features as $feature) {
            $zoneKey = trim((string) ($feature['zone_key'] ?? ''));
            if ($zoneKey === '') {
                $zoneKey = 'global';
            }
            if (!isset($zones[$zoneKey])) {
                $zones[$zoneKey] = [
                    'zone_key' => $zoneKey,
                    'features' => 0,
                    'hazards' => 0,
                    'cover_nodes' => 0,
                ];
            }

            $zones[$zoneKey]['features'] += 1;
            if ((string) ($feature['feature_type'] ?? '') === 'hazard') {
                $zones[$zoneKey]['hazards'] += 1;
            }
            if ((string) ($feature['feature_type'] ?? '') === 'cover') {
                $zones[$zoneKey]['cover_nodes'] += 1;
            }
        }

        return array_values($zones);
    }

    private function actorTeamKey(int $conflictId, int $actorCharacterId): string
    {
        if ($actorCharacterId <= 0) {
            return '';
        }

        $row = $this->firstPrepared(
            'SELECT team_key
             FROM combat_participant_states
             WHERE conflict_id = ?
               AND character_id = ?
             LIMIT 1',
            [$conflictId, $actorCharacterId],
        );

        return trim((string) ($row->team_key ?? ''));
    }

    public function adminBootstrap(): array
    {
        $this->ensureFeatureTables();

        return [
            'settings' => [
                'environment_complexity_mode' => $this->complexityMode(),
            ],
            'contexts' => $this->listCombatContexts(),
            'features' => $this->listFeatures(),
            'base_combat_tier' => $this->baseCombatTierLevel(),
        ];
    }

    public function updateSettings($data): array
    {
        $mode = strtolower(trim((string) ($data->environment_complexity_mode ?? 'standard')));
        if (!in_array($mode, ['minimal', 'standard', 'advanced'], true)) {
            $mode = 'standard';
        }

        $this->moduleManager()->setSetting(self::MODULE_ID, self::SETTING_COMPLEXITY_MODE, $mode);
        return $this->adminBootstrap();
    }

    public function saveFeature($data, int $userId): int
    {
        $this->ensureFeatureTables();
        $this->ensureBaseTier2Enabled();

        $id = (int) ($data->id ?? 0);
        $conflictId = (int) ($data->conflict_id ?? 0);
        $featureName = $this->normalizeText($data->feature_name ?? '', 160);
        $featureType = $this->normalizeFeatureType($data->feature_type ?? 'utility');
        $stateKey = $this->normalizeStateKey($data->state_key ?? 'active');
        $zoneKey = $this->normalizeText($data->zone_key ?? '', 80);
        $controlSideKey = $this->normalizeText($data->control_side_key ?? '', 40);
        $description = $this->normalizeText($data->description ?? '', 1200);
        $visibilityImpact = $this->clampInt((int) ($data->visibility_impact ?? 0), -10, 10);
        $mobilityImpact = $this->clampInt((int) ($data->mobility_impact ?? 0), -10, 10);
        $hazardImpact = $this->clampInt((int) ($data->hazard_impact ?? 0), -10, 10);
        $coverImpact = $this->clampInt((int) ($data->cover_impact ?? 0), -10, 10);
        $tags = $this->normalizeAffordanceTags($data->affordance_tags ?? []);
        $isActive = (int) ($data->is_active ?? 1) === 1 ? 1 : 0;

        if ($conflictId <= 0) {
            throw AppError::validation('Conflitto non valido.', [], 'combat_environment_conflict_invalid');
        }
        if ($featureName === '') {
            throw AppError::validation('Nome feature obbligatorio.', [], 'combat_environment_feature_name_required');
        }

        if ($id > 0) {
            $this->execPrepared(
                'UPDATE combat_environment_features
                 SET conflict_id = ?, zone_key = ?, feature_name = ?, feature_type = ?, state_key = ?, control_side_key = ?, description = ?,
                     visibility_impact = ?, mobility_impact = ?, hazard_impact = ?, cover_impact = ?, affordance_tags_json = ?, is_active = ?, updated_at = ?
                 WHERE id = ? LIMIT 1',
                [
                    $conflictId,
                    $zoneKey !== '' ? $zoneKey : null,
                    $featureName,
                    $featureType,
                    $stateKey,
                    $controlSideKey !== '' ? $controlSideKey : null,
                    $description !== '' ? $description : null,
                    $visibilityImpact,
                    $mobilityImpact,
                    $hazardImpact,
                    $coverImpact,
                    $this->jsonEncode($tags),
                    $isActive,
                    $this->now(),
                    $id,
                ],
            );

            return $id;
        }

        $this->execPrepared(
            'INSERT INTO combat_environment_features
                (conflict_id, zone_key, feature_name, feature_type, state_key, control_side_key, description,
                 visibility_impact, mobility_impact, hazard_impact, cover_impact, affordance_tags_json,
                 is_active, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $conflictId,
                $zoneKey !== '' ? $zoneKey : null,
                $featureName,
                $featureType,
                $stateKey,
                $controlSideKey !== '' ? $controlSideKey : null,
                $description !== '' ? $description : null,
                $visibilityImpact,
                $mobilityImpact,
                $hazardImpact,
                $coverImpact,
                $this->jsonEncode($tags),
                $isActive,
                $userId > 0 ? $userId : null,
                $this->now(),
                $this->now(),
            ],
        );

        return (int) $this->db->lastInsertId();
    }

    public function deleteFeature(int $id): void
    {
        $this->ensureFeatureTables();
        if ($id <= 0) {
            throw AppError::validation('Feature non valida.', [], 'combat_environment_feature_invalid');
        }

        $this->execPrepared(
            'DELETE FROM combat_environment_feature_logs
             WHERE feature_id = ?',
            [$id],
        );
        $this->execPrepared(
            'DELETE FROM combat_environment_features
             WHERE id = ?
             LIMIT 1',
            [$id],
        );
    }

    public function buildStateAddon(array $baseState, int $conflictId, int $viewerCharacterId, bool $isStaff): array
    {
        $this->ensureFeatureTables();
        $mode = $this->complexityMode();
        $baseTier = $this->baseCombatTierLevel();
        if ($conflictId <= 0) {
            return [
                'enabled' => false,
                'message' => 'Nessun conflitto selezionato.',
                'complexity_mode' => $mode,
            ];
        }
        if ($baseTier < 2) {
            return [
                'enabled' => false,
                'message' => 'Il modulo ambiente avanzato richiede Narrative Combat su Tier 2.',
                'complexity_mode' => $mode,
            ];
        }

        $this->ensureConflictAccessible($conflictId, $viewerCharacterId, $isStaff);
        $features = $this->listFeatures($conflictId, true);
        $actorTeamKey = $this->actorTeamKey($conflictId, $viewerCharacterId);
        $opportunities = $mode === 'minimal' ? [] : $this->buildOpportunities($features, $actorTeamKey);

        return [
            'enabled' => true,
            'complexity_mode' => $mode,
            'feature_count' => count($features),
            'features' => $features,
            'opportunities' => $opportunities,
            'zone_summary' => $this->buildZoneSummary($features),
            'base_tier_level' => (int) ($baseState['tier_level'] ?? $baseTier),
            'debug_metrics' => $isStaff ? [
                'active_features' => count($features),
                'opportunities' => count($opportunities),
            ] : [],
        ];
    }

    public function applyScoreModifiers(
        array $payload,
        array $intent,
        array $actorState,
        ?array $targetState,
        ?array $baseEnvironmentRaw,
    ): array {
        $this->ensureFeatureTables();
        if ($this->baseCombatTierLevel() < 2) {
            return $payload;
        }

        $mode = $this->complexityMode();
        if ($mode === 'minimal') {
            return $payload;
        }

        $conflictId = (int) ($intent['conflict_id'] ?? 0);
        if ($conflictId <= 0) {
            return $payload;
        }

        $features = $this->listFeatures($conflictId, true);
        if ($features === []) {
            return $payload;
        }

        $actionType = strtolower(trim((string) ($intent['action_type'] ?? '')));
        $actorTeamKey = trim((string) ($actorState['team_key'] ?? ''));
        $targetTeamKey = $targetState !== null ? trim((string) ($targetState['team_key'] ?? '')) : '';
        $actorScore = (int) ($payload['actor_score'] ?? 0);
        $targetScore = (int) ($payload['target_score'] ?? 0);
        $modifiers = is_array($payload['modifiers'] ?? null) ? $payload['modifiers'] : [];

        foreach ($features as $feature) {
            $type = (string) ($feature['feature_type'] ?? 'utility');
            $stateKey = (string) ($feature['state_key'] ?? 'active');
            $sideKey = trim((string) ($feature['control_side_key'] ?? ''));
            $coverImpact = max(0, (int) ($feature['cover_impact'] ?? 0));
            $hazardImpact = max(0, (int) ($feature['hazard_impact'] ?? 0));
            $mobilityImpact = max(0, abs((int) ($feature['mobility_impact'] ?? 0)));
            $visibilityImpact = max(0, abs((int) ($feature['visibility_impact'] ?? 0)));

            if ($type === 'cover') {
                $coverBonus = $this->clampInt((int) ceil($coverImpact / 3), 0, 2) + ($stateKey === 'fortified' ? 1 : 0);
                if ($coverBonus > 0 && $targetTeamKey !== '' && $sideKey !== '' && $sideKey === $targetTeamKey && $actionType === 'strike') {
                    $targetScore += $coverBonus;
                    $modifiers[] = $feature['feature_name'] . ': copertura del bersaglio +' . $coverBonus;
                }
                if ($coverBonus > 0 && $actorTeamKey !== '' && $sideKey !== '' && $sideKey === $actorTeamKey && in_array($actionType, ['defend', 'protect'], true)) {
                    $actorScore += $coverBonus;
                    $modifiers[] = $feature['feature_name'] . ': copertura difensiva +' . $coverBonus;
                }
                continue;
            }

            if ($type === 'hazard') {
                $hazardPenalty = $this->clampInt((int) ceil($hazardImpact / 3), 0, 2) + ($stateKey === 'escalated' ? 1 : 0);
                if ($hazardPenalty > 0 && in_array($actionType, ['reposition', 'disengage', 'recover'], true)) {
                    $actorScore -= $hazardPenalty;
                    $modifiers[] = $feature['feature_name'] . ': pericolo area -' . $hazardPenalty;
                }
                if ($hazardPenalty > 0 && $targetTeamKey !== '' && $sideKey !== '' && $sideKey === $targetTeamKey && $actionType === 'strike') {
                    $actorScore += 1;
                    $modifiers[] = $feature['feature_name'] . ': bersaglio sotto pressione ambientale +1';
                }
                continue;
            }

            if ($type === 'chokepoint') {
                $zoneBonus = ($stateKey === 'blocked' || $stateKey === 'fortified') ? 2 : 1;
                if ($actorTeamKey !== '' && $sideKey !== '' && $sideKey === $actorTeamKey && in_array($actionType, ['strike', 'defend', 'protect'], true)) {
                    $actorScore += $zoneBonus;
                    $modifiers[] = $feature['feature_name'] . ': controllo chokepoint +' . $zoneBonus;
                } elseif ($targetTeamKey !== '' && $sideKey !== '' && $sideKey === $targetTeamKey && $actionType === 'strike') {
                    $targetScore += $zoneBonus;
                    $modifiers[] = $feature['feature_name'] . ': difesa chokepoint bersaglio +' . $zoneBonus;
                }
                continue;
            }

            if ($type === 'terrain') {
                $terrainPenalty = $this->clampInt((int) ceil(($mobilityImpact + $visibilityImpact) / 6), 0, 2);
                if ($terrainPenalty > 0 && in_array($actionType, ['reposition', 'disengage'], true)) {
                    $actorScore -= $terrainPenalty;
                    $modifiers[] = $feature['feature_name'] . ': terreno difficile -' . $terrainPenalty;
                }
                continue;
            }

            if ($type === 'utility' && $mode === 'advanced' && $sideKey !== '' && $sideKey === $actorTeamKey) {
                $actorScore += 1;
                $modifiers[] = $feature['feature_name'] . ': opportunita locale +1';
            }
        }

        $payload['actor_score'] = $actorScore;
        $payload['target_score'] = $targetScore;
        $payload['modifiers'] = $modifiers;

        if ($baseEnvironmentRaw !== null && $mode === 'advanced' && max(0, (int) ($baseEnvironmentRaw['hazard_level'] ?? 0)) >= 7) {
            $payload['actor_score'] = (int) $payload['actor_score'] - 1;
            $payload['modifiers'][] = 'Scenario avanzato: pericolo globale -1';
        }

        return $payload;
    }

    public function interact($data, int $actorCharacterId, bool $isStaff): array
    {
        $this->ensureFeatureTables();
        $this->ensureBaseTier2Enabled();

        $conflictId = (int) ($data->conflict_id ?? 0);
        $featureId = (int) ($data->feature_id ?? 0);
        $actionKey = strtolower(trim((string) ($data->action_key ?? '')));
        $notes = $this->normalizeText($data->notes ?? '', 500);

        if ($conflictId <= 0 || $featureId <= 0 || $actionKey === '') {
            throw AppError::validation('Dati interazione ambiente non validi.', [], 'combat_environment_interaction_invalid');
        }

        $this->ensureConflictAccessible($conflictId, $actorCharacterId, $isStaff);
        $featureRow = $this->firstPrepared(
            'SELECT *
             FROM combat_environment_features
             WHERE id = ?
               AND conflict_id = ?
             LIMIT 1',
            [$featureId, $conflictId],
        );
        if (empty($featureRow)) {
            throw AppError::validation('Feature ambientale non trovata.', [], 'combat_environment_feature_not_found');
        }

        $feature = $this->featureDataset([$featureRow])[0];
        $actorTeamKey = $this->actorTeamKey($conflictId, $actorCharacterId);
        $oldStateKey = (string) ($feature['state_key'] ?? 'active');
        $newStateKey = $oldStateKey;
        $controlSideKey = (string) ($feature['control_side_key'] ?? '');

        if ($actionKey === 'secure') {
            $newStateKey = 'fortified';
            if ($actorTeamKey !== '') {
                $controlSideKey = $actorTeamKey;
            }
        } elseif ($actionKey === 'compromise') {
            $newStateKey = 'compromised';
        } elseif ($actionKey === 'escalate') {
            $newStateKey = 'escalated';
        } elseif ($actionKey === 'stabilize') {
            $newStateKey = 'stabilized';
        } elseif ($actionKey === 'claim') {
            if ($actorTeamKey === '') {
                throw AppError::validation('Il personaggio non partecipa a questo conflitto.', [], 'combat_environment_claim_forbidden');
            }
            $controlSideKey = $actorTeamKey;
            if ($oldStateKey === 'open') {
                $newStateKey = 'active';
            }
        } else {
            throw AppError::validation('Azione ambientale non valida.', [], 'combat_environment_action_invalid');
        }

        $this->execPrepared(
            'UPDATE combat_environment_features
             SET state_key = ?, control_side_key = ?, updated_at = ?
             WHERE id = ? LIMIT 1',
            [
                $newStateKey,
                $controlSideKey !== '' ? $controlSideKey : null,
                $this->now(),
                $featureId,
            ],
        );

        $this->execPrepared(
            'INSERT INTO combat_environment_feature_logs
                (feature_id, conflict_id, actor_character_id, action_key, old_state_key, new_state_key, notes, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $featureId,
                $conflictId,
                $actorCharacterId > 0 ? $actorCharacterId : null,
                $actionKey,
                $oldStateKey,
                $newStateKey,
                $notes !== '' ? $notes : null,
                $this->now(),
            ],
        );

        $baseState = [
            'tier_level' => $this->baseCombatTierLevel(),
        ];

        return [
            'feature_id' => $featureId,
            'action_key' => $actionKey,
            'state_key' => $newStateKey,
            'control_side_key' => $controlSideKey,
            'addon' => $this->buildStateAddon($baseState, $conflictId, $actorCharacterId, $isStaff),
        ];
    }
}
