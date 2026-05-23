<?php

declare(strict_types=1);

namespace Modules\Logeon\CombatAdminTools\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\ModuleManager;

class CombatAdminToolsService
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var ModuleManager|null */
    private $moduleManager = null;

    public function __construct(
        DbAdapterInterface $db = null,
        ModuleManager $moduleManager = null,
    ) {
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

    private function baseCombatTierLevel(): int
    {
        $tier = (int) $this->moduleManager()->getSetting('logeon.narrative-combat', 'combat_depth', 2);
        return $tier <= 1 ? 1 : 2;
    }

    private function contextsSummary(): array
    {
        if (!$this->hasTable('combat_contexts') || !$this->hasTable('combat_participant_states')) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT cc.conflict_id, cc.status, cc.escalation_level, cc.progression_index, cc.tier_level,
                    COUNT(cps.id) AS participants,
                    SUM(CASE WHEN cps.status = "active" THEN 1 ELSE 0 END) AS active_participants,
                    SUM(CASE WHEN cps.fatigue_level >= 6 OR cps.stamina_current <= 20 THEN 1 ELSE 0 END) AS risk_participants
             FROM combat_contexts cc
             LEFT JOIN combat_participant_states cps ON cps.conflict_id = cc.conflict_id
             GROUP BY cc.conflict_id, cc.status, cc.escalation_level, cc.progression_index, cc.tier_level
             ORDER BY cc.updated_at DESC, cc.conflict_id DESC',
        );

        $dataset = [];
        foreach ($rows as $row) {
            $dataset[] = [
                'conflict_id' => (int) ($row->conflict_id ?? 0),
                'status' => (string) ($row->status ?? 'active'),
                'tier_level' => (int) ($row->tier_level ?? 1),
                'escalation_level' => (int) ($row->escalation_level ?? 1),
                'progression_index' => (int) ($row->progression_index ?? 0),
                'participants' => (int) ($row->participants ?? 0),
                'active_participants' => (int) ($row->active_participants ?? 0),
                'risk_participants' => (int) ($row->risk_participants ?? 0),
            ];
        }

        return $dataset;
    }

    private function pendingActionsSummary(): array
    {
        if (!$this->hasTable('combat_action_intents')) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT conflict_id, COUNT(*) AS pending_count
             FROM combat_action_intents
             WHERE resolution_status = ?
             GROUP BY conflict_id
             ORDER BY conflict_id DESC',
            ['pending'],
        );

        $dataset = [];
        foreach ($rows as $row) {
            $dataset[] = [
                'conflict_id' => (int) ($row->conflict_id ?? 0),
                'pending_count' => (int) ($row->pending_count ?? 0),
            ];
        }
        return $dataset;
    }

    public function adminBootstrap(): array
    {
        return [
            'base_combat_tier' => $this->baseCombatTierLevel(),
            'contexts' => $this->contextsSummary(),
            'pending' => $this->pendingActionsSummary(),
        ];
    }

    public function buildStateAddon(array $baseState, int $conflictId, int $viewerCharacterId, bool $isStaff): array
    {
        if ($conflictId <= 0) {
            return ['enabled' => false, 'message' => 'Nessun conflitto selezionato.'];
        }
        if ($this->baseCombatTierLevel() < 2) {
            return ['enabled' => false, 'message' => 'Combat Admin Tools richiede Narrative Combat su Tier 2.'];
        }
        if (!$isStaff) {
            return ['enabled' => false, 'message' => 'Diagnostica disponibile solo allo staff.'];
        }

        $pendingActions = (array) ($baseState['pending_actions'] ?? []);
        $participantStates = (array) ($baseState['participant_states'] ?? []);
        $effects = (array) ($baseState['active_effects'] ?? []);

        $fatigueHotspots = [];
        foreach ($participantStates as $row) {
            $fatigue = (int) ($row['fatigue_level'] ?? 0);
            $stamina = (int) ($row['stamina_current'] ?? 0);
            if ($fatigue < 6 && $stamina > 20) {
                continue;
            }
            $fatigueHotspots[] = [
                'character_id' => (int) ($row['character_id'] ?? 0),
                'label' => trim((string) (($row['character_name'] ?? '') . ' ' . ($row['character_surname'] ?? ''))),
                'fatigue_level' => $fatigue,
                'stamina_current' => $stamina,
            ];
        }

        $effectPressure = [];
        foreach ($effects as $row) {
            $targetId = (int) ($row['target_actor_id'] ?? 0);
            if ($targetId <= 0) {
                continue;
            }
            if (!isset($effectPressure[$targetId])) {
                $effectPressure[$targetId] = 0;
            }
            $effectPressure[$targetId] += 1;
        }

        $pressureRows = [];
        foreach ($effectPressure as $targetId => $count) {
            $pressureRows[] = [
                'character_id' => (int) $targetId,
                'effect_count' => (int) $count,
            ];
        }

        return [
            'enabled' => true,
            'message' => 'Diagnostica staff e punti di attenzione contestuali.',
            'pending_count' => count($pendingActions),
            'fatigue_hotspots' => $fatigueHotspots,
            'effect_pressure' => $pressureRows,
            'escalation_level' => (int) (($baseState['context']['escalation_level'] ?? 1)),
        ];
    }
}
