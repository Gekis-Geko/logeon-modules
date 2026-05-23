<?php

declare(strict_types=1);

namespace Modules\Logeon\NarrativeCombat\Services;

use App\Services\ConflictService;
use App\Services\LocationMessageService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Hooks;
use Core\Http\AppError;
use Core\ModuleManager;

class NarrativeCombatService
{
    private const MODULE_ID = 'logeon.narrative-combat';
    private const SETTING_COMBAT_DEPTH = 'combat_depth';
    private const DEFAULT_TIER_LEVEL = 2;
    private const TIER2_LOCKING_MODULES = [
        'logeon.combat-environment',
        'logeon.combat-ai',
        'logeon.combat-coordination',
        'logeon.combat-admin-tools',
    ];

    /** @var DbAdapterInterface */
    private $db;
    /** @var ConflictService|null */
    private $conflictService = null;
    /** @var LocationMessageService|null */
    private $locationMessageService = null;
    /** @var ModuleManager|null */
    private $moduleManager = null;
    /** @var array<string,bool> */
    private $tableExists = [];
    /** @var array<string,bool> */
    private $columnExists = [];

    public function __construct(
        DbAdapterInterface $db = null,
        ConflictService $conflictService = null,
        LocationMessageService $locationMessageService = null,
        ModuleManager $moduleManager = null,
    ) {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->conflictService = $conflictService;
        $this->locationMessageService = $locationMessageService;
        $this->moduleManager = $moduleManager;
    }

    private function conflictService(): ConflictService
    {
        if ($this->conflictService instanceof ConflictService) {
            return $this->conflictService;
        }

        $this->conflictService = new ConflictService($this->db);
        return $this->conflictService;
    }

    private function locationMessageService(): LocationMessageService
    {
        if ($this->locationMessageService instanceof LocationMessageService) {
            return $this->locationMessageService;
        }

        $this->locationMessageService = new LocationMessageService($this->db);
        return $this->locationMessageService;
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

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function jsonEncode($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '{}';
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

    private function normalizeTierLevel($value): int
    {
        $tier = (int) $value;
        if ($tier <= 1) {
            return 1;
        }

        return self::DEFAULT_TIER_LEVEL;
    }

    private function tier2LockingModules(): array
    {
        $active = [];
        foreach (self::TIER2_LOCKING_MODULES as $moduleId) {
            if ($this->moduleManager()->isActive($moduleId)) {
                $active[] = $moduleId;
            }
        }

        return $active;
    }

    private function minimumRequiredTierLevel(): int
    {
        return $this->tier2LockingModules() === [] ? 1 : self::DEFAULT_TIER_LEVEL;
    }

    public function configuredTierLevel(): int
    {
        $configured = $this->normalizeTierLevel(
            $this->moduleManager()->getSetting(
                self::MODULE_ID,
                self::SETTING_COMBAT_DEPTH,
                self::DEFAULT_TIER_LEVEL,
            ),
        );

        return max($configured, $this->minimumRequiredTierLevel());
    }

    private function isTier2Enabled(): bool
    {
        return $this->configuredTierLevel() >= 2;
    }

    private function assertTier2Enabled(): void
    {
        if ($this->isTier2Enabled()) {
            return;
        }

        throw AppError::validation(
            'Questa azione richiede la profondita combattimento impostata su Tier 2.',
            [],
            'combat_tier2_disabled',
        );
    }

    public function adminSettingsBootstrap(): array
    {
        $tier = $this->configuredTierLevel();
        $minimumTier = $this->minimumRequiredTierLevel();
        $lockedByModules = $this->tier2LockingModules();

        return [
            'settings' => [
                'combat_depth' => $tier,
            ],
            'options' => [
                [
                    'value' => 1,
                    'label' => 'Tier 1',
                    'description' => 'Stamina, fatica, azioni base, effetti e risoluzione essenziale.',
                    'disabled' => $minimumTier > 1,
                ],
                [
                    'value' => 2,
                    'label' => 'Tier 2',
                    'description' => 'Aggiunge momentum, escalation, metriche sintetiche, guardie e ambiente.',
                    'disabled' => false,
                ],
            ],
            'effective_tier' => $tier,
            'constraints' => [
                'minimum_tier' => $minimumTier,
                'locked_by_modules' => $lockedByModules,
                'message' => $minimumTier > 1
                    ? 'Tier 2 e mantenuto attivo automaticamente perche ci sono estensioni Tier 3 attive: ' . implode(', ', $lockedByModules) . '.'
                    : '',
            ],
        ];
    }

    public function updateAdminSettings($data): array
    {
        $requestedTier = $this->normalizeTierLevel($data->combat_depth ?? self::DEFAULT_TIER_LEVEL);
        $tier = max($requestedTier, $this->minimumRequiredTierLevel());
        $this->moduleManager()->setSetting(self::MODULE_ID, self::SETTING_COMBAT_DEPTH, $tier);

        $dataset = $this->adminSettingsBootstrap();
        $dataset['last_update'] = [
            'requested_tier' => $requestedTier,
            'stored_tier' => $tier,
            'was_forced' => $tier !== $requestedTier,
        ];

        return $dataset;
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

    private function hasColumn(string $table, string $column): bool
    {
        $cacheKey = $table . '.' . $column;
        if (array_key_exists($cacheKey, $this->columnExists)) {
            return $this->columnExists[$cacheKey];
        }

        $row = $this->firstPrepared(
            'SELECT COUNT(*) AS c
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND column_name = ?
             LIMIT 1',
            [$table, $column],
        );
        $this->columnExists[$cacheKey] = !empty($row) && (int) ($row->c ?? 0) > 0;
        return $this->columnExists[$cacheKey];
    }

    private function hasRuntimeArtifacts(): bool
    {
        return $this->hasTable('combat_contexts')
            && $this->hasTable('combat_participant_states')
            && $this->hasTable('combat_action_intents')
            && $this->hasTable('combat_state_effects');
    }

    private function ensureRuntimeArtifacts(): void
    {
        if ($this->hasRuntimeArtifacts()) {
            return;
        }

        throw AppError::validation(
            'Schema modulo combattimento non disponibile. Attiva o reinstalla il modulo.',
            [],
            'combat_module_schema_missing',
        );
    }

    private function ensurePositiveId(int $id, string $message, string $code): void
    {
        if ($id > 0) {
            return;
        }

        throw AppError::validation($message, [], $code);
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

        throw AppError::unauthorized('Operazione non autorizzata sul combattimento', [], 'combat_access_forbidden');
    }

    /**
     * @return array<string,string>
     */
    public function taxonomy(): array
    {
        return [
            'strike' => 'offensive',
            'defend' => 'defensive',
            'reposition' => 'positional',
            'recover' => 'recovery',
            'protect' => 'support',
            'disengage' => 'positional',
        ];
    }

    private function normalizeActionType($value): string
    {
        $actionType = strtolower(trim((string) $value));
        $allowed = array_keys($this->taxonomy());
        if (!in_array($actionType, $allowed, true)) {
            throw AppError::validation('Azione di combattimento non valida.', [], 'combat_action_invalid');
        }

        return $actionType;
    }

    private function baseStaminaCost(string $actionType): int
    {
        if ($actionType === 'strike') {
            return 12;
        }
        if ($actionType === 'defend') {
            return 8;
        }
        if ($actionType === 'reposition') {
            return 9;
        }
        if ($actionType === 'recover') {
            return 0;
        }
        if ($actionType === 'protect') {
            return 10;
        }
        if ($actionType === 'disengage') {
            return 10;
        }

        return 8;
    }

    private function computeStaminaCost(string $actionType, array $participantState): int
    {
        $fatigue = max(0, (int) ($participantState['fatigue_level'] ?? 0));
        return $this->baseStaminaCost($actionType) + $fatigue;
    }

    private function buildTeamAssignments(array $participants): array
    {
        $map = [];
        $hasExplicit = false;

        foreach ($participants as $row) {
            $teamKey = trim((string) ($row->team_key ?? ''));
            $characterId = (int) ($row->character_id ?? 0);
            if ($characterId <= 0) {
                continue;
            }

            if ($teamKey !== '') {
                $hasExplicit = true;
                $map[$characterId] = $teamKey;
            }
        }

        if ($hasExplicit) {
            return $map;
        }

        $hasTarget = false;
        foreach ($participants as $row) {
            if (strtolower(trim((string) ($row->participant_role ?? ''))) === 'target') {
                $hasTarget = true;
                break;
            }
        }

        $counter = 0;
        foreach ($participants as $row) {
            $characterId = (int) ($row->character_id ?? 0);
            if ($characterId <= 0) {
                continue;
            }

            $role = strtolower(trim((string) ($row->participant_role ?? 'actor')));
            $teamKey = 'side_a';
            if ($hasTarget) {
                $teamKey = ($role === 'target') ? 'side_b' : 'side_a';
            } elseif ($counter > 0) {
                $teamKey = 'side_b';
            }

            $map[$characterId] = $teamKey;
            $counter += 1;
        }

        return $map;
    }

    private function getCombatContextRow(int $conflictId)
    {
        return $this->firstPrepared(
            'SELECT *
             FROM combat_contexts
             WHERE conflict_id = ?
             LIMIT 1',
            [$conflictId],
        );
    }

    private function updateConflictStatusActive(int $conflictId): void
    {
        $conflict = $this->firstPrepared(
            'SELECT id, status
             FROM conflicts
             WHERE id = ?
             LIMIT 1',
            [$conflictId],
        );
        if (empty($conflict)) {
            return;
        }

        $status = strtolower(trim((string) ($conflict->status ?? '')));
        if (!in_array($status, ['proposal', 'open', 'active'], true)) {
            return;
        }

        $set = ['status = ?'];
        $params = ['active'];
        if ($this->hasColumn('conflicts', 'last_activity_at')) {
            $set[] = 'last_activity_at = NOW()';
        }
        $params[] = $conflictId;

        $this->execPrepared(
            'UPDATE conflicts
             SET ' . implode(', ', $set) . '
             WHERE id = ?
             LIMIT 1',
            $params,
        );
    }

    private function updateConflictStatusAwaiting(int $conflictId): void
    {
        $set = ['status = ?'];
        $params = ['awaiting_resolution'];
        if ($this->hasColumn('conflicts', 'last_activity_at')) {
            $set[] = 'last_activity_at = NOW()';
        }
        $params[] = $conflictId;

        $this->execPrepared(
            'UPDATE conflicts
             SET ' . implode(', ', $set) . '
             WHERE id = ?
             LIMIT 1',
            $params,
        );
    }

    private function insertContextIfMissing(int $conflictId, int $createdBy, int $participantsCount): void
    {
        if (!empty($this->getCombatContextRow($conflictId))) {
            return;
        }

        $combatMode = $participantsCount > 2 ? 'group_melee' : 'duel';
        $tierLevel = $this->configuredTierLevel();
        $this->execPrepared(
            'INSERT INTO combat_contexts
                (conflict_id, tier_level, combat_mode, escalation_level, progression_index, resolution_condition, status, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $conflictId,
                $tierLevel,
                $combatMode,
                1,
                0,
                '',
                'active',
                $createdBy > 0 ? $createdBy : null,
                $this->now(),
                $this->now(),
            ],
        );
    }

    private function ensureContextTier(int $conflictId): void
    {
        $context = $this->getCombatContextRow($conflictId);
        if (empty($context)) {
            return;
        }

        $configuredTier = $this->configuredTierLevel();
        $tierLevel = (int) ($context->tier_level ?? 1);
        if ($tierLevel === $configuredTier) {
            return;
        }

        $this->execPrepared(
            'UPDATE combat_contexts
             SET tier_level = ?, updated_at = ?
             WHERE conflict_id = ? LIMIT 1',
            [$configuredTier, $this->now(), $conflictId],
        );
    }

    public function startCombatContext(int $conflictId, int $actorCharacterId, bool $isStaff): array
    {
        $this->ensureRuntimeArtifacts();
        $this->ensurePositiveId($conflictId, 'Conflitto non valido.', 'conflict_not_found');
        $detail = $this->ensureConflictAccessible($conflictId, $actorCharacterId, $isStaff);
        $participants = is_array($detail['participants'] ?? null) ? $detail['participants'] : [];

        $this->begin();
        try {
            $this->insertContextIfMissing($conflictId, $actorCharacterId, count($participants));
            $this->ensureContextTier($conflictId);
            $this->updateConflictStatusActive($conflictId);
            $sync = $this->syncParticipants($conflictId, $actorCharacterId, $isStaff);
            $this->commit();
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }

        return [
            'context' => $this->getState($conflictId, $actorCharacterId, $isStaff),
            'initialized' => (int) ($sync['initialized'] ?? 0),
        ];
    }

    public function syncParticipants(int $conflictId, int $actorCharacterId, bool $isStaff): array
    {
        $this->ensureRuntimeArtifacts();
        $this->ensurePositiveId($conflictId, 'Conflitto non valido.', 'conflict_not_found');
        $detail = $this->ensureConflictAccessible($conflictId, $actorCharacterId, $isStaff);
        $participants = is_array($detail['participants'] ?? null) ? $detail['participants'] : [];

        if (empty($this->getCombatContextRow($conflictId))) {
            $this->insertContextIfMissing($conflictId, $actorCharacterId, count($participants));
        }
        $this->ensureContextTier($conflictId);

        $teamMap = $this->buildTeamAssignments($participants);
        $rows = $this->fetchPrepared(
            'SELECT id, character_id
             FROM combat_participant_states
             WHERE conflict_id = ?',
            [$conflictId],
        );

        $existing = [];
        foreach ($rows as $row) {
            $characterId = (int) ($row->character_id ?? 0);
            if ($characterId > 0) {
                $existing[$characterId] = (int) ($row->id ?? 0);
            }
        }

        $activeIds = [];
        $initialized = 0;
        foreach ($participants as $participant) {
            $characterId = (int) ($participant->character_id ?? 0);
            $isActive = (int) ($participant->is_active ?? 0) === 1;
            if ($characterId <= 0 || !$isActive) {
                continue;
            }

            $activeIds[$characterId] = $characterId;
            $teamKey = $teamMap[$characterId] ?? 'side_a';

            if (!array_key_exists($characterId, $existing)) {
                $this->execPrepared(
                    'INSERT INTO combat_participant_states
                        (conflict_id, character_id, team_key, stamina_max, stamina_current, fatigue_level, stance, pressure_level, threat_exposure, combat_readiness, engagement_targets_json, status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $conflictId,
                        $characterId,
                        $teamKey,
                        100,
                        100,
                        0,
                        'neutral',
                        0,
                        0,
                        100,
                        '[]',
                        'active',
                        $this->now(),
                        $this->now(),
                    ],
                );
                $initialized += 1;
                continue;
            }

            $this->execPrepared(
                'UPDATE combat_participant_states
                 SET team_key = ?, status = ?, updated_at = ?
                 WHERE conflict_id = ? AND character_id = ? LIMIT 1',
                [$teamKey, 'active', $this->now(), $conflictId, $characterId],
            );
        }

        if (!empty($existing)) {
            foreach (array_keys($existing) as $characterId) {
                if (isset($activeIds[$characterId])) {
                    continue;
                }

                $this->execPrepared(
                    'UPDATE combat_participant_states
                     SET status = ?, updated_at = ?
                     WHERE conflict_id = ? AND character_id = ? LIMIT 1',
                    ['withdrawn', $this->now(), $conflictId, (int) $characterId],
                );
            }
        }

        return [
            'initialized' => $initialized,
            'active_count' => count($activeIds),
        ];
    }

    private function listGuardRelations(int $conflictId): array
    {
        if (!$this->isTier2Enabled()) {
            return [];
        }
        if (!$this->hasTable('combat_guard_relations')) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT *
             FROM combat_guard_relations
             WHERE conflict_id = ?
               AND is_active = 1
             ORDER BY guardian_id ASC, protected_id ASC',
            [$conflictId],
        );

        $dataset = [];
        foreach ($rows as $row) {
            $dataset[] = [
                'id' => (int) ($row->id ?? 0),
                'conflict_id' => (int) ($row->conflict_id ?? 0),
                'guardian_id' => (int) ($row->guardian_id ?? 0),
                'protected_id' => (int) ($row->protected_id ?? 0),
                'stamina_upkeep' => (int) ($row->stamina_upkeep ?? 0),
                'created_at' => (string) ($row->created_at ?? ''),
            ];
        }

        return $dataset;
    }

    private function environmentRow(int $conflictId)
    {
        if (!$this->isTier2Enabled()) {
            return null;
        }
        if (!$this->hasTable('combat_environment_contexts')) {
            return null;
        }

        return $this->firstPrepared(
            'SELECT *
             FROM combat_environment_contexts
             WHERE conflict_id = ?
             LIMIT 1',
            [$conflictId],
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function buildEnvironmentRaw(int $conflictId): ?array
    {
        $row = $this->environmentRow($conflictId);
        if (empty($row)) {
            return null;
        }

        return [
            'visibility_level' => (int) ($row->visibility_level ?? 10),
            'mobility_level' => (int) ($row->mobility_level ?? 10),
            'hazard_level' => (int) ($row->hazard_level ?? 0),
            'cover_density' => (int) ($row->cover_density ?? 0),
            'notes' => (string) ($row->notes ?? ''),
        ];
    }

    /**
     * @param array<string,mixed>|null $environmentRaw
     * @return array<int,string>
     */
    private function buildEnvironmentConditions(?array $environmentRaw): array
    {
        if ($environmentRaw === null) {
            return [];
        }

        $conditions = [];
        $visibility = (int) ($environmentRaw['visibility_level'] ?? 10);
        $mobility = (int) ($environmentRaw['mobility_level'] ?? 10);
        $hazard = (int) ($environmentRaw['hazard_level'] ?? 0);
        $cover = (int) ($environmentRaw['cover_density'] ?? 0);
        $notes = trim((string) ($environmentRaw['notes'] ?? ''));

        if ($visibility <= 3) {
            $conditions[] = 'Visibilita molto bassa';
        } elseif ($visibility <= 6) {
            $conditions[] = 'Visibilita ridotta';
        }

        if ($mobility <= 3) {
            $conditions[] = 'Mobilita fortemente limitata';
        } elseif ($mobility <= 6) {
            $conditions[] = 'Mobilita difficile';
        }

        if ($hazard >= 7) {
            $conditions[] = 'Ambiente molto pericoloso';
        } elseif ($hazard >= 4) {
            $conditions[] = 'Presenza di pericoli';
        }

        if ($cover >= 7) {
            $conditions[] = 'Copertura abbondante';
        } elseif ($cover >= 4) {
            $conditions[] = 'Copertura moderata';
        }

        if ($notes !== '') {
            $conditions[] = 'Nota ambiente: ' . $notes;
        }

        return $conditions;
    }

    /**
     * @param array<int,array<string,mixed>> $participantStates
     * @return array<int,array<string,mixed>>
     */
    private function buildAdvantageIndicators(array $participantStates): array
    {
        $dataset = [];
        foreach ($participantStates as $row) {
            $characterId = (int) ($row['character_id'] ?? 0);
            if ($characterId <= 0) {
                continue;
            }

            $stamina = (int) ($row['stamina_current'] ?? 0);
            $fatigue = (int) ($row['fatigue_level'] ?? 0);
            $readiness = (int) ($row['combat_readiness'] ?? 0);
            $status = strtolower(trim((string) ($row['status'] ?? 'active')));
            $label = 'In equilibrio';

            if ($status === 'incapacitated') {
                $label = 'Fuori combattimento';
            } elseif ($status === 'withdrawn') {
                $label = 'In disimpegno';
            } elseif ($fatigue >= 6 || $stamina <= 15) {
                $label = 'Esausto';
            } elseif ($readiness >= 75 && $stamina >= 60) {
                $label = 'Sta prendendo vantaggio';
            } elseif ($readiness <= 35 || $stamina <= 30) {
                $label = 'Sta perdendo terreno';
            }

            $dataset[] = [
                'character_id' => $characterId,
                'label' => $label,
                'raw' => 'readiness=' . $readiness . ', stamina=' . $stamina . ', fatigue=' . $fatigue,
            ];
        }

        return $dataset;
    }

    private function listParticipantStates(int $conflictId): array
    {
        $rows = $this->fetchPrepared(
            'SELECT cps.*, c.name AS character_name, c.surname AS character_surname, c.avatar AS character_avatar, c.gender AS character_gender
             FROM combat_participant_states cps
             LEFT JOIN characters c ON c.id = cps.character_id
             WHERE cps.conflict_id = ?
             ORDER BY cps.team_key ASC, cps.character_id ASC',
            [$conflictId],
        );

        $dataset = [];
        foreach ($rows as $row) {
            $dataset[] = [
                'id' => (int) ($row->id ?? 0),
                'conflict_id' => (int) ($row->conflict_id ?? 0),
                'character_id' => (int) ($row->character_id ?? 0),
                'character_name' => (string) ($row->character_name ?? ''),
                'character_surname' => (string) ($row->character_surname ?? ''),
                'team_key' => (string) ($row->team_key ?? 'side_a'),
                'stamina_max' => (int) ($row->stamina_max ?? 100),
                'stamina_current' => (int) ($row->stamina_current ?? 100),
                'fatigue_level' => (int) ($row->fatigue_level ?? 0),
                'stance' => (string) ($row->stance ?? 'neutral'),
                'pressure_level' => (int) ($row->pressure_level ?? 0),
                'threat_exposure' => (int) ($row->threat_exposure ?? 0),
                'combat_readiness' => (int) ($row->combat_readiness ?? 100),
                'engagement_targets' => $this->decodeJsonArray($row->engagement_targets_json ?? '[]'),
                'status' => (string) ($row->status ?? 'active'),
                'last_action_at' => (string) ($row->last_action_at ?? ''),
            ];
        }

        return $dataset;
    }

    private function listEffects(int $conflictId, bool $activeOnly = true): array
    {
        $sql = 'SELECT *
                FROM combat_state_effects
                WHERE conflict_id = ?';
        $params = [$conflictId];
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY created_at ASC, id ASC';

        $rows = $this->fetchPrepared($sql, $params);
        $dataset = [];
        foreach ($rows as $row) {
            $dataset[] = [
                'id' => (int) ($row->id ?? 0),
                'conflict_id' => (int) ($row->conflict_id ?? 0),
                'source_actor_id' => (int) ($row->source_actor_id ?? 0),
                'target_actor_id' => (int) ($row->target_actor_id ?? 0),
                'effect_type' => (string) ($row->effect_type ?? ''),
                'intensity' => (int) ($row->intensity ?? 1),
                'duration_model' => (string) ($row->duration_model ?? 'combat'),
                'duration_value' => (int) ($row->duration_value ?? 0),
                'is_active' => (int) ($row->is_active ?? 1),
                'created_at' => (string) ($row->created_at ?? ''),
                'resolved_at' => (string) ($row->resolved_at ?? ''),
            ];
        }

        return $dataset;
    }

    private function listPendingActions(int $conflictId): array
    {
        $rows = $this->fetchPrepared(
            'SELECT *
             FROM combat_action_intents
             WHERE conflict_id = ?
               AND resolution_status = ?
             ORDER BY declared_at ASC, id ASC',
            [$conflictId, 'pending'],
        );

        $dataset = [];
        foreach ($rows as $row) {
            $dataset[] = [
                'id' => (int) ($row->id ?? 0),
                'conflict_id' => (int) ($row->conflict_id ?? 0),
                'actor_id' => (int) ($row->actor_id ?? 0),
                'action_type' => (string) ($row->action_type ?? ''),
                'primary_target_id' => (int) ($row->primary_target_id ?? 0),
                'secondary_targets' => (string) ($row->secondary_targets ?? '[]'),
                'stamina_cost_preview' => (int) ($row->stamina_cost_preview ?? 0),
                'resolution_status' => (string) ($row->resolution_status ?? 'pending'),
                'declared_at' => (string) ($row->declared_at ?? ''),
            ];
        }

        return $dataset;
    }

    private function listTimeline(int $conflictId): array
    {
        $rows = $this->fetchPrepared(
            'SELECT *
             FROM combat_action_intents
             WHERE conflict_id = ?
             ORDER BY id ASC',
            [$conflictId],
        );

        $dataset = [];
        foreach ($rows as $row) {
            $dataset[] = [
                'id' => (int) ($row->id ?? 0),
                'conflict_id' => (int) ($row->conflict_id ?? 0),
                'actor_id' => (int) ($row->actor_id ?? 0),
                'action_type' => (string) ($row->action_type ?? ''),
                'resolution_status' => (string) ($row->resolution_status ?? ''),
                'outcome_category' => (string) ($row->outcome_category ?? ''),
                'outcome_summary' => (string) ($row->outcome_summary ?? ''),
                'created_at' => (string) ($row->resolved_at ?? $row->declared_at ?? ''),
            ];
        }

        return $dataset;
    }

    private function buildSideSummary(array $participantStates, array $syntheticTeams = []): array
    {
        $sides = [];
        foreach ($participantStates as $row) {
            $teamKey = trim((string) ($row['team_key'] ?? 'side_a'));
            if ($teamKey === '') {
                $teamKey = 'side_a';
            }

            if (!isset($sides[$teamKey])) {
                $sides[$teamKey] = [
                    'team_key' => $teamKey,
                    'count' => 0,
                    'character_ids' => [],
                    'advantage_sum' => 0,
                ];
            }

            if ((string) ($row['status'] ?? 'active') === 'active') {
                $sides[$teamKey]['count'] += 1;
            }
            $sides[$teamKey]['character_ids'][] = (int) ($row['character_id'] ?? 0);
            $sides[$teamKey]['advantage_sum'] += (int) ($row['combat_readiness'] ?? 0);
        }

        $result = ['sides' => [], 'numerical_superiority' => ''];
        $winnerKey = '';
        $winnerCount = 0;
        $syntheticMap = [];
        foreach ($syntheticTeams as $team) {
            $teamKey = trim((string) ($team['team_key'] ?? ''));
            if ($teamKey !== '') {
                $syntheticMap[$teamKey] = $team;
            }
        }

        foreach ($sides as $teamKey => $side) {
            $count = (int) $side['count'];
            $avg = 0.0;
            $totalMembers = count($side['character_ids']);
            $avg = (float) $side['advantage_sum'] / $totalMembers;
            $synthetic = $syntheticMap[$teamKey] ?? [];

            $result['sides'][] = [
                'team_key' => $teamKey,
                'count' => $count,
                'character_ids' => array_values(array_filter(array_map('intval', $side['character_ids']))),
                'advantage_avg' => round($avg, 2),
                'pressure_avg' => round((float) ($synthetic['pressure_avg'] ?? 0), 2),
                'control_avg' => round((float) ($synthetic['control_avg'] ?? 0), 2),
                'attrition_avg' => round((float) ($synthetic['attrition_avg'] ?? 0), 2),
                'momentum_score' => round((float) ($synthetic['momentum_score'] ?? 0), 2),
            ];

            if ($count > $winnerCount) {
                $winnerKey = $teamKey;
                $winnerCount = $count;
            } elseif ($count === $winnerCount) {
                $winnerKey = '';
            }
        }

        $result['numerical_superiority'] = $winnerKey;
        return $result;
    }

    private function buildPhaseInfo($context, array $participantStates, array $syntheticState): array
    {
        $activeCount = 0;
        $fatigueTotal = 0;
        $pressureTotal = 0.0;
        foreach ($participantStates as $row) {
            if ((string) ($row['status'] ?? 'active') === 'active') {
                $activeCount += 1;
            }
            $fatigueTotal += (int) ($row['fatigue_level'] ?? 0);
        }
        foreach ((array) ($syntheticState['teams'] ?? []) as $team) {
            $pressureTotal += (float) ($team['pressure_avg'] ?? 0);
        }

        $escalationLevel = (int) ($context->escalation_level ?? 1);
        $progressionIndex = (int) ($context->progression_index ?? 0);
        $momentumHolder = trim((string) ($syntheticState['momentum']['holder_team_key'] ?? ''));
        $momentumLabel = trim((string) ($syntheticState['momentum']['label'] ?? ''));
        $label = 'Ingaggio';
        $phase = 'engagement';
        $cue = 'Il confronto e in corso.';
        if ($activeCount <= 1) {
            $phase = 'resolution';
            $label = 'Esito';
            $cue = 'Il combattimento ha ormai espresso un vincitore narrativo.';
        } elseif ($escalationLevel >= 5) {
            $phase = 'breaking-point';
            $label = 'Rottura';
            $cue = 'La tensione e al culmine: basta poco per spezzare uno dei fronti.';
        } elseif ($escalationLevel >= 4 || $momentumHolder !== '') {
            $phase = 'dominance';
            $label = 'Dominio';
            $cue = $momentumHolder !== ''
                ? 'Il lato ' . $momentumHolder . ' sta imponendo il ritmo dello scontro.'
                : 'Uno dei fronti sta prendendo il controllo del combattimento.';
        } elseif ($fatigueTotal >= max(4, count($participantStates) * 2) || $pressureTotal >= max(6.0, count($participantStates) * 2.5)) {
            $phase = 'pressure';
            $label = 'Pressione';
            $cue = 'La stanchezza inizia a farsi sentire su entrambi i fronti.';
        } elseif ($progressionIndex >= 4) {
            $phase = 'contest';
            $label = 'Contesa';
            $cue = 'Le intenzioni si sono chiarite e il combattimento sta cercando un fronte dominante.';
        }
        if ($momentumLabel !== '' && $activeCount > 1) {
            $cue .= ' Momentum: ' . $momentumLabel . '.';
        }

        return [
            'phase' => $phase,
            'label' => $label,
            'narrative_cue' => $cue,
            'escalation_level' => $escalationLevel,
            'progression_index' => $progressionIndex,
        ];
    }

    private function effectsByTarget(array $effects): array
    {
        $map = [];
        foreach ($effects as $effect) {
            $targetId = (int) ($effect['target_actor_id'] ?? 0);
            if ($targetId <= 0) {
                continue;
            }
            if (!isset($map[$targetId])) {
                $map[$targetId] = [];
            }
            $map[$targetId][] = $effect;
        }

        return $map;
    }

    private function effectIntensity(array $effects, array $types): int
    {
        $total = 0;
        foreach ($effects as $effect) {
            $effectType = strtolower(trim((string) ($effect['effect_type'] ?? '')));
            if (!in_array($effectType, $types, true)) {
                continue;
            }
            $total += max(1, (int) ($effect['intensity'] ?? 1));
        }

        return $total;
    }

    private function buildSyntheticState($context, array $participantStates, array $effects, array $guardRelations, ?array $environmentRaw): array
    {
        if (!$this->isTier2Enabled()) {
            return [
                'momentum' => [
                    'holder_team_key' => '',
                    'label' => '',
                    'delta' => 0.0,
                ],
                'escalation' => [
                    'level' => (int) ($context->escalation_level ?? 1),
                    'label' => '',
                    'cue' => '',
                ],
                'teams' => [],
            ];
        }

        $effectsByTarget = $this->effectsByTarget($effects);
        $guardedBy = [];
        $protecting = [];
        $upkeepByGuardian = [];

        foreach ($guardRelations as $guard) {
            $guardianId = (int) ($guard['guardian_id'] ?? 0);
            $protectedId = (int) ($guard['protected_id'] ?? 0);
            $upkeep = max(0, (int) ($guard['stamina_upkeep'] ?? 0));
            if ($guardianId > 0) {
                $protecting[$guardianId] = ($protecting[$guardianId] ?? 0) + 1;
                $upkeepByGuardian[$guardianId] = ($upkeepByGuardian[$guardianId] ?? 0) + $upkeep;
            }
            if ($protectedId > 0) {
                $guardedBy[$protectedId] = ($guardedBy[$protectedId] ?? 0) + 1;
            }
        }

        $cover = (int) ($environmentRaw['cover_density'] ?? 0);
        $mobility = (int) ($environmentRaw['mobility_level'] ?? 10);
        $hazard = (int) ($environmentRaw['hazard_level'] ?? 0);

        $teams = [];
        foreach ($participantStates as $row) {
            if ((string) ($row['status'] ?? 'active') !== 'active') {
                continue;
            }

            $teamKey = trim((string) ($row['team_key'] ?? 'side_a'));
            if ($teamKey === '') {
                $teamKey = 'side_a';
            }
            if (!isset($teams[$teamKey])) {
                $teams[$teamKey] = [
                    'team_key' => $teamKey,
                    'count' => 0,
                    'pressure_sum' => 0.0,
                    'control_sum' => 0.0,
                    'attrition_sum' => 0.0,
                    'momentum_score' => 0.0,
                ];
            }

            $characterId = (int) ($row['character_id'] ?? 0);
            $characterEffects = $effectsByTarget[$characterId] ?? [];
            $fatigue = max(0, (int) ($row['fatigue_level'] ?? 0));
            $stamina = max(0, (int) ($row['stamina_current'] ?? 0));
            $readiness = max(0, (int) ($row['combat_readiness'] ?? 0));
            $pressureLevel = max(0, (int) ($row['pressure_level'] ?? 0));
            $threatExposure = max(0, (int) ($row['threat_exposure'] ?? 0));
            $engagementTargets = is_array($row['engagement_targets'] ?? null) ? $row['engagement_targets'] : [];

            $protected = $this->effectIntensity($characterEffects, ['protected']);
            $pressured = $this->effectIntensity($characterEffects, ['pressured']);
            $exposed = $this->effectIntensity($characterEffects, ['exposed']);
            $unbalanced = $this->effectIntensity($characterEffects, ['unbalanced']);
            $wounded = $this->effectIntensity($characterEffects, ['wounded_light', 'exhausted']);
            $guardCount = (int) ($guardedBy[$characterId] ?? 0);
            $protectCount = (int) ($protecting[$characterId] ?? 0);
            $upkeep = (int) ($upkeepByGuardian[$characterId] ?? 0);

            $pressure = max(0.0, (float) $pressureLevel + ($fatigue * 0.8) + ($pressured * 2.0) + ($exposed * 1.5) + ($unbalanced * 1.0) + ($threatExposure * 0.5));
            $control = max(0.0, ($readiness / 10.0) + ($protected * 1.5) + ($guardCount * 1.5) + ($protectCount * 2.0) + (count($engagementTargets) > 0 ? 1.0 : 0.0) + ($cover >= 6 ? 1.0 : 0.0) + ($mobility >= 7 ? 1.0 : 0.0) - ($hazard >= 7 ? 1.0 : 0.0) - ($upkeep / 10.0));
            $attrition = max(0.0, ((100 - $readiness) / 8.0) + ($fatigue * 1.8) + ($wounded * 2.0) + ($stamina <= 30 ? 2.0 : 0.0) + ($hazard >= 5 ? 1.0 : 0.0));
            $momentumScore = ($control * 1.7) - $pressure - $attrition;

            $teams[$teamKey]['count'] += 1;
            $teams[$teamKey]['pressure_sum'] += $pressure;
            $teams[$teamKey]['control_sum'] += $control;
            $teams[$teamKey]['attrition_sum'] += $attrition;
            $teams[$teamKey]['momentum_score'] += $momentumScore;
        }

        $teamList = [];
        foreach ($teams as $teamKey => $team) {
            $count = max(1, (int) $team['count']);
            $teamList[] = [
                'team_key' => $teamKey,
                'count' => $count,
                'pressure_avg' => round((float) $team['pressure_sum'] / $count, 2),
                'control_avg' => round((float) $team['control_sum'] / $count, 2),
                'attrition_avg' => round((float) $team['attrition_sum'] / $count, 2),
                'momentum_score' => round((float) $team['momentum_score'], 2),
            ];
        }

        usort($teamList, static function (array $left, array $right): int {
            return (float) $right['momentum_score'] <=> (float) $left['momentum_score'];
        });

        $holderTeamKey = '';
        $holderLabel = 'Equilibrio instabile';
        $holderDelta = 0.0;
        if (!empty($teamList)) {
            $best = $teamList[0];
            $second = $teamList[1] ?? null;
            $secondScore = $second === null ? 0.0 : (float) $second['momentum_score'];
            $holderDelta = round((float) $best['momentum_score'] - $secondScore, 2);
            if ($second === null || $holderDelta >= 1.5) {
                $holderTeamKey = (string) $best['team_key'];
                if ($holderDelta >= 6.0) {
                    $holderLabel = 'Momentum forte di ' . $holderTeamKey;
                } elseif ($holderDelta >= 3.0) {
                    $holderLabel = 'Momentum in crescita di ' . $holderTeamKey;
                } else {
                    $holderLabel = 'Momentum leggero di ' . $holderTeamKey;
                }
            }
        }

        $escalationLevel = (int) ($context->escalation_level ?? 1);
        $escalationLabel = 'Apertura';
        $escalationCue = 'Il confronto sta ancora cercando il suo assetto.';
        if ($escalationLevel >= 5) {
            $escalationLabel = 'Punto critico';
            $escalationCue = 'Qualunque scelta puo trasformare il combattimento in una rottura definitiva.';
        } elseif ($escalationLevel === 4) {
            $escalationLabel = 'Rottura imminente';
            $escalationCue = 'Le parti sono ormai molto esposte e vicine al collasso tattico.';
        } elseif ($escalationLevel === 3) {
            $escalationLabel = 'Scontro acceso';
            $escalationCue = 'Le iniziative si stanno concatenando e la pressione e ormai costante.';
        } elseif ($escalationLevel === 2) {
            $escalationLabel = 'Pressione crescente';
            $escalationCue = 'Le linee si stanno assestando e iniziano i primi veri vantaggi di posizione.';
        }

        return [
            'momentum' => [
                'holder_team_key' => $holderTeamKey,
                'label' => $holderLabel,
                'delta' => $holderDelta,
            ],
            'escalation' => [
                'level' => $escalationLevel,
                'label' => $escalationLabel,
                'cue' => $escalationCue,
            ],
            'teams' => $teamList,
        ];
    }

    public function getState(int $conflictId, int $viewerCharacterId, bool $isStaff): array
    {
        $this->ensureRuntimeArtifacts();
        $this->ensurePositiveId($conflictId, 'Conflitto non valido.', 'conflict_not_found');
        $detail = $this->ensureConflictAccessible($conflictId, $viewerCharacterId, $isStaff);

        $context = $this->getCombatContextRow($conflictId);
        if (empty($context)) {
            throw AppError::validation('Contesto combattimento non ancora avviato.', [], 'combat_context_missing');
        }
        $this->ensureContextTier($conflictId);
        $context = $this->getCombatContextRow($conflictId);

        $participantStates = $this->listParticipantStates($conflictId);
        $effects = $this->listEffects($conflictId, true);
        $pending = $this->listPendingActions($conflictId);
        $timeline = $this->listTimeline($conflictId);
        $guardRelations = $this->listGuardRelations($conflictId);
        $environmentRaw = $this->buildEnvironmentRaw($conflictId);
        $environmentConditions = $this->buildEnvironmentConditions($environmentRaw);
        $syntheticState = $this->buildSyntheticState(
            $context,
            $participantStates,
            $effects,
            $guardRelations,
            $environmentRaw,
        );

        $payload = [
            'tier_level' => $this->configuredTierLevel(),
            'context' => [
                'id' => (int) ($context->id ?? 0),
                'conflict_id' => (int) ($context->conflict_id ?? 0),
                'combat_mode' => (string) ($context->combat_mode ?? 'duel'),
                'escalation_level' => (int) ($context->escalation_level ?? 1),
                'progression_index' => (int) ($context->progression_index ?? 0),
                'status' => (string) ($context->status ?? 'active'),
                'resolution_condition' => (string) ($context->resolution_condition ?? ''),
                'created_at' => (string) ($context->created_at ?? ''),
                'updated_at' => (string) ($context->updated_at ?? ''),
                'completed_at' => (string) ($context->completed_at ?? ''),
            ],
            'conflict' => $detail['conflict'],
            'participant_states' => $participantStates,
            'active_effects' => $effects,
            'pending_actions' => $pending,
            'timeline' => $timeline,
            'phase_info' => $this->buildPhaseInfo($context, $participantStates, $syntheticState),
            'side_summary' => $this->buildSideSummary($participantStates, (array) ($syntheticState['teams'] ?? [])),
            'advantage_indicators' => $this->buildAdvantageIndicators($participantStates),
            'synthetic_state' => $syntheticState,
            'guard_relations' => $guardRelations,
            'environment_conditions' => $environmentConditions,
            'environment_raw' => $environmentRaw,
        ];

        $filtered = Hooks::filter('combat.state.payload', $payload, $conflictId, $viewerCharacterId, $isStaff, $this->db);
        return is_array($filtered) ? $filtered : $payload;
    }

    private function participantStateByCharacter(int $conflictId, int $characterId): array
    {
        $rows = $this->listParticipantStates($conflictId);
        foreach ($rows as $row) {
            if ((int) ($row['character_id'] ?? 0) === $characterId) {
                return $row;
            }
        }

        throw AppError::validation('Partecipante di combattimento non trovato.', [], 'combat_participant_not_found');
    }

    private function pendingActionForActor(int $conflictId, int $actorId)
    {
        return $this->firstPrepared(
            'SELECT id
             FROM combat_action_intents
             WHERE conflict_id = ?
               AND actor_id = ?
               AND resolution_status = ?
             LIMIT 1',
            [$conflictId, $actorId, 'pending'],
        );
    }

    private function ensureTargetParticipant(int $conflictId, int $targetId): void
    {
        if ($targetId <= 0) {
            return;
        }

        $row = $this->firstPrepared(
            'SELECT id
             FROM combat_participant_states
             WHERE conflict_id = ?
               AND character_id = ?
             LIMIT 1',
            [$conflictId, $targetId],
        );
        if (!empty($row)) {
            return;
        }

        throw AppError::validation('Bersaglio di combattimento non valido.', [], 'combat_target_invalid');
    }

    /**
     * @param object $payload
     * @return array<string,mixed>
     */
    public function declareAction($payload, int $actorCharacterId, bool $isStaff): array
    {
        $this->ensureRuntimeArtifacts();
        $data = $payload;
        $conflictId = (int) ($data->conflict_id ?? 0);
        $this->ensurePositiveId($conflictId, 'Conflitto non valido.', 'conflict_not_found');
        $this->ensureConflictAccessible($conflictId, $actorCharacterId, $isStaff);

        if (empty($this->getCombatContextRow($conflictId))) {
            throw AppError::validation('Il contesto combattimento non e stato avviato.', [], 'combat_context_missing');
        }

        $participantState = $this->participantStateByCharacter($conflictId, $actorCharacterId);
        if ((string) ($participantState['status'] ?? 'active') !== 'active') {
            throw AppError::validation('Il tuo personaggio non e piu attivo in questo combattimento.', [], 'combat_actor_inactive');
        }
        if (!empty($this->pendingActionForActor($conflictId, $actorCharacterId))) {
            throw AppError::validation('Hai gia una azione in attesa di risoluzione.', [], 'combat_action_pending_exists');
        }

        $actionType = $this->normalizeActionType($data->action_type ?? '');
        $primaryTargetId = (int) ($data->primary_target_id ?? 0);
        $secondaryTargets = $this->normalizeIds($data->secondary_targets ?? []);
        $narrativeReference = $this->normalizeText($data->narrative_reference ?? '', 255);

        if (in_array($actionType, ['strike', 'protect'], true) && $primaryTargetId <= 0) {
            throw AppError::validation('Questa azione richiede un bersaglio primario.', [], 'combat_target_required');
        }
        if ($actionType === 'strike' && $primaryTargetId === $actorCharacterId) {
            throw AppError::validation('Non puoi colpire te stesso con questa azione.', [], 'combat_target_self_invalid');
        }
        if (in_array($actionType, ['strike', 'protect', 'reposition'], true) && $primaryTargetId > 0) {
            $this->ensureTargetParticipant($conflictId, $primaryTargetId);
        }
        foreach ($secondaryTargets as $targetId) {
            if ($targetId === $primaryTargetId) {
                continue;
            }
            $this->ensureTargetParticipant($conflictId, $targetId);
        }

        $staminaCost = $this->computeStaminaCost($actionType, $participantState);
        $staminaCurrent = (int) ($participantState['stamina_current'] ?? 0);
        if ($actionType !== 'recover' && $staminaCurrent < $staminaCost) {
            throw AppError::validation('Stamina insufficiente per dichiarare questa azione.', [], 'combat_stamina_insufficient');
        }

        $this->execPrepared(
            'INSERT INTO combat_action_intents
                (conflict_id, actor_id, action_type, primary_target_id, secondary_targets, narrative_reference, stamina_cost_preview, resolution_status, declared_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $conflictId,
                $actorCharacterId,
                $actionType,
                $primaryTargetId > 0 ? $primaryTargetId : null,
                $this->jsonEncode($secondaryTargets),
                $narrativeReference !== '' ? $narrativeReference : null,
                $staminaCost,
                'pending',
                $this->now(),
            ],
        );
        $actionIntentId = (int) $this->db->lastInsertId();

        Hooks::fire(
            'combat.action.declared',
            $actionIntentId,
            $conflictId,
            $actorCharacterId,
            $actionType,
            $primaryTargetId,
            $secondaryTargets,
            $this->db,
        );

        return $this->getState($conflictId, $actorCharacterId, $isStaff);
    }

    /**
     * @param object $payload
     * @return array<string,mixed>
     */
    public function addGuardRelation($payload, int $actorCharacterId, bool $isStaff): array
    {
        $this->ensureRuntimeArtifacts();
        $this->assertTier2Enabled();
        if (!$this->hasTable('combat_guard_relations')) {
            throw AppError::validation('Supporto guardia non disponibile nello schema corrente.', [], 'combat_guard_schema_missing');
        }

        $data = $payload;
        $conflictId = (int) ($data->conflict_id ?? 0);
        $guardianId = (int) ($data->guardian_id ?? 0);
        $protectedId = (int) ($data->protected_id ?? 0);
        $upkeep = $this->clampInt((int) ($data->stamina_upkeep ?? 5), 0, 50);

        $this->ensurePositiveId($conflictId, 'Conflitto non valido.', 'conflict_not_found');
        $this->ensureConflictAccessible($conflictId, $actorCharacterId, $isStaff);

        if (!$isStaff && $guardianId !== $actorCharacterId) {
            throw AppError::unauthorized('Puoi gestire solo le protezioni del tuo personaggio.', [], 'combat_guard_forbidden');
        }
        if ($guardianId <= 0 || $protectedId <= 0) {
            throw AppError::validation('Guardiano o protetto non validi.', [], 'combat_guard_invalid');
        }
        if ($guardianId === $protectedId) {
            throw AppError::validation('Un personaggio non puo proteggere se stesso.', [], 'combat_guard_self_invalid');
        }

        $this->participantStateByCharacter($conflictId, $guardianId);
        $this->participantStateByCharacter($conflictId, $protectedId);

        $this->execPrepared(
            'UPDATE combat_guard_relations
             SET is_active = 0, updated_at = ?
             WHERE conflict_id = ?
               AND guardian_id = ?
               AND is_active = 1',
            [$this->now(), $conflictId, $guardianId],
        );

        $this->execPrepared(
            'INSERT INTO combat_guard_relations
                (conflict_id, guardian_id, protected_id, stamina_upkeep, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$conflictId, $guardianId, $protectedId, $upkeep, 1, $this->now(), $this->now()],
        );

        return $this->getState($conflictId, $actorCharacterId, $isStaff);
    }

    /**
     * @param object $payload
     * @return array<string,mixed>
     */
    public function removeGuardRelation($payload, int $actorCharacterId, bool $isStaff): array
    {
        $this->ensureRuntimeArtifacts();
        $this->assertTier2Enabled();
        if (!$this->hasTable('combat_guard_relations')) {
            throw AppError::validation('Supporto guardia non disponibile nello schema corrente.', [], 'combat_guard_schema_missing');
        }

        $data = $payload;
        $conflictId = (int) ($data->conflict_id ?? 0);
        $guardianId = (int) ($data->guardian_id ?? 0);

        $this->ensurePositiveId($conflictId, 'Conflitto non valido.', 'conflict_not_found');
        $this->ensureConflictAccessible($conflictId, $actorCharacterId, $isStaff);

        if (!$isStaff && $guardianId !== $actorCharacterId) {
            throw AppError::unauthorized('Puoi rimuovere solo le protezioni del tuo personaggio.', [], 'combat_guard_forbidden');
        }
        if ($guardianId <= 0) {
            throw AppError::validation('Guardiano non valido.', [], 'combat_guard_invalid');
        }

        $this->execPrepared(
            'UPDATE combat_guard_relations
             SET is_active = 0, updated_at = ?
             WHERE conflict_id = ?
               AND guardian_id = ?
               AND is_active = 1',
            [$this->now(), $conflictId, $guardianId],
        );

        return $this->getState($conflictId, $actorCharacterId, $isStaff);
    }

    /**
     * @param object $payload
     * @return array<string,mixed>
     */
    public function saveEnvironment($payload, int $actorCharacterId, bool $isStaff): array
    {
        $this->ensureRuntimeArtifacts();
        $this->assertTier2Enabled();
        if (!$this->hasTable('combat_environment_contexts')) {
            throw AppError::validation('Supporto ambiente non disponibile nello schema corrente.', [], 'combat_environment_schema_missing');
        }
        if (!$isStaff) {
            throw AppError::unauthorized('Solo lo staff puo modificare il contesto ambientale.', [], 'combat_environment_forbidden');
        }

        $data = $payload;
        $conflictId = (int) ($data->conflict_id ?? 0);
        $this->ensurePositiveId($conflictId, 'Conflitto non valido.', 'conflict_not_found');
        $this->ensureConflictAccessible($conflictId, $actorCharacterId, true);

        $visibility = $this->clampInt((int) ($data->visibility_level ?? 10), 0, 10);
        $mobility = $this->clampInt((int) ($data->mobility_level ?? 10), 0, 10);
        $hazard = $this->clampInt((int) ($data->hazard_level ?? 0), 0, 10);
        $cover = $this->clampInt((int) ($data->cover_density ?? 0), 0, 10);
        $notes = $this->normalizeText($data->notes ?? '', 500);

        if (!empty($this->environmentRow($conflictId))) {
            $this->execPrepared(
                'UPDATE combat_environment_contexts
                 SET visibility_level = ?, mobility_level = ?, hazard_level = ?, cover_density = ?, notes = ?, updated_at = ?
                 WHERE conflict_id = ? LIMIT 1',
                [$visibility, $mobility, $hazard, $cover, $notes !== '' ? $notes : null, $this->now(), $conflictId],
            );
        } else {
            $this->execPrepared(
                'INSERT INTO combat_environment_contexts
                    (conflict_id, visibility_level, mobility_level, hazard_level, cover_density, notes, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$conflictId, $visibility, $mobility, $hazard, $cover, $notes !== '' ? $notes : null, $this->now(), $this->now()],
            );
        }

        return $this->getState($conflictId, $actorCharacterId, true);
    }

    private function activeEffectsForTarget(int $conflictId, int $targetActorId): array
    {
        $effects = [];
        foreach ($this->listEffects($conflictId, true) as $effect) {
            if ((int) ($effect['target_actor_id'] ?? 0) !== $targetActorId) {
                continue;
            }
            $effects[] = $effect;
        }

        return $effects;
    }

    private function effectBonus(array $effects, string $context): int
    {
        $bonus = 0;
        foreach ($effects as $effect) {
            $type = strtolower(trim((string) ($effect['effect_type'] ?? '')));
            $intensity = max(1, (int) ($effect['intensity'] ?? 1));
            if ($type === 'protected') {
                $bonus += ($context === 'defense') ? (3 * $intensity) : (1 * $intensity);
                continue;
            }
            if ($type === 'exposed') {
                $bonus -= 2 * $intensity;
                continue;
            }
            if ($type === 'unbalanced') {
                $bonus -= 3 * $intensity;
                continue;
            }
            if ($type === 'pressured') {
                $bonus -= 1 * $intensity;
                continue;
            }
            if ($type === 'exhausted') {
                $bonus -= 4 * $intensity;
                continue;
            }
            if ($type === 'wounded_light') {
                $bonus -= 2 * $intensity;
                continue;
            }
        }

        return $bonus;
    }

    private function capabilityScore(array $participantState, string $context, array $effects): int
    {
        $staminaCurrent = max(0, (int) ($participantState['stamina_current'] ?? 0));
        $staminaMax = max(1, (int) ($participantState['stamina_max'] ?? 100));
        $fatigue = max(0, (int) ($participantState['fatigue_level'] ?? 0));
        $readiness = max(0, (int) ($participantState['combat_readiness'] ?? 100));
        $pressureLevel = max(0, (int) ($participantState['pressure_level'] ?? 0));
        $threatExposure = max(0, (int) ($participantState['threat_exposure'] ?? 0));

        $score = 10;
        $score += (int) floor(($staminaCurrent / $staminaMax) * 8);
        $score += (int) floor($readiness / 20);
        $score -= $fatigue * 2;
        if ($this->isTier2Enabled()) {
            $score -= (int) floor($pressureLevel / 2);
            $score -= (int) floor($threatExposure / 3);
        }
        $score += $this->effectBonus($effects, $context);

        return $score;
    }

    private function teamSyntheticMap(array $syntheticState): array
    {
        $map = [];
        foreach ((array) ($syntheticState['teams'] ?? []) as $team) {
            $teamKey = trim((string) ($team['team_key'] ?? ''));
            if ($teamKey !== '') {
                $map[$teamKey] = $team;
            }
        }

        return $map;
    }

    private function teamSyntheticModifier(array $team): int
    {
        $control = (float) ($team['control_avg'] ?? 0.0);
        $pressure = (float) ($team['pressure_avg'] ?? 0.0);
        $attrition = (float) ($team['attrition_avg'] ?? 0.0);
        $delta = ($control - $pressure - $attrition) / 3.5;

        return $this->clampInt((int) round($delta), -3, 3);
    }

    private function momentumModifier(string $teamKey, array $syntheticState): int
    {
        $holderTeamKey = trim((string) ($syntheticState['momentum']['holder_team_key'] ?? ''));
        $delta = (float) ($syntheticState['momentum']['delta'] ?? 0.0);
        if ($holderTeamKey === '' || $teamKey === '') {
            return 0;
        }
        if ($holderTeamKey === $teamKey) {
            return $delta >= 6.0 ? 2 : 1;
        }

        return $delta >= 3.0 ? -1 : 0;
    }

    private function environmentModifier(string $actionType, ?array $environmentRaw, bool $forActor): int
    {
        if ($environmentRaw === null) {
            return 0;
        }

        $visibility = (int) ($environmentRaw['visibility_level'] ?? 10);
        $mobility = (int) ($environmentRaw['mobility_level'] ?? 10);
        $hazard = (int) ($environmentRaw['hazard_level'] ?? 0);
        $cover = (int) ($environmentRaw['cover_density'] ?? 0);
        $modifier = 0;

        if ($forActor) {
            if ($actionType === 'strike') {
                if ($visibility <= 3) {
                    $modifier -= 2;
                } elseif ($visibility <= 6) {
                    $modifier -= 1;
                }
                if ($mobility <= 4) {
                    $modifier -= 1;
                }
            } elseif (in_array($actionType, ['reposition', 'disengage'], true)) {
                if ($mobility >= 8) {
                    $modifier += 1;
                } elseif ($mobility <= 4) {
                    $modifier -= 2;
                }
            } elseif ($actionType === 'recover' && $hazard >= 5) {
                $modifier -= ($hazard >= 8) ? 2 : 1;
            } elseif (in_array($actionType, ['defend', 'protect'], true) && $cover >= 6) {
                $modifier += 1;
            }

            return $modifier;
        }

        if ($actionType === 'strike') {
            if ($cover >= 8) {
                $modifier += 2;
            } elseif ($cover >= 5) {
                $modifier += 1;
            }
            if ($visibility <= 4) {
                $modifier += 1;
            }
        } elseif (in_array($actionType, ['defend', 'protect'], true) && $cover >= 5) {
            $modifier += 1;
        }

        return $modifier;
    }

    private function outcomeCategoryFromDelta(int $delta): string
    {
        if ($delta >= 10) {
            return 'decisive_success';
        }
        if ($delta >= 6) {
            return 'strong_success';
        }
        if ($delta >= 2) {
            return 'partial_success';
        }
        if ($delta >= -1) {
            return 'contested_exchange';
        }
        if ($delta >= -5) {
            return 'partial_failure';
        }
        if ($delta >= -9) {
            return 'strong_failure';
        }
        return 'catastrophic_failure';
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

    private function recalcReadiness(int $staminaCurrent, int $staminaMax, int $fatigue): int
    {
        if ($staminaMax <= 0) {
            $staminaMax = 100;
        }

        $ratio = (float) $staminaCurrent / (float) $staminaMax;
        $value = (int) round(($ratio * 70) + ((10 - min(10, $fatigue)) * 3));
        return $this->clampInt($value, 0, 100);
    }

    private function updateParticipantState(int $conflictId, int $characterId, array $changes): array
    {
        $state = $this->participantStateByCharacter($conflictId, $characterId);
        $staminaMax = max(1, (int) ($state['stamina_max'] ?? 100));
        $staminaCurrent = $this->clampInt((int) (($changes['stamina_current'] ?? $state['stamina_current']) ?? 0), 0, $staminaMax);
        $fatigueLevel = $this->clampInt((int) (($changes['fatigue_level'] ?? $state['fatigue_level']) ?? 0), 0, 10);
        $status = (string) ($changes['status'] ?? $state['status'] ?? 'active');
        $stance = (string) ($changes['stance'] ?? $state['stance'] ?? 'neutral');
        $pressureLevel = (int) (($changes['pressure_level'] ?? $state['pressure_level']) ?? 0);
        $threatExposure = (int) (($changes['threat_exposure'] ?? $state['threat_exposure']) ?? 0);
        $engagementTargets = array_key_exists('engagement_targets', $changes)
            ? $this->normalizeIds($changes['engagement_targets'])
            : (array) ($state['engagement_targets'] ?? []);
        $combatReadiness = $this->recalcReadiness($staminaCurrent, $staminaMax, $fatigueLevel);
        if ($staminaCurrent <= 0 && $status === 'active') {
            $status = 'incapacitated';
        }

        $this->execPrepared(
            'UPDATE combat_participant_states
             SET stamina_current = ?, fatigue_level = ?, stance = ?, pressure_level = ?, threat_exposure = ?, combat_readiness = ?, engagement_targets_json = ?, status = ?, last_action_at = ?, updated_at = ?
             WHERE conflict_id = ? AND character_id = ? LIMIT 1',
            [
                $staminaCurrent,
                $fatigueLevel,
                $stance,
                $pressureLevel,
                $threatExposure,
                $combatReadiness,
                $this->jsonEncode($engagementTargets),
                $status,
                $this->now(),
                $this->now(),
                $conflictId,
                $characterId,
            ],
        );

        return $this->participantStateByCharacter($conflictId, $characterId);
    }

    private function deactivateEffects(int $conflictId, int $targetActorId, array $effectTypes): void
    {
        if (empty($effectTypes)) {
            return;
        }

        $normalized = [];
        foreach ($effectTypes as $effectType) {
            $effectType = strtolower(trim((string) $effectType));
            if ($effectType !== '') {
                $normalized[] = $effectType;
            }
        }
        if (empty($normalized)) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($normalized), '?'));
        $params = [$conflictId, $targetActorId];
        foreach ($normalized as $effectType) {
            $params[] = $effectType;
        }

        $this->execPrepared(
            'UPDATE combat_state_effects
             SET is_active = 0, resolved_at = ?
             WHERE conflict_id = ?
               AND target_actor_id = ?
               AND effect_type IN (' . $placeholders . ')
               AND is_active = 1',
            array_merge([$this->now()], $params),
        );
    }

    private function applyEffect(int $conflictId, int $sourceActorId, int $targetActorId, string $effectType, int $intensity = 1): void
    {
        $effectType = strtolower(trim($effectType));
        if ($effectType === '' || $targetActorId <= 0) {
            return;
        }

        $this->execPrepared(
            'INSERT INTO combat_state_effects
                (conflict_id, source_actor_id, target_actor_id, effect_type, intensity, duration_model, duration_value, removal_conditions_json, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $conflictId,
                $sourceActorId > 0 ? $sourceActorId : null,
                $targetActorId,
                $effectType,
                max(1, $intensity),
                'combat',
                0,
                null,
                1,
                $this->now(),
            ],
        );
    }

    private function outcomePayload(array $intent, array $actorState, ?array $targetState): array
    {
        $conflictId = (int) ($intent['conflict_id'] ?? 0);
        $actionType = (string) ($intent['action_type'] ?? '');
        $actorEffects = $this->activeEffectsForTarget($conflictId, (int) ($actorState['character_id'] ?? 0));
        $targetEffects = $targetState !== null
            ? $this->activeEffectsForTarget($conflictId, (int) ($targetState['character_id'] ?? 0))
            : [];
        $environmentRaw = $this->isTier2Enabled() ? $this->buildEnvironmentRaw($conflictId) : null;
        $syntheticState = ['teams' => [], 'momentum' => ['holder_team_key' => '', 'delta' => 0.0]];
        $teamMap = [];
        if ($this->isTier2Enabled()) {
            $context = $this->getCombatContextRow($conflictId);
            if (!empty($context)) {
                $participantStates = $this->listParticipantStates($conflictId);
                $syntheticState = $this->buildSyntheticState(
                    $context,
                    $participantStates,
                    $this->listEffects($conflictId, true),
                    $this->listGuardRelations($conflictId),
                    $environmentRaw,
                );
            }
            $teamMap = $this->teamSyntheticMap($syntheticState);
        }
        $actorTeamKey = (string) ($actorState['team_key'] ?? 'side_a');
        $targetTeamKey = $targetState !== null ? (string) ($targetState['team_key'] ?? 'side_b') : '';

        $actorScore = $this->capabilityScore($actorState, 'offense', $actorEffects);
        $targetScore = $targetState !== null
            ? $this->capabilityScore($targetState, 'defense', $targetEffects)
            : 12;

        if ($this->isTier2Enabled()) {
            $actorScore += $this->teamSyntheticModifier($teamMap[$actorTeamKey] ?? []);
            $actorScore += $this->momentumModifier($actorTeamKey, $syntheticState);
            $actorScore += $this->environmentModifier($actionType, $environmentRaw, true);
            if ($targetState !== null) {
                $targetScore += $this->teamSyntheticModifier($teamMap[$targetTeamKey] ?? []);
                $targetScore += $this->momentumModifier($targetTeamKey, $syntheticState);
            }
            $targetScore += $this->environmentModifier($actionType, $environmentRaw, false);
        }

        if ($actionType === 'recover') {
            $targetScore = 10;
        } elseif ($actionType === 'defend') {
            $targetScore = 11;
        } elseif ($actionType === 'disengage') {
            $targetScore = $targetState !== null ? max(10, $targetScore - 2) : 11;
        } elseif ($actionType === 'protect') {
            $targetScore = 12;
        }

        $scorePayload = [
            'actor_score' => $actorScore,
            'target_score' => $targetScore,
            'delta' => 0,
            'outcome_category' => '',
            'modifiers' => [],
        ];
        $filtered = Hooks::filter(
            'combat.resolve.scores',
            $scorePayload,
            $intent,
            $actorState,
            $targetState,
            $environmentRaw,
            $this->db,
        );
        if (!is_array($filtered)) {
            return $scorePayload;
        }

        $filtered['actor_score'] = (int) ($filtered['actor_score'] ?? $scorePayload['actor_score']);
        $filtered['target_score'] = (int) ($filtered['target_score'] ?? $scorePayload['target_score']);
        $filtered['delta'] = $filtered['actor_score'] - $filtered['target_score'] + random_int(-4, 4);
        $filtered['outcome_category'] = $this->outcomeCategoryFromDelta((int) $filtered['delta']);

        return $filtered;
    }

    private function summaryText(string $actionType, string $category, int $actorId, int $targetId = 0): string
    {
        $map = [
            'decisive_success' => 'successo decisivo',
            'strong_success' => 'successo netto',
            'partial_success' => 'successo parziale',
            'contested_exchange' => 'scambio contestato',
            'partial_failure' => 'fallimento parziale',
            'strong_failure' => 'fallimento netto',
            'catastrophic_failure' => 'fallimento catastrofico',
        ];
        $label = $map[$category] ?? $category;
        $text = 'PG #' . $actorId . ' esegue ' . $actionType . ' con ' . $label;
        if ($targetId > 0) {
            $text .= ' su PG #' . $targetId;
        }
        $text .= '.';

        return $text;
    }

    private function stateShiftMap(string $actionType, string $category): array
    {
        $map = [
            'actor_pressure' => 0,
            'actor_threat' => 0,
            'target_pressure' => 0,
            'target_threat' => 0,
        ];

        if ($actionType === 'strike') {
            $table = [
                'decisive_success' => [1, 0, 4, 3],
                'strong_success' => [1, 0, 3, 2],
                'partial_success' => [1, 0, 2, 1],
                'contested_exchange' => [2, 1, 1, 1],
                'partial_failure' => [2, 2, 0, 0],
                'strong_failure' => [3, 3, -1, -1],
                'catastrophic_failure' => [4, 4, -1, -1],
            ];
        } elseif ($actionType === 'defend') {
            $table = [
                'decisive_success' => [-3, -2, 0, 0],
                'strong_success' => [-2, -2, 0, 0],
                'partial_success' => [-1, -1, 0, 0],
                'contested_exchange' => [0, 0, 0, 0],
                'partial_failure' => [1, 1, 0, 0],
                'strong_failure' => [2, 1, 0, 0],
                'catastrophic_failure' => [3, 2, 0, 0],
            ];
        } elseif ($actionType === 'reposition') {
            $table = [
                'decisive_success' => [-2, -3, 1, 2],
                'strong_success' => [-2, -2, 1, 1],
                'partial_success' => [-1, -1, 1, 1],
                'contested_exchange' => [0, 0, 0, 0],
                'partial_failure' => [1, 2, 0, 0],
                'strong_failure' => [2, 3, -1, -1],
                'catastrophic_failure' => [3, 3, -1, -1],
            ];
        } elseif ($actionType === 'recover') {
            $table = [
                'decisive_success' => [-4, -2, 0, 0],
                'strong_success' => [-3, -1, 0, 0],
                'partial_success' => [-2, -1, 0, 0],
                'contested_exchange' => [-1, 0, 0, 0],
                'partial_failure' => [0, 0, 0, 0],
                'strong_failure' => [1, 1, 0, 0],
                'catastrophic_failure' => [2, 2, 0, 0],
            ];
        } elseif ($actionType === 'protect') {
            $table = [
                'decisive_success' => [1, 0, -1, -3],
                'strong_success' => [1, 0, -1, -2],
                'partial_success' => [1, 0, 0, -1],
                'contested_exchange' => [1, 1, 0, 0],
                'partial_failure' => [2, 2, 0, 1],
                'strong_failure' => [2, 2, 0, 2],
                'catastrophic_failure' => [3, 3, 0, 2],
            ];
        } else {
            $table = [
                'decisive_success' => [-2, -4, 0, 0],
                'strong_success' => [-2, -3, 0, 0],
                'partial_success' => [-1, -2, 0, 0],
                'contested_exchange' => [0, 0, 0, 0],
                'partial_failure' => [1, 2, 0, 0],
                'strong_failure' => [2, 3, 0, 0],
                'catastrophic_failure' => [3, 4, 0, 0],
            ];
        }

        $values = $table[$category] ?? [0, 0, 0, 0];
        $map['actor_pressure'] = (int) $values[0];
        $map['actor_threat'] = (int) $values[1];
        $map['target_pressure'] = (int) $values[2];
        $map['target_threat'] = (int) $values[3];

        return $map;
    }

    private function updateCombatProgressionContext(int $conflictId, string $actionType, string $category): void
    {
        $context = $this->getCombatContextRow($conflictId);
        if (empty($context)) {
            return;
        }

        $progressionIndex = (int) ($context->progression_index ?? 0) + 1;
        if (!$this->isTier2Enabled()) {
            $this->execPrepared(
                'UPDATE combat_contexts
                 SET progression_index = ?, updated_at = ?
                 WHERE conflict_id = ? LIMIT 1',
                [$progressionIndex, $this->now(), $conflictId],
            );
            return;
        }

        $escalationLevel = (int) ($context->escalation_level ?? 1);
        $environmentRaw = $this->buildEnvironmentRaw($conflictId);
        $hazardLevel = (int) ($environmentRaw['hazard_level'] ?? 0);
        $visibilityLevel = (int) ($environmentRaw['visibility_level'] ?? 10);
        $delta = 0;

        if ($actionType === 'strike') {
            $delta += 1;
        } elseif ($actionType === 'recover' && in_array($category, ['decisive_success', 'strong_success', 'partial_success'], true)) {
            $delta -= 1;
        } elseif ($actionType === 'disengage') {
            $delta += in_array($category, ['decisive_success', 'strong_success', 'partial_success'], true) ? -1 : 1;
        }

        if (in_array($category, ['decisive_success', 'strong_success', 'catastrophic_failure'], true)) {
            $delta += 1;
        }
        if ($hazardLevel >= 7) {
            $delta += 1;
        }
        if ($actionType === 'strike' && $visibilityLevel <= 3) {
            $delta += 1;
        }

        $escalationLevel = $this->clampInt($escalationLevel + $delta, 1, 5);

        $this->execPrepared(
            'UPDATE combat_contexts
             SET progression_index = ?, escalation_level = ?, updated_at = ?
             WHERE conflict_id = ? LIMIT 1',
            [$progressionIndex, $escalationLevel, $this->now(), $conflictId],
        );
    }

    private function applyResolvedOutcome(int $conflictId, array $intent, int $resolvedBy): array
    {
        $actorId = (int) ($intent['actor_id'] ?? 0);
        $targetId = (int) ($intent['primary_target_id'] ?? 0);
        $actionType = (string) ($intent['action_type'] ?? '');

        $actorState = $this->participantStateByCharacter($conflictId, $actorId);
        $targetState = $targetId > 0 ? $this->participantStateByCharacter($conflictId, $targetId) : null;
        $cost = (int) ($intent['stamina_cost_preview'] ?? 0);
        $payload = $this->outcomePayload($intent, $actorState, $targetState);
        $category = (string) ($payload['outcome_category'] ?? 'partial_success');

        $actorStamina = (int) ($actorState['stamina_current'] ?? 0) - $cost;
        $actorFatigue = (int) ($actorState['fatigue_level'] ?? 0);
        $targetStamina = $targetState !== null ? (int) ($targetState['stamina_current'] ?? 0) : 0;
        $targetFatigue = $targetState !== null ? (int) ($targetState['fatigue_level'] ?? 0) : 0;
        $actorPressure = (int) ($actorState['pressure_level'] ?? 0);
        $actorThreat = (int) ($actorState['threat_exposure'] ?? 0);
        $targetPressure = $targetState !== null ? (int) ($targetState['pressure_level'] ?? 0) : 0;
        $targetThreat = $targetState !== null ? (int) ($targetState['threat_exposure'] ?? 0) : 0;
        $actorStatus = (string) ($actorState['status'] ?? 'active');
        $targetStatus = $targetState !== null ? (string) ($targetState['status'] ?? 'active') : 'active';

        if ($actionType === 'strike') {
            $targetLoss = 0;
            $actorBacklash = 0;
            if ($category === 'decisive_success') {
                $targetLoss = 24;
                $this->applyEffect($conflictId, $actorId, $targetId, 'unbalanced', 2);
                $this->applyEffect($conflictId, $actorId, $targetId, 'wounded_light', 1);
            } elseif ($category === 'strong_success') {
                $targetLoss = 18;
                $this->applyEffect($conflictId, $actorId, $targetId, 'pressured', 1);
                $this->applyEffect($conflictId, $actorId, $targetId, 'unbalanced', 1);
            } elseif ($category === 'partial_success') {
                $targetLoss = 12;
                $this->applyEffect($conflictId, $actorId, $targetId, 'pressured', 1);
            } elseif ($category === 'contested_exchange') {
                $targetLoss = 8;
                $actorBacklash = 6;
            } elseif ($category === 'partial_failure') {
                $actorBacklash = 8;
                $this->applyEffect($conflictId, $targetId, $actorId, 'exposed', 1);
            } elseif ($category === 'strong_failure') {
                $actorBacklash = 12;
                $this->applyEffect($conflictId, $targetId, $actorId, 'unbalanced', 1);
            } else {
                $actorBacklash = 18;
                $this->applyEffect($conflictId, $targetId, $actorId, 'exposed', 1);
                $this->applyEffect($conflictId, $targetId, $actorId, 'pressured', 1);
            }

            $actorStamina -= $actorBacklash;
            $actorFatigue += 1;
            if ($targetState !== null) {
                $targetStamina -= $targetLoss;
                $targetFatigue += ($targetLoss >= 18) ? 2 : (($targetLoss > 0) ? 1 : 0);
            }
        } elseif ($actionType === 'defend') {
            $actorFatigue += 1;
            $this->deactivateEffects($conflictId, $actorId, ['exposed']);
            if (in_array($category, ['decisive_success', 'strong_success', 'partial_success'], true)) {
                $this->applyEffect($conflictId, $actorId, $actorId, 'protected', $category === 'decisive_success' ? 2 : 1);
            } elseif (in_array($category, ['strong_failure', 'catastrophic_failure'], true)) {
                $this->applyEffect($conflictId, $actorId, $actorId, 'pressured', 1);
            }
        } elseif ($actionType === 'reposition') {
            $actorFatigue += 1;
            $this->deactivateEffects($conflictId, $actorId, ['pressured', 'unbalanced']);
            if ($targetState !== null && in_array($category, ['decisive_success', 'strong_success', 'partial_success'], true)) {
                $this->applyEffect($conflictId, $actorId, $targetId, 'exposed', 1);
            } elseif (in_array($category, ['strong_failure', 'catastrophic_failure'], true)) {
                $this->applyEffect($conflictId, $targetId, $actorId, 'exposed', 1);
            }
        } elseif ($actionType === 'recover') {
            $recoverGain = 12;
            if ($category === 'decisive_success') {
                $recoverGain = 24;
            } elseif ($category === 'strong_success') {
                $recoverGain = 18;
            } elseif ($category === 'partial_success') {
                $recoverGain = 14;
            } elseif ($category === 'contested_exchange') {
                $recoverGain = 8;
            } elseif ($category === 'partial_failure') {
                $recoverGain = 4;
            } else {
                $recoverGain = 0;
            }

            $actorStamina += $recoverGain;
            if ($recoverGain >= 14) {
                $actorFatigue -= 1;
            }
            $this->deactivateEffects($conflictId, $actorId, ['pressured']);
        } elseif ($actionType === 'protect') {
            $actorFatigue += 1;
            $protectedTarget = $targetId > 0 ? $targetId : $actorId;
            if (in_array($category, ['decisive_success', 'strong_success', 'partial_success'], true)) {
                $this->applyEffect($conflictId, $actorId, $protectedTarget, 'protected', $category === 'decisive_success' ? 2 : 1);
            } else {
                $this->applyEffect($conflictId, $protectedTarget, $actorId, 'pressured', 1);
            }
        } elseif ($actionType === 'disengage') {
            $actorFatigue += 1;
            if (in_array($category, ['decisive_success', 'strong_success', 'partial_success'], true)) {
                $actorStatus = 'withdrawn';
            } elseif (in_array($category, ['strong_failure', 'catastrophic_failure'], true)) {
                $this->applyEffect($conflictId, $targetId > 0 ? $targetId : $actorId, $actorId, 'exposed', 1);
            }
        }

        if ($this->isTier2Enabled()) {
            $shifts = $this->stateShiftMap($actionType, $category);
            $actorPressure += (int) ($shifts['actor_pressure'] ?? 0);
            $actorThreat += (int) ($shifts['actor_threat'] ?? 0);
            if ($targetState !== null) {
                $targetPressure += (int) ($shifts['target_pressure'] ?? 0);
                $targetThreat += (int) ($shifts['target_threat'] ?? 0);
            }
        }

        $actorState = $this->updateParticipantState($conflictId, $actorId, [
            'stamina_current' => $actorStamina,
            'fatigue_level' => $actorFatigue,
            'pressure_level' => $actorPressure,
            'threat_exposure' => $actorThreat,
            'status' => $actorStatus,
            'engagement_targets' => $targetId > 0 ? [$targetId] : [],
        ]);
        if ($targetState !== null) {
            $targetState = $this->updateParticipantState($conflictId, $targetId, [
                'stamina_current' => $targetStamina,
                'fatigue_level' => $targetFatigue,
                'pressure_level' => $targetPressure,
                'threat_exposure' => $targetThreat,
                'status' => $targetStatus,
            ]);
        }
        $this->updateCombatProgressionContext($conflictId, $actionType, $category);

        $summary = $this->summaryText($actionType, $category, $actorId, $targetId);
        $updatedContext = $this->getCombatContextRow($conflictId);
        $resolutionPayload = [
            'action_type' => $actionType,
            'actor_id' => $actorId,
            'target_id' => $targetId,
            'outcome_category' => $category,
            'actor_state' => [
                'stamina_current' => (int) ($actorState['stamina_current'] ?? 0),
                'fatigue_level' => (int) ($actorState['fatigue_level'] ?? 0),
                'pressure_level' => (int) ($actorState['pressure_level'] ?? 0),
                'threat_exposure' => (int) ($actorState['threat_exposure'] ?? 0),
                'status' => (string) ($actorState['status'] ?? 'active'),
            ],
            'target_state' => $targetState !== null ? [
                'stamina_current' => (int) ($targetState['stamina_current'] ?? 0),
                'fatigue_level' => (int) ($targetState['fatigue_level'] ?? 0),
                'pressure_level' => (int) ($targetState['pressure_level'] ?? 0),
                'threat_exposure' => (int) ($targetState['threat_exposure'] ?? 0),
                'status' => (string) ($targetState['status'] ?? 'active'),
            ] : null,
            'score' => $payload,
            'combat_context' => [
                'escalation_level' => (int) ($updatedContext->escalation_level ?? 1),
                'progression_index' => (int) ($updatedContext->progression_index ?? 0),
            ],
        ];

        return [
            'summary' => $summary,
            'outcome_category' => $category,
            'resolution_payload' => $resolutionPayload,
            'resolved_by' => $resolvedBy,
            'actor_state' => $actorState,
            'target_state' => $targetState,
        ];
    }

    private function publishOutcomeMessage(int $locationId, int $actorCharacterId, string $summary, array $resolutionPayload)
    {
        if ($locationId <= 0 || $actorCharacterId <= 0) {
            return null;
        }

        $body = '<div class="fato-message text-center">'
            . '<p class="mb-1"><b>Esito combattimento</b></p>'
            . '<p class="mb-0">' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '</p>'
            . '</div>';
        $meta = $this->jsonEncode([
            'command' => 'fato',
            'event_type' => 'combat_action_resolved',
            'combat' => $resolutionPayload,
        ]);

        return $this->locationMessageService()->insertMessage(
            $locationId,
            $actorCharacterId,
            3,
            $body,
            $meta,
        );
    }

    private function insertConflictActionTargets(int $actionId, int $primaryTargetId, array $secondaryTargets): void
    {
        if ($actionId <= 0 || !$this->hasTable('conflict_action_targets')) {
            return;
        }

        $targets = [];
        if ($primaryTargetId > 0) {
            $targets[] = $primaryTargetId;
        }
        foreach ($secondaryTargets as $targetId) {
            $targetId = (int) $targetId;
            if ($targetId > 0 && !in_array($targetId, $targets, true)) {
                $targets[] = $targetId;
            }
        }

        foreach ($targets as $targetId) {
            $this->execPrepared(
                'INSERT INTO conflict_action_targets
                    (conflict_action_id, target_type, target_id, team_key, created_at)
                 VALUES (?, ?, ?, ?, ?)',
                [$actionId, 'character', $targetId, null, $this->now()],
            );
        }
    }

    private function insertConflictActionLog(int $conflictId, array $intent, string $summary, array $resolutionPayload, int $chatMessageId = 0): void
    {
        if (!$this->hasTable('conflict_actions')) {
            return;
        }

        $columns = ['conflict_id', 'actor_id', 'action_type', 'action_body', 'meta_json'];
        $values = ['?', '?', '?', '?', '?'];
        $params = [
            $conflictId,
            (int) ($intent['actor_id'] ?? 0),
            'action',
            $summary,
            $this->jsonEncode($resolutionPayload),
        ];

        if ($this->hasColumn('conflict_actions', 'actor_type')) {
            $columns[] = 'actor_type';
            $values[] = '?';
            $params[] = 'character';
        }
        if ($this->hasColumn('conflict_actions', 'action_kind')) {
            $columns[] = 'action_kind';
            $values[] = '?';
            $params[] = 'combat_action';
        }
        if ($this->hasColumn('conflict_actions', 'action_mode')) {
            $columns[] = 'action_mode';
            $values[] = '?';
            $params[] = 'combat';
        }
        if ($this->hasColumn('conflict_actions', 'chat_message_id')) {
            $columns[] = 'chat_message_id';
            $values[] = '?';
            $params[] = $chatMessageId > 0 ? $chatMessageId : null;
        }
        if ($this->hasColumn('conflict_actions', 'resolution_type')) {
            $columns[] = 'resolution_type';
            $values[] = '?';
            $params[] = 'combat';
        }
        if ($this->hasColumn('conflict_actions', 'resolution_status')) {
            $columns[] = 'resolution_status';
            $values[] = '?';
            $params[] = 'resolved';
        }
        if ($this->hasColumn('conflict_actions', 'resolved_at')) {
            $columns[] = 'resolved_at';
            $values[] = '?';
            $params[] = $this->now();
        }

        $this->execPrepared(
            'INSERT INTO conflict_actions (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', $values) . ')',
            $params,
        );

        $actionId = (int) $this->db->lastInsertId();
        $primaryTargetId = (int) ($intent['primary_target_id'] ?? 0);
        $secondaryTargets = $this->decodeJsonArray($intent['secondary_targets'] ?? '[]');
        $this->insertConflictActionTargets($actionId, $primaryTargetId, $secondaryTargets);
    }

    private function evaluateCombatCompletion(int $conflictId, int $locationId, int $actorCharacterId): void
    {
        $states = $this->listParticipantStates($conflictId);
        $activeSides = [];
        foreach ($states as $row) {
            if ((string) ($row['status'] ?? 'active') !== 'active') {
                continue;
            }
            $teamKey = trim((string) ($row['team_key'] ?? 'side_a'));
            if ($teamKey === '') {
                $teamKey = 'side_a';
            }
            $activeSides[$teamKey] = true;
        }

        if (count($activeSides) > 1) {
            return;
        }

        $resolutionCondition = 'last_side_standing';
        if (count($activeSides) === 0) {
            $resolutionCondition = 'mutual_exhaustion';
        }

        $this->execPrepared(
            'UPDATE combat_contexts
             SET status = ?, resolution_condition = ?, completed_at = ?, updated_at = ?
             WHERE conflict_id = ? LIMIT 1',
            ['completed', $resolutionCondition, $this->now(), $this->now(), $conflictId],
        );
        $this->updateConflictStatusAwaiting($conflictId);

        if ($locationId > 0 && $actorCharacterId > 0) {
            $summary = $resolutionCondition === 'mutual_exhaustion'
                ? 'Il combattimento si esaurisce senza un vincitore netto.'
                : 'Il combattimento ha espresso un vincitore narrativo.';
            $this->publishOutcomeMessage($locationId, $actorCharacterId, $summary, [
                'event_type' => 'combat_completed',
                'conflict_id' => $conflictId,
                'resolution_condition' => $resolutionCondition,
            ]);
        }
    }

    public function resolveAction(int $actionIntentId, int $resolverCharacterId, bool $isStaff): array
    {
        $this->ensureRuntimeArtifacts();
        $this->ensurePositiveId($actionIntentId, 'Azione di combattimento non valida.', 'combat_action_not_found');
        if (!$isStaff) {
            throw AppError::unauthorized('Solo lo staff puo risolvere le azioni di combattimento.', [], 'combat_resolve_forbidden');
        }

        $intent = $this->firstPrepared(
            'SELECT *
             FROM combat_action_intents
             WHERE id = ?
             LIMIT 1',
            [$actionIntentId],
        );
        if (empty($intent)) {
            throw AppError::validation('Azione di combattimento non trovata.', [], 'combat_action_not_found');
        }
        if (strtolower(trim((string) ($intent->resolution_status ?? 'pending'))) !== 'pending') {
            throw AppError::validation('Questa azione e gia stata risolta.', [], 'combat_action_already_resolved');
        }

        $conflictId = (int) ($intent->conflict_id ?? 0);
        $detail = $this->ensureConflictAccessible($conflictId, $resolverCharacterId, true);
        $locationId = (int) ($detail['conflict']->location_id ?? 0);

        $this->begin();
        try {
            $resolved = $this->applyResolvedOutcome($conflictId, (array) $intent, $resolverCharacterId);
            $messageRow = $this->publishOutcomeMessage(
                $locationId,
                (int) ($intent->actor_id ?? 0),
                (string) ($resolved['summary'] ?? ''),
                (array) ($resolved['resolution_payload'] ?? []),
            );
            $messageId = (int) ($messageRow->id ?? 0);

            $this->execPrepared(
                'UPDATE combat_action_intents
                 SET resolution_status = ?, outcome_category = ?, outcome_summary = ?, resolution_payload_json = ?, chat_message_id = ?, resolved_at = ?, resolved_by = ?
                 WHERE id = ? LIMIT 1',
                [
                    'resolved',
                    (string) ($resolved['outcome_category'] ?? ''),
                    (string) ($resolved['summary'] ?? ''),
                    $this->jsonEncode($resolved['resolution_payload'] ?? []),
                    $messageId > 0 ? $messageId : null,
                    $this->now(),
                    $resolverCharacterId > 0 ? $resolverCharacterId : null,
                    $actionIntentId,
                ],
            );

            $this->insertConflictActionLog(
                $conflictId,
                (array) $intent,
                (string) ($resolved['summary'] ?? ''),
                (array) ($resolved['resolution_payload'] ?? []),
                $messageId,
            );
            $this->evaluateCombatCompletion($conflictId, $locationId, (int) ($intent->actor_id ?? 0));
            $this->commit();
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }

        Hooks::fire(
            'combat.action.resolved',
            $actionIntentId,
            $conflictId,
            (array) $intent,
            (array) $resolved,
            $resolverCharacterId,
            $this->db,
        );

        return $this->getState($conflictId, max(0, $resolverCharacterId), true);
    }
}
