<?php

declare(strict_types=1);

namespace Modules\Logeon\CombatCoordination\Services;

use App\Services\ConflictService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;
use Core\ModuleManager;
use Modules\Logeon\NarrativeCombat\Services\NarrativeCombatService;

class CombatCoordinationService
{
    private const DEPENDENCY_MODULE_ID = 'logeon.narrative-combat';

    /** @var DbAdapterInterface */
    private $db;
    /** @var ModuleManager|null */
    private $moduleManager = null;
    /** @var ConflictService|null */
    private $conflictService = null;
    /** @var NarrativeCombatService|null */
    private $combatService = null;

    public function __construct(
        DbAdapterInterface $db = null,
        ModuleManager $moduleManager = null,
        ConflictService $conflictService = null,
        NarrativeCombatService $combatService = null,
    ) {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->moduleManager = $moduleManager;
        $this->conflictService = $conflictService;
        $this->combatService = $combatService;
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

    private function combatService(): NarrativeCombatService
    {
        if ($this->combatService instanceof NarrativeCombatService) {
            return $this->combatService;
        }
        $this->combatService = new NarrativeCombatService($this->db, null, null, $this->moduleManager());
        return $this->combatService;
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

    private function ensureSchema(): void
    {
        if ($this->hasTable('combat_coordination_plans') && $this->hasTable('combat_coordination_logs')) {
            return;
        }
        throw AppError::validation('Schema Combat Coordination non disponibile.', [], 'combat_coordination_schema_missing');
    }

    private function baseCombatTierLevel(): int
    {
        $tier = (int) $this->moduleManager()->getSetting(self::DEPENDENCY_MODULE_ID, 'combat_depth', 2);
        return $tier <= 1 ? 1 : 2;
    }

    private function ensureBaseTier2Enabled(): void
    {
        if ($this->baseCombatTierLevel() >= 2) {
            return;
        }
        throw AppError::validation('Combat Coordination richiede Narrative Combat su Tier 2.', [], 'combat_coordination_requires_tier2');
    }

    private function normalizeText($value, int $max = 255): string
    {
        return mb_substr(trim((string) $value), 0, $max);
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

    private function normalizeManeuver($value): string
    {
        $value = strtolower(trim((string) $value));
        $allowed = ['focus_fire', 'shield_wall', 'extraction', 'suppressive_push'];
        return in_array($value, $allowed, true) ? $value : 'focus_fire';
    }

    private function maneuverConfig(string $maneuverKey): array
    {
        $map = [
            'focus_fire' => ['label' => 'Focus Fire', 'required_action_type' => 'strike', 'leader_bonus' => 2],
            'shield_wall' => ['label' => 'Shield Wall', 'required_action_type' => 'defend', 'leader_bonus' => 2],
            'extraction' => ['label' => 'Extraction', 'required_action_type' => 'protect', 'leader_bonus' => 2],
            'suppressive_push' => ['label' => 'Suppressive Push', 'required_action_type' => 'reposition', 'leader_bonus' => 1],
        ];
        return $map[$maneuverKey] ?? $map['focus_fire'];
    }

    private function ensureConflictAccessible(int $conflictId, int $viewerCharacterId, bool $isStaff): array
    {
        $detail = $this->conflictService()->getConflict($conflictId);
        if ($isStaff) {
            return $detail;
        }

        foreach ((array) ($detail['participants'] ?? []) as $participant) {
            if ((int) ($participant->character_id ?? 0) === $viewerCharacterId) {
                return $detail;
            }
        }

        throw AppError::unauthorized('Operazione non autorizzata su Combat Coordination.', [], 'combat_coordination_access_forbidden');
    }

    private function listCombatContexts(): array
    {
        $rows = $this->fetchPrepared(
            'SELECT cc.conflict_id, cc.status AS combat_status, cc.tier_level
             FROM combat_contexts cc
             ORDER BY cc.updated_at DESC, cc.conflict_id DESC',
        );
        $dataset = [];
        foreach ($rows as $row) {
            $dataset[] = [
                'conflict_id' => (int) ($row->conflict_id ?? 0),
                'label' => 'Conflitto #' . (int) ($row->conflict_id ?? 0),
                'combat_status' => (string) ($row->combat_status ?? 'active'),
                'tier_level' => (int) ($row->tier_level ?? 1),
            ];
        }

        return $dataset;
    }

    private function planDataset(array $rows): array
    {
        $dataset = [];
        foreach ($rows as $row) {
            $maneuverKey = $this->normalizeManeuver($row->maneuver_key ?? 'focus_fire');
            $config = $this->maneuverConfig($maneuverKey);
            $dataset[] = [
                'id' => (int) ($row->id ?? 0),
                'conflict_id' => (int) ($row->conflict_id ?? 0),
                'leader_id' => (int) ($row->leader_id ?? 0),
                'team_key' => (string) ($row->team_key ?? 'side_a'),
                'maneuver_key' => $maneuverKey,
                'maneuver_label' => (string) ($config['label'] ?? $maneuverKey),
                'required_action_type' => (string) ($row->required_action_type ?? $config['required_action_type']),
                'primary_target_id' => (int) ($row->primary_target_id ?? 0),
                'supporter_ids' => $this->normalizeIds(json_decode((string) ($row->supporter_ids_json ?? '[]'), true)),
                'bonus_scale' => (int) ($row->bonus_scale ?? 1),
                'notes' => (string) ($row->notes ?? ''),
                'status' => (string) ($row->status ?? 'active'),
            ];
        }
        return $dataset;
    }

    private function listPlans(int $conflictId = 0, bool $onlyActive = false): array
    {
        $sql = 'SELECT *
                FROM combat_coordination_plans
                WHERE 1 = 1';
        $params = [];
        if ($conflictId > 0) {
            $sql .= ' AND conflict_id = ?';
            $params[] = $conflictId;
        }
        if ($onlyActive) {
            $sql .= ' AND status = ?';
            $params[] = 'active';
        }
        $sql .= ' ORDER BY conflict_id DESC, id DESC';

        return $this->planDataset($this->fetchPrepared($sql, $params));
    }

    private function participantStates(int $conflictId): array
    {
        $state = $this->combatService()->getState($conflictId, 0, true);
        return (array) ($state['participant_states'] ?? []);
    }

    private function participantMap(int $conflictId): array
    {
        $map = [];
        foreach ($this->participantStates($conflictId) as $row) {
            $map[(int) ($row['character_id'] ?? 0)] = $row;
        }
        return $map;
    }

    public function adminBootstrap(): array
    {
        $this->ensureSchema();
        return [
            'contexts' => $this->listCombatContexts(),
            'plans' => $this->listPlans(),
            'maneuvers' => [
                ['value' => 'focus_fire', 'label' => 'Focus Fire'],
                ['value' => 'shield_wall', 'label' => 'Shield Wall'],
                ['value' => 'extraction', 'label' => 'Extraction'],
                ['value' => 'suppressive_push', 'label' => 'Suppressive Push'],
            ],
            'base_combat_tier' => $this->baseCombatTierLevel(),
        ];
    }

    public function savePlan($data, int $actorCharacterId, bool $isStaff): int
    {
        $this->ensureSchema();
        $this->ensureBaseTier2Enabled();

        $id = (int) ($data->id ?? 0);
        $conflictId = (int) ($data->conflict_id ?? 0);
        $leaderId = (int) ($data->leader_id ?? 0);
        $maneuverKey = $this->normalizeManeuver($data->maneuver_key ?? 'focus_fire');
        $primaryTargetId = (int) ($data->primary_target_id ?? 0);
        $supporterIds = $this->normalizeIds($data->supporter_ids ?? []);
        $bonusScale = max(1, min(3, (int) ($data->bonus_scale ?? 1)));
        $notes = $this->normalizeText($data->notes ?? '', 800);

        if ($conflictId <= 0 || $leaderId <= 0) {
            throw AppError::validation('Conflitto o leader non validi.', [], 'combat_coordination_plan_invalid');
        }
        $this->ensureConflictAccessible($conflictId, $actorCharacterId, $isStaff);
        if (!$isStaff && $leaderId !== $actorCharacterId) {
            throw AppError::unauthorized('Puoi creare solo manovre coordinate per il tuo personaggio.', [], 'combat_coordination_plan_forbidden');
        }

        $participants = $this->participantMap($conflictId);
        $leader = $participants[$leaderId] ?? null;
        if (!is_array($leader) || (string) ($leader['status'] ?? 'active') !== 'active') {
            throw AppError::validation('Leader non valido per la manovra coordinata.', [], 'combat_coordination_leader_invalid');
        }

        $teamKey = (string) ($leader['team_key'] ?? 'side_a');
        foreach ($supporterIds as $supporterId) {
            $supporter = $participants[$supporterId] ?? null;
            if (!is_array($supporter) || (string) ($supporter['team_key'] ?? '') !== $teamKey) {
                throw AppError::validation('I supporter devono appartenere allo stesso lato del leader.', [], 'combat_coordination_supporter_invalid');
            }
        }

        $config = $this->maneuverConfig($maneuverKey);
        if ($id > 0) {
            $this->execPrepared(
                'UPDATE combat_coordination_plans
                 SET conflict_id = ?, leader_id = ?, team_key = ?, maneuver_key = ?, required_action_type = ?, primary_target_id = ?, supporter_ids_json = ?, bonus_scale = ?, notes = ?, updated_at = ?
                 WHERE id = ? LIMIT 1',
                [
                    $conflictId,
                    $leaderId,
                    $teamKey,
                    $maneuverKey,
                    (string) ($config['required_action_type'] ?? 'strike'),
                    $primaryTargetId > 0 ? $primaryTargetId : null,
                    json_encode($supporterIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $bonusScale,
                    $notes !== '' ? $notes : null,
                    $this->now(),
                    $id,
                ],
            );
            return $id;
        }

        $this->execPrepared(
            'INSERT INTO combat_coordination_plans
                (conflict_id, leader_id, team_key, maneuver_key, required_action_type, primary_target_id, supporter_ids_json, bonus_scale, notes, status, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $conflictId,
                $leaderId,
                $teamKey,
                $maneuverKey,
                (string) ($config['required_action_type'] ?? 'strike'),
                $primaryTargetId > 0 ? $primaryTargetId : null,
                json_encode($supporterIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $bonusScale,
                $notes !== '' ? $notes : null,
                'active',
                $actorCharacterId > 0 ? $actorCharacterId : null,
                $this->now(),
                $this->now(),
            ],
        );
        $planId = (int) $this->db->lastInsertId();
        $this->logPlan($planId, $conflictId, $actorCharacterId, 'created', 'Manovra coordinata registrata.');
        return $planId;
    }

    public function cancelPlan(int $planId, int $actorCharacterId, bool $isStaff): array
    {
        $this->ensureSchema();
        if ($planId <= 0) {
            throw AppError::validation('Piano coordinato non valido.', [], 'combat_coordination_plan_invalid');
        }

        $row = $this->firstPrepared('SELECT * FROM combat_coordination_plans WHERE id = ? LIMIT 1', [$planId]);
        if (empty($row)) {
            throw AppError::validation('Piano coordinato non trovato.', [], 'combat_coordination_plan_missing');
        }
        if (!$isStaff && (int) ($row->leader_id ?? 0) !== $actorCharacterId) {
            throw AppError::unauthorized('Puoi annullare solo i piani di cui sei leader.', [], 'combat_coordination_cancel_forbidden');
        }

        $this->execPrepared(
            'UPDATE combat_coordination_plans
             SET status = ?, updated_at = ?
             WHERE id = ? LIMIT 1',
            ['cancelled', $this->now(), $planId],
        );
        $this->logPlan($planId, (int) ($row->conflict_id ?? 0), $actorCharacterId, 'cancelled', 'Piano coordinato annullato.');

        return ['id' => $planId, 'status' => 'cancelled'];
    }

    private function logPlan(int $planId, int $conflictId, int $actorId, string $logType, string $notes): void
    {
        $this->execPrepared(
            'INSERT INTO combat_coordination_logs
                (plan_id, conflict_id, actor_id, log_type, notes, created_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$planId, $conflictId, $actorId > 0 ? $actorId : null, $logType, $notes, $this->now()],
        );
    }

    public function buildStateAddon(array $baseState, int $conflictId, int $viewerCharacterId, bool $isStaff): array
    {
        $this->ensureSchema();
        if ($conflictId <= 0) {
            return ['enabled' => false, 'message' => 'Nessun conflitto selezionato.'];
        }
        if ($this->baseCombatTierLevel() < 2) {
            return ['enabled' => false, 'message' => 'Combat Coordination richiede Narrative Combat su Tier 2.'];
        }

        $this->ensureConflictAccessible($conflictId, $viewerCharacterId, $isStaff);
        $plans = $this->listPlans($conflictId, true);
        $participants = [];
        foreach ((array) ($baseState['participant_states'] ?? []) as $row) {
            $participants[] = [
                'character_id' => (int) ($row['character_id'] ?? 0),
                'label' => trim((string) (($row['character_name'] ?? '') . ' ' . ($row['character_surname'] ?? ''))),
                'team_key' => (string) ($row['team_key'] ?? 'side_a'),
                'status' => (string) ($row['status'] ?? 'active'),
            ];
        }

        return [
            'enabled' => true,
            'message' => 'Piani coordinati e bonus di gruppo contestuali.',
            'viewer_character_id' => $viewerCharacterId,
            'plans' => $plans,
            'participant_options' => $participants,
        ];
    }

    public function applyScoreModifiers(array $payload, array $intent, array $actorState, ?array $targetState): array
    {
        $this->ensureSchema();
        if ($this->baseCombatTierLevel() < 2) {
            return $payload;
        }

        $conflictId = (int) ($intent['conflict_id'] ?? 0);
        $actorId = (int) ($intent['actor_id'] ?? 0);
        if ($conflictId <= 0 || $actorId <= 0) {
            return $payload;
        }

        $actionType = strtolower(trim((string) ($intent['action_type'] ?? '')));
        $targetId = (int) ($intent['primary_target_id'] ?? 0);
        $plans = $this->listPlans($conflictId, true);
        if ($plans === []) {
            return $payload;
        }

        $actorScore = (int) ($payload['actor_score'] ?? 0);
        $modifiers = is_array($payload['modifiers'] ?? null) ? $payload['modifiers'] : [];

        foreach ($plans as $plan) {
            $requiredAction = (string) ($plan['required_action_type'] ?? '');
            if ($requiredAction !== $actionType) {
                continue;
            }

            $supporterIds = (array) ($plan['supporter_ids'] ?? []);
            $isLeader = (int) ($plan['leader_id'] ?? 0) === $actorId;
            $isSupporter = in_array($actorId, $supporterIds, true);
            if (!$isLeader && !$isSupporter) {
                continue;
            }

            $bonus = max(1, (int) ($plan['bonus_scale'] ?? 1));
            if ($isLeader) {
                $bonus += max(0, min(2, (int) floor(count($supporterIds) / 2)));
            }
            if ($targetId > 0 && (int) ($plan['primary_target_id'] ?? 0) === $targetId) {
                $bonus += 1;
            }
            if ($isSupporter && !$isLeader) {
                $bonus = max(1, $bonus - 1);
            }

            $actorScore += $bonus;
            $modifiers[] = (string) ($plan['maneuver_label'] ?? 'Coordination') . ': bonus coordinazione +' . $bonus;
        }

        $payload['actor_score'] = $actorScore;
        $payload['modifiers'] = $modifiers;
        return $payload;
    }

    public function markPlansConsumedByResolution(int $actionIntentId, int $conflictId, array $intent, array $resolved): void
    {
        $this->ensureSchema();
        $actorId = (int) ($intent['actor_id'] ?? 0);
        $actionType = strtolower(trim((string) ($intent['action_type'] ?? '')));
        $targetId = (int) ($intent['primary_target_id'] ?? 0);
        if ($conflictId <= 0 || $actorId <= 0 || $actionType === '') {
            return;
        }

        $plans = $this->listPlans($conflictId, true);
        foreach ($plans as $plan) {
            if ((int) ($plan['leader_id'] ?? 0) !== $actorId) {
                continue;
            }
            if ((string) ($plan['required_action_type'] ?? '') !== $actionType) {
                continue;
            }
            $planTargetId = (int) ($plan['primary_target_id'] ?? 0);
            if ($planTargetId > 0 && $targetId > 0 && $planTargetId !== $targetId) {
                continue;
            }

            $this->execPrepared(
                'UPDATE combat_coordination_plans
                 SET status = ?, consumed_at = ?, updated_at = ?
                 WHERE id = ? LIMIT 1',
                ['consumed', $this->now(), $this->now(), (int) ($plan['id'] ?? 0)],
            );
            $this->logPlan(
                (int) ($plan['id'] ?? 0),
                $conflictId,
                $actorId,
                'consumed',
                'Piano consumato su risoluzione azione #' . $actionIntentId . '.',
            );
        }
    }
}
