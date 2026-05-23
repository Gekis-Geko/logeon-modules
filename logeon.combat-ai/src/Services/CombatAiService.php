<?php

declare(strict_types=1);

namespace Modules\Logeon\CombatAi\Services;

use App\Services\ConflictService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;
use Core\ModuleManager;
use Modules\Logeon\NarrativeCombat\Services\NarrativeCombatService;

class CombatAiService
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
        if ($this->hasTable('combat_ai_profiles') && $this->hasTable('combat_ai_logs')) {
            return;
        }

        throw AppError::validation(
            'Schema modulo Combat AI non disponibile. Attiva o reinstalla il modulo.',
            [],
            'combat_ai_schema_missing',
        );
    }

    private function baseCombatTierLevel(): int
    {
        $tier = (int) $this->moduleManager()->getSetting(
            self::DEPENDENCY_MODULE_ID,
            'combat_depth',
            2,
        );

        return $tier <= 1 ? 1 : 2;
    }

    private function ensureBaseTier2Enabled(): void
    {
        if ($this->baseCombatTierLevel() >= 2) {
            return;
        }

        throw AppError::validation(
            'Il modulo Combat AI richiede Narrative Combat impostato almeno su Tier 2.',
            [],
            'combat_ai_requires_tier2',
        );
    }

    private function normalizeText($value, int $max = 255): string
    {
        return mb_substr(trim((string) $value), 0, $max);
    }

    private function normalizeBehavior($value): string
    {
        $value = strtolower(trim((string) $value));
        $allowed = ['aggressive', 'defensive', 'supportive', 'opportunist', 'cautious'];
        return in_array($value, $allowed, true) ? $value : 'opportunist';
    }

    private function normalizeAutomationMode($value): string
    {
        $value = strtolower(trim((string) $value));
        $allowed = ['suggest_only', 'staff_declare'];
        return in_array($value, $allowed, true) ? $value : 'suggest_only';
    }

    private function normalizePriorityFocus($value): string
    {
        $value = strtolower(trim((string) $value));
        $allowed = ['balanced', 'pressure', 'survival', 'support'];
        return in_array($value, $allowed, true) ? $value : 'balanced';
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

        if ($allowed) {
            return $detail;
        }

        throw AppError::unauthorized('Operazione non autorizzata su Combat AI.', [], 'combat_ai_access_forbidden');
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

    private function listProfiles(int $conflictId = 0): array
    {
        $sql = 'SELECT p.*, c.name AS character_name, c.surname AS character_surname
                FROM combat_ai_profiles p
                LEFT JOIN characters c ON c.id = p.character_id
                WHERE 1 = 1';
        $params = [];
        if ($conflictId > 0) {
            $sql .= ' AND p.conflict_id = ?';
            $params[] = $conflictId;
        }
        $sql .= ' ORDER BY p.conflict_id DESC, p.character_id ASC';

        $rows = $this->fetchPrepared($sql, $params);
        $dataset = [];
        foreach ($rows as $row) {
            $dataset[] = [
                'id' => (int) ($row->id ?? 0),
                'conflict_id' => (int) ($row->conflict_id ?? 0),
                'character_id' => (int) ($row->character_id ?? 0),
                'character_name' => trim((string) (($row->character_name ?? '') . ' ' . ($row->character_surname ?? ''))),
                'behavior_key' => $this->normalizeBehavior($row->behavior_key ?? 'opportunist'),
                'automation_mode' => $this->normalizeAutomationMode($row->automation_mode ?? 'suggest_only'),
                'priority_focus' => $this->normalizePriorityFocus($row->priority_focus ?? 'balanced'),
                'notes' => (string) ($row->notes ?? ''),
                'is_active' => (int) ($row->is_active ?? 1),
            ];
        }

        return $dataset;
    }

    public function adminBootstrap(): array
    {
        $this->ensureSchema();

        return [
            'contexts' => $this->listCombatContexts(),
            'profiles' => $this->listProfiles(),
            'options' => [
                'behaviors' => [
                    ['value' => 'aggressive', 'label' => 'Aggressivo'],
                    ['value' => 'defensive', 'label' => 'Difensivo'],
                    ['value' => 'supportive', 'label' => 'Supportivo'],
                    ['value' => 'opportunist', 'label' => 'Opportunista'],
                    ['value' => 'cautious', 'label' => 'Cauto'],
                ],
                'automation_modes' => [
                    ['value' => 'suggest_only', 'label' => 'Solo suggerimenti'],
                    ['value' => 'staff_declare', 'label' => 'Dichiarazione staff-guidata'],
                ],
                'priority_focuses' => [
                    ['value' => 'balanced', 'label' => 'Bilanciato'],
                    ['value' => 'pressure', 'label' => 'Pressione'],
                    ['value' => 'survival', 'label' => 'Sopravvivenza'],
                    ['value' => 'support', 'label' => 'Supporto'],
                ],
            ],
            'base_combat_tier' => $this->baseCombatTierLevel(),
        ];
    }

    public function saveProfile($data, int $userId): int
    {
        $this->ensureSchema();
        $this->ensureBaseTier2Enabled();

        $id = (int) ($data->id ?? 0);
        $conflictId = (int) ($data->conflict_id ?? 0);
        $characterId = (int) ($data->character_id ?? 0);
        $behavior = $this->normalizeBehavior($data->behavior_key ?? 'opportunist');
        $automationMode = $this->normalizeAutomationMode($data->automation_mode ?? 'suggest_only');
        $priorityFocus = $this->normalizePriorityFocus($data->priority_focus ?? 'balanced');
        $notes = $this->normalizeText($data->notes ?? '', 1000);
        $isActive = (int) ($data->is_active ?? 1) === 1 ? 1 : 0;

        if ($conflictId <= 0 || $characterId <= 0) {
            throw AppError::validation('Conflitto o personaggio non validi.', [], 'combat_ai_profile_invalid');
        }

        $participant = $this->firstPrepared(
            'SELECT id
             FROM combat_participant_states
             WHERE conflict_id = ?
               AND character_id = ?
             LIMIT 1',
            [$conflictId, $characterId],
        );
        if (empty($participant)) {
            throw AppError::validation('Il personaggio non partecipa a questo combattimento.', [], 'combat_ai_participant_missing');
        }

        if ($id > 0) {
            $this->execPrepared(
                'UPDATE combat_ai_profiles
                 SET conflict_id = ?, character_id = ?, behavior_key = ?, automation_mode = ?, priority_focus = ?, notes = ?, is_active = ?, updated_at = ?
                 WHERE id = ? LIMIT 1',
                [$conflictId, $characterId, $behavior, $automationMode, $priorityFocus, $notes !== '' ? $notes : null, $isActive, $this->now(), $id],
            );

            return $id;
        }

        $this->execPrepared(
            'INSERT INTO combat_ai_profiles
                (conflict_id, character_id, behavior_key, automation_mode, priority_focus, notes, is_active, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                behavior_key = VALUES(behavior_key),
                automation_mode = VALUES(automation_mode),
                priority_focus = VALUES(priority_focus),
                notes = VALUES(notes),
                is_active = VALUES(is_active),
                updated_at = VALUES(updated_at)',
            [
                $conflictId,
                $characterId,
                $behavior,
                $automationMode,
                $priorityFocus,
                $notes !== '' ? $notes : null,
                $isActive,
                $userId > 0 ? $userId : null,
                $this->now(),
                $this->now(),
            ],
        );

        $row = $this->firstPrepared(
            'SELECT id
             FROM combat_ai_profiles
             WHERE conflict_id = ?
               AND character_id = ?
             LIMIT 1',
            [$conflictId, $characterId],
        );

        return (int) ($row->id ?? 0);
    }

    public function deleteProfile(int $id): void
    {
        $this->ensureSchema();
        if ($id <= 0) {
            throw AppError::validation('Profilo AI non valido.', [], 'combat_ai_profile_invalid');
        }

        $this->execPrepared('DELETE FROM combat_ai_profiles WHERE id = ? LIMIT 1', [$id]);
    }

    /**
     * @param array<int,array<string,mixed>> $participantStates
     * @return array<int,array<string,mixed>>
     */
    private function enemyCandidates(array $participantStates, string $teamKey): array
    {
        $out = [];
        foreach ($participantStates as $row) {
            if ((string) ($row['status'] ?? 'active') !== 'active') {
                continue;
            }
            if ((string) ($row['team_key'] ?? '') === $teamKey) {
                continue;
            }
            $out[] = $row;
        }

        usort($out, static function (array $a, array $b): int {
            $scoreA = (int) ($a['stamina_current'] ?? 0) - ((int) ($a['fatigue_level'] ?? 0) * 8);
            $scoreB = (int) ($b['stamina_current'] ?? 0) - ((int) ($b['fatigue_level'] ?? 0) * 8);
            return $scoreA <=> $scoreB;
        });

        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $participantStates
     * @return array<int,array<string,mixed>>
     */
    private function allyCandidates(array $participantStates, int $characterId, string $teamKey): array
    {
        $out = [];
        foreach ($participantStates as $row) {
            if ((int) ($row['character_id'] ?? 0) === $characterId) {
                continue;
            }
            if ((string) ($row['status'] ?? 'active') !== 'active') {
                continue;
            }
            if ((string) ($row['team_key'] ?? '') !== $teamKey) {
                continue;
            }
            $out[] = $row;
        }

        usort($out, static function (array $a, array $b): int {
            $scoreA = ((int) ($b['threat_exposure'] ?? 0) * 10) + (100 - (int) ($b['stamina_current'] ?? 0));
            $scoreB = ((int) ($a['threat_exposure'] ?? 0) * 10) + (100 - (int) ($a['stamina_current'] ?? 0));
            return $scoreA <=> $scoreB;
        });

        return $out;
    }

    /**
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $baseState
     * @return array<string,mixed>
     */
    private function buildSuggestion(array $profile, array $baseState): array
    {
        $participantStates = (array) ($baseState['participant_states'] ?? []);
        $characterId = (int) ($profile['character_id'] ?? 0);
        $behavior = (string) ($profile['behavior_key'] ?? 'opportunist');
        $participant = null;

        foreach ($participantStates as $row) {
            if ((int) ($row['character_id'] ?? 0) === $characterId) {
                $participant = $row;
                break;
            }
        }

        if (!is_array($participant)) {
            return [
                'character_id' => $characterId,
                'available' => false,
                'reason' => 'Partecipante non disponibile nello stato attuale.',
            ];
        }

        $teamKey = (string) ($participant['team_key'] ?? 'side_a');
        $stamina = (int) ($participant['stamina_current'] ?? 0);
        $fatigue = (int) ($participant['fatigue_level'] ?? 0);
        $threat = (int) ($participant['threat_exposure'] ?? 0);
        $allies = $this->allyCandidates($participantStates, $characterId, $teamKey);
        $enemies = $this->enemyCandidates($participantStates, $teamKey);

        $actionType = 'defend';
        $targetId = 0;
        $reason = 'Mantiene una postura prudente.';

        if ($stamina <= 15 || $fatigue >= 7) {
            $actionType = $threat >= 6 ? 'disengage' : 'recover';
            $reason = 'Stamina critica o fatica alta.';
        } elseif ($behavior === 'supportive' && $allies !== []) {
            $actionType = 'protect';
            $targetId = (int) ($allies[0]['character_id'] ?? 0);
            $reason = 'Protegge l\'alleato piu esposto.';
        } elseif ($behavior === 'defensive') {
            if ($threat >= 5 && $allies !== []) {
                $actionType = 'protect';
                $targetId = (int) ($allies[0]['character_id'] ?? 0);
                $reason = 'Consolidamento difensivo della linea.';
            } else {
                $actionType = 'defend';
                $reason = 'Tiene la posizione e assorbe pressione.';
            }
        } elseif ($behavior === 'cautious') {
            $actionType = $threat >= 5 ? 'disengage' : 'reposition';
            $reason = 'Cerca spazio e riduce l\'esposizione.';
        } elseif ($behavior === 'aggressive' || $behavior === 'opportunist') {
            if ($enemies !== []) {
                $actionType = 'strike';
                $targetId = (int) ($enemies[0]['character_id'] ?? 0);
                $reason = $behavior === 'aggressive'
                    ? 'Pressa il bersaglio piu vulnerabile.'
                    : 'Sfrutta l\'apertura piu conveniente.';
            } else {
                $actionType = 'reposition';
                $reason = 'Non ha bersagli attivi diretti.';
            }
        }

        if ($actionType === 'protect' && $targetId <= 0) {
            $actionType = 'defend';
            $reason = 'Nessun alleato valido da proteggere.';
        }
        if ($actionType === 'strike' && $targetId <= 0) {
            $actionType = 'reposition';
            $reason = 'Nessun bersaglio valido disponibile.';
        }

        return [
            'character_id' => $characterId,
            'character_name' => (string) ($profile['character_name'] ?? ''),
            'behavior_key' => $behavior,
            'automation_mode' => (string) ($profile['automation_mode'] ?? 'suggest_only'),
            'priority_focus' => (string) ($profile['priority_focus'] ?? 'balanced'),
            'available' => true,
            'action_type' => $actionType,
            'primary_target_id' => $targetId,
            'summary' => $reason,
        ];
    }

    public function buildStateAddon(array $baseState, int $conflictId, int $viewerCharacterId, bool $isStaff): array
    {
        $this->ensureSchema();
        $modeMessage = 'Suggerimenti tattici staff-guidati sopra il combattimento narrativo.';
        if ($conflictId <= 0) {
            return ['enabled' => false, 'message' => 'Nessun conflitto selezionato.'];
        }
        if ($this->baseCombatTierLevel() < 2) {
            return ['enabled' => false, 'message' => 'Combat AI richiede Narrative Combat su Tier 2.'];
        }
        if (!$isStaff) {
            return ['enabled' => false, 'message' => 'Combat AI e visibile solo allo staff.'];
        }

        $this->ensureConflictAccessible($conflictId, $viewerCharacterId, true);
        $profiles = array_values(array_filter($this->listProfiles($conflictId), static function (array $row): bool {
            return (int) ($row['is_active'] ?? 0) === 1;
        }));

        $suggestions = [];
        foreach ($profiles as $profile) {
            $suggestions[] = $this->buildSuggestion($profile, $baseState);
        }

        return [
            'enabled' => true,
            'staff_only' => true,
            'message' => $modeMessage,
            'profiles' => $profiles,
            'suggestions' => $suggestions,
        ];
    }

    public function declareSuggestedAction($data, int $actorCharacterId, bool $isStaff): array
    {
        $this->ensureSchema();
        $this->ensureBaseTier2Enabled();
        if (!$isStaff) {
            throw AppError::unauthorized('Solo lo staff puo dichiarare azioni AI.', [], 'combat_ai_declare_forbidden');
        }

        $conflictId = (int) ($data->conflict_id ?? 0);
        $characterId = (int) ($data->character_id ?? 0);
        if ($conflictId <= 0 || $characterId <= 0) {
            throw AppError::validation('Conflitto o personaggio non validi.', [], 'combat_ai_declare_invalid');
        }

        $baseState = $this->combatService()->getState($conflictId, $actorCharacterId, true);
        $profiles = $this->listProfiles($conflictId);
        $profile = null;
        foreach ($profiles as $row) {
            if ((int) ($row['character_id'] ?? 0) === $characterId && (int) ($row['is_active'] ?? 0) === 1) {
                $profile = $row;
                break;
            }
        }
        if (!is_array($profile)) {
            throw AppError::validation('Nessun profilo AI attivo per questo partecipante.', [], 'combat_ai_profile_missing');
        }

        $suggestion = $this->buildSuggestion($profile, $baseState);
        if (empty($suggestion['available'])) {
            throw AppError::validation((string) ($suggestion['reason'] ?? 'Suggerimento AI non disponibile.'), [], 'combat_ai_suggestion_missing');
        }

        $payload = (object) [
            'conflict_id' => $conflictId,
            'action_type' => (string) ($suggestion['action_type'] ?? 'defend'),
            'primary_target_id' => (int) ($suggestion['primary_target_id'] ?? 0),
            'secondary_targets' => [],
            'narrative_reference' => 'AI: ' . (string) ($suggestion['summary'] ?? 'azione suggerita'),
        ];
        $state = $this->combatService()->declareAction($payload, $characterId, true);

        $this->execPrepared(
            'INSERT INTO combat_ai_logs
                (conflict_id, character_id, action_type, target_id, suggestion_summary, source_mode, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $conflictId,
                $characterId,
                (string) ($suggestion['action_type'] ?? 'defend'),
                (int) ($suggestion['primary_target_id'] ?? 0) > 0 ? (int) $suggestion['primary_target_id'] : null,
                (string) ($suggestion['summary'] ?? 'Suggerimento AI'),
                (string) ($profile['automation_mode'] ?? 'suggest_only'),
                $this->now(),
            ],
        );

        return [
            'declared' => true,
            'character_id' => $characterId,
            'suggestion' => $suggestion,
            'state' => $state,
        ];
    }
}
