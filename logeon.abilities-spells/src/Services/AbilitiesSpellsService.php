<?php

declare(strict_types=1);

namespace Modules\Logeon\AbilitiesSpells\Services;

use App\Services\NotificationService;
use App\Services\NarrativeStateApplicationService;
use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;
use Core\Hooks;

class AbilitiesSpellsService
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var NarrativeStateApplicationService|null */
    private $narrativeStateApplicationService = null;
    /** @var NotificationService|null */
    private $notificationService = null;
    /** @var AbilityResolver|null */
    private $resolver = null;

    public function __construct(
        DbAdapterInterface $db = null,
        NarrativeStateApplicationService $narrativeStateApplicationService = null,
        NotificationService $notificationService = null,
    ) {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->narrativeStateApplicationService = $narrativeStateApplicationService;
        $this->notificationService = $notificationService;
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

    private function beginTransaction(): void
    {
        $this->db->query('START TRANSACTION');
    }

    private function commit(): void
    {
        $this->db->query('COMMIT');
    }

    private function rollback(): void
    {
        $this->db->query('ROLLBACK');
    }

    private function failValidation(string $message, string $errorCode = 'validation_error'): void
    {
        throw AppError::validation($message, [], $errorCode);
    }

    private function narrativeStateApplicationService(): NarrativeStateApplicationService
    {
        if ($this->narrativeStateApplicationService instanceof NarrativeStateApplicationService) {
            return $this->narrativeStateApplicationService;
        }

        $this->narrativeStateApplicationService = new NarrativeStateApplicationService($this->db);
        return $this->narrativeStateApplicationService;
    }

    private function notificationService(): NotificationService
    {
        if ($this->notificationService instanceof NotificationService) {
            return $this->notificationService;
        }

        $this->notificationService = new NotificationService($this->db);
        return $this->notificationService;
    }

    private function normalizeText($value): string
    {
        return trim((string) $value);
    }

    private function normalizeSlug($value, string $fallback = ''): string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '' && $fallback !== '') {
            $value = strtolower(trim($fallback));
        }
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value);
        $value = trim((string) $value, '-');
        return $value;
    }

    private function normalizeEnum($value, array $allowed, string $fallback): string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '' || !in_array($value, $allowed, true)) {
            return $fallback;
        }
        return $value;
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
        return (float) str_replace(',', '.', (string) $value);
    }

    private function normalizeStatus($value, string $fallback = 'available'): string
    {
        $value = strtolower(trim((string) $value));
        $allowed = ['available', 'learning', 'pending_approval', 'learned', 'suspended', 'disabled'];
        if ($value === '' || !in_array($value, $allowed, true)) {
            return $fallback;
        }
        return $value;
    }

    private function normalizeApprovalStatus($value, string $fallback = 'approved'): string
    {
        $value = strtolower(trim((string) $value));
        $allowed = ['pending', 'approved', 'rejected'];
        if ($value === '' || !in_array($value, $allowed, true)) {
            return $fallback;
        }
        return $value;
    }

    private function normalizeApprovalDecision($value, string $fallback = 'approve'): string
    {
        $value = strtolower(trim((string) $value));
        $allowed = ['approve', 'reject'];
        if ($value === '' || !in_array($value, $allowed, true)) {
            return $fallback;
        }
        return $value;
    }

    private function normalizeJsonValue($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed === '' ? null : $trimmed;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : null;
    }

    private function resolver(): AbilityResolver
    {
        if ($this->resolver instanceof AbilityResolver) {
            return $this->resolver;
        }

        $this->resolver = new AbilityResolver($this->db);
        return $this->resolver;
    }

    private function ensureNarrativeStateExists(int $stateId): void
    {
        if ($stateId <= 0) {
            return;
        }

        $row = $this->firstPrepared(
            'SELECT id
             FROM narrative_states
             WHERE id = ?
             LIMIT 1',
            [$stateId],
        );

        if (empty($row) || (int) ($row->id ?? 0) <= 0) {
            $this->failValidation('Stato narrativo non valido', 'state_not_found');
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

    private function readSetting(string $key, $default = null)
    {
        $row = $this->firstPrepared(
            'SELECT `value`
             FROM sys_settings
             WHERE `key` = ?
             LIMIT 1',
            [$key],
        );

        if (!is_object($row) || !property_exists($row, 'value')) {
            return $default;
        }

        return $row->value;
    }

    private function settingEnabled(string $key, bool $default = false): bool
    {
        $value = $this->readSetting($key, $default ? '1' : '0');
        if ($value === null) {
            return $default;
        }

        $raw = strtolower(trim((string) $value));
        return in_array($raw, ['1', 'true', 'yes', 'si', 'on'], true);
    }

    private function settingString(string $key, string $default = ''): string
    {
        $value = $this->readSetting($key, $default);
        return trim((string) $value);
    }

    private function listActiveStaffUserIds(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $rows = $this->fetchPrepared(
            'SELECT id
             FROM users
             WHERE is_active = 1
               AND (is_administrator = 1 OR is_moderator = 1)
             LIMIT ' . (int) $limit
        );

        $ids = [];
        foreach ($rows as $row) {
            $userId = (int) ($row->id ?? 0);
            if ($userId > 0) {
                $ids[] = $userId;
            }
        }

        return $ids;
    }

    private function getCharacterOwnerContext(int $characterId): array
    {
        if ($characterId <= 0) {
            return ['user_id' => 0, 'label' => ''];
        }

        $row = $this->firstPrepared(
            'SELECT user_id, name, surname
             FROM characters
             WHERE id = ?
             LIMIT 1',
            [$characterId],
        );

        if (!is_object($row)) {
            return ['user_id' => 0, 'label' => ''];
        }

        $label = trim(((string) ($row->name ?? '')) . ' ' . ((string) ($row->surname ?? '')));
        return [
            'user_id' => (int) ($row->user_id ?? 0),
            'label' => $label,
        ];
    }

    private function notifyStaffAboutPendingApproval(
        int $assignmentId,
        int $characterId,
        int $abilityId,
        string $abilityName,
        bool $isUpgrade,
        ?int $actorUserId = null
    ): void {
        if ($assignmentId <= 0 || $characterId <= 0 || $abilityId <= 0) {
            return;
        }

        $staffUserIds = $this->listActiveStaffUserIds();
        if (empty($staffUserIds)) {
            return;
        }

        $owner = $this->getCharacterOwnerContext($characterId);
        $characterLabel = trim((string) ($owner['label'] ?? ''));
        $abilityLabel = trim($abilityName) !== '' ? trim($abilityName) : ('Abilita #' . $abilityId);
        $title = $isUpgrade
            ? ('Upgrade abilita in attesa: ' . $abilityLabel)
            : ('Apprendimento abilita in attesa: ' . $abilityLabel);
        $message = $characterLabel !== ''
            ? ($characterLabel . ' ha inviato una richiesta per ' . strtolower($abilityLabel) . '.')
            : ('E stata inviata una richiesta per ' . strtolower($abilityLabel) . '.');
        $meta = json_encode([
            'assignment_id' => $assignmentId,
            'character_id' => $characterId,
            'ability_id' => $abilityId,
            'is_upgrade' => $isUpgrade ? 1 : 0,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($staffUserIds as $staffUserId) {
            try {
                $this->notificationService()->create(
                    (int) $staffUserId,
                    null,
                    NotificationService::KIND_ACTION_REQUIRED,
                    'ability_progression_approval',
                    $title,
                    [
                        'message' => $message,
                        'priority' => 'normal',
                        'actor_user_id' => ($actorUserId !== null && $actorUserId > 0) ? $actorUserId : null,
                        'actor_character_id' => $characterId,
                        'source_type' => 'ability_progression_approval',
                        'source_id' => $assignmentId,
                        'source_meta_json' => $meta !== false ? $meta : null,
                    ],
                );
            } catch (\Throwable $e) {
                // Le notifiche staff non devono bloccare il flusso gioco.
            }
        }
    }

    private function resolveStaffApprovalNotifications(int $assignmentId, string $decision): void
    {
        if ($assignmentId <= 0) {
            return;
        }

        foreach ($this->listActiveStaffUserIds() as $staffUserId) {
            try {
                $this->notificationService()->resolveBySource(
                    (int) $staffUserId,
                    'ability_progression_approval',
                    $assignmentId,
                    $decision,
                );
            } catch (\Throwable $e) {
                // Best effort.
            }
        }
    }

    private function notifyPlayerAboutApprovalDecision(
        int $assignmentId,
        int $characterId,
        int $abilityId,
        string $abilityName,
        string $decision,
        ?int $actorUserId = null
    ): void {
        if ($assignmentId <= 0 || $characterId <= 0 || $abilityId <= 0) {
            return;
        }

        $owner = $this->getCharacterOwnerContext($characterId);
        $ownerUserId = (int) ($owner['user_id'] ?? 0);
        if ($ownerUserId <= 0) {
            return;
        }

        $abilityLabel = trim($abilityName) !== '' ? trim($abilityName) : ('Abilita #' . $abilityId);
        $approved = $decision === 'approve';
        $title = $approved
            ? ('Richiesta abilita approvata: ' . $abilityLabel)
            : ('Richiesta abilita respinta: ' . $abilityLabel);
        $message = $approved
            ? ('Lo staff ha approvato la tua richiesta per ' . $abilityLabel . '.')
            : ('Lo staff ha respinto la tua richiesta per ' . $abilityLabel . '. I punti investiti restano salvati.');

        try {
            $this->notificationService()->create(
                $ownerUserId,
                $characterId,
                NotificationService::KIND_DECISION_RESULT,
                'ability_progression_approval',
                $title,
                [
                    'message' => $message,
                    'priority' => 'normal',
                    'actor_user_id' => ($actorUserId !== null && $actorUserId > 0) ? $actorUserId : null,
                    'source_type' => 'ability_progression_approval',
                    'source_id' => $assignmentId,
                    'action_url' => '/game/abilities-spells',
                ],
            );
        } catch (\Throwable $e) {
            // Best effort.
        }
    }

    private function emitCharacterAbilityChanged(
        int $characterId,
        int $abilityId,
        string $oldStatus,
        string $newStatus,
        int $oldLevel,
        int $newLevel,
        string $reason,
        ?int $changedByUserId = null,
        array $metadata = []
    ): void {
        if (!class_exists('\\Core\\Hooks')) {
            return;
        }

        try {
            Hooks::fire('character.abilities.changed', [
                'character_id' => $characterId,
                'ability_id' => $abilityId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'old_level' => $oldLevel,
                'new_level' => $newLevel,
                'changed_by_user_id' => $changedByUserId,
                'reason' => $reason,
                'occurred_at' => date(DATE_ATOM),
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $e) {
            error_log('[character.abilities.changed] listener error: ' . $e->getMessage());
        }
    }

    private function ensureAbilityExists(int $abilityId): void
    {
        if ($abilityId <= 0) {
            $this->failValidation('Abilita non valida', 'ability_id_invalid');
        }

        if (empty($this->findAbilityById($abilityId))) {
            $this->failValidation('Abilita non trovata', 'ability_not_found');
        }
    }

    private function findAbilityById(int $abilityId)
    {
        if ($abilityId <= 0) {
            return null;
        }

        return $this->firstPrepared(
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
                    ns.name AS narrative_state_name
             FROM lf_abilities_spells_abilities a
             LEFT JOIN narrative_states ns ON ns.id = a.narrative_state_id
             WHERE a.id = ?
             LIMIT 1',
            [$abilityId],
        );
    }

    public function adminListNarrativeStates(): array
    {
        $rows = $this->fetchPrepared(
            'SELECT id, code, name
             FROM narrative_states
             WHERE is_active = 1
             ORDER BY name ASC, id ASC',
        );

        return $rows ?: [];
    }

    public function adminListAbilities(): array
    {
        $rows = $this->fetchPrepared(
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
                    a.date_created,
                    a.date_updated,
                    ns.name AS narrative_state_name,
                    pc.name AS point_category_name,
                    (SELECT COUNT(*) FROM lf_abilities_spells_grants g WHERE g.ability_id = a.id AND g.is_active = 1) AS grants_count,
                    (SELECT COUNT(*) FROM lf_abilities_spells_requirements r WHERE r.ability_id = a.id AND r.is_active = 1) AS requirements_count,
                    (SELECT COUNT(*) FROM lf_abilities_spells_effects e WHERE e.ability_id = a.id AND e.is_active = 1) AS effects_count
             FROM lf_abilities_spells_abilities a
             LEFT JOIN narrative_states ns ON ns.id = a.narrative_state_id
             LEFT JOIN lf_abilities_spells_point_categories pc ON pc.id = a.point_category_id
             ORDER BY a.sort_order ASC, a.name ASC, a.id ASC',
        );

        return $rows ?: [];
    }

    public function adminCreateAbility(object $data): int
    {
        $name = $this->normalizeText($data->name ?? '');
        $slug = $this->normalizeSlug($data->slug ?? '', $name);
        $description = $this->normalizeText($data->description ?? '');
        $type = $this->normalizeEnum($data->type ?? 'ability', ['ability', 'spell', 'technique', 'ritual'], 'ability');
        $pointCategoryId = max(0, $this->normalizeInt($data->point_category_id ?? 0, 0));
        $targetType = $this->normalizeEnum($data->target_type ?? 'self', ['self', 'scene'], 'self');
        $effectMode = $this->normalizeEnum($data->effect_mode ?? 'none', ['none', 'apply_state', 'remove_state'], 'none');
        $narrativeStateId = $this->normalizeInt($data->narrative_state_id ?? 0, 0);
        $cooldownSeconds = max(0, $this->normalizeInt($data->cooldown_seconds ?? 0, 0));
        $sortOrder = $this->normalizeInt($data->sort_order ?? 100, 100);
        $isActive = $this->normalizeBool($data->is_active ?? 1, 1);
        $isPublic = $this->normalizeBool($data->is_public ?? 0, 0);
        $isHiddenWhenLocked = $this->normalizeBool($data->is_hidden_when_locked ?? 0, 0);
        $requiresLearning = $this->normalizeBool($data->requires_learning ?? 0, 0);
        $requiresStaffApproval = $this->normalizeBool($data->requires_staff_approval ?? 0, 0);
        $maxLevel = max(1, $this->normalizeInt($data->max_level ?? 1, 1));
        $metadataJson = $this->normalizeJsonValue($data->metadata_json ?? null);

        if ($name === '') {
            $this->failValidation('Nome abilita obbligatorio', 'ability_name_required');
        }
        if ($slug === '') {
            $this->failValidation('Slug abilita non valido', 'ability_slug_invalid');
        }
        if ($effectMode !== 'none' && $narrativeStateId <= 0) {
            $this->failValidation('Seleziona uno stato narrativo per questo effetto', 'ability_state_required');
        }

        $this->ensureNarrativeStateExists($narrativeStateId);

        $existing = $this->firstPrepared(
            'SELECT id
             FROM lf_abilities_spells_abilities
             WHERE slug = ?
             LIMIT 1',
            [$slug],
        );
        if (!empty($existing)) {
            $this->failValidation('Slug gia in uso', 'ability_slug_duplicate');
        }

        $this->execPrepared(
            'INSERT INTO lf_abilities_spells_abilities
                (name, slug, description, type, point_category_id, target_type, effect_mode, narrative_state_id, cooldown_seconds, sort_order, is_active, is_public, is_hidden_when_locked, requires_learning, requires_staff_approval, max_level, metadata_json, date_created, date_updated)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $name,
                $slug,
                $description,
                $type,
                $pointCategoryId > 0 ? $pointCategoryId : null,
                $targetType,
                $effectMode,
                $narrativeStateId > 0 ? $narrativeStateId : null,
                $cooldownSeconds,
                $sortOrder,
                $isActive,
                $isPublic,
                $isHiddenWhenLocked,
                $requiresLearning,
                $requiresStaffApproval,
                $maxLevel,
                $metadataJson,
            ],
        );

        return (int) $this->db->lastInsertId();
    }

    public function adminUpdateAbility(object $data): void
    {
        $id = $this->normalizeInt($data->id ?? 0, 0);
        if ($id <= 0) {
            $this->failValidation('ID abilita non valido', 'ability_id_invalid');
        }

        $current = $this->findAbilityById($id);
        if (empty($current)) {
            $this->failValidation('Abilita non trovata', 'ability_not_found');
        }

        $name = $this->normalizeText($data->name ?? '');
        $slug = $this->normalizeSlug($data->slug ?? '', $name);
        $description = $this->normalizeText($data->description ?? '');
        $type = $this->normalizeEnum($data->type ?? 'ability', ['ability', 'spell', 'technique', 'ritual'], 'ability');
        $pointCategoryId = max(0, $this->normalizeInt($data->point_category_id ?? 0, 0));
        $targetType = $this->normalizeEnum($data->target_type ?? 'self', ['self', 'scene'], 'self');
        $effectMode = $this->normalizeEnum($data->effect_mode ?? 'none', ['none', 'apply_state', 'remove_state'], 'none');
        $narrativeStateId = $this->normalizeInt($data->narrative_state_id ?? 0, 0);
        $cooldownSeconds = max(0, $this->normalizeInt($data->cooldown_seconds ?? 0, 0));
        $sortOrder = $this->normalizeInt($data->sort_order ?? 100, 100);
        $isActive = $this->normalizeBool($data->is_active ?? 1, 1);
        $isPublic = $this->normalizeBool($data->is_public ?? 0, 0);
        $isHiddenWhenLocked = $this->normalizeBool($data->is_hidden_when_locked ?? 0, 0);
        $requiresLearning = $this->normalizeBool($data->requires_learning ?? 0, 0);
        $requiresStaffApproval = $this->normalizeBool($data->requires_staff_approval ?? 0, 0);
        $maxLevel = max(1, $this->normalizeInt($data->max_level ?? 1, 1));
        $metadataJson = $this->normalizeJsonValue($data->metadata_json ?? null);

        if ($name === '') {
            $this->failValidation('Nome abilita obbligatorio', 'ability_name_required');
        }
        if ($slug === '') {
            $this->failValidation('Slug abilita non valido', 'ability_slug_invalid');
        }
        if ($effectMode !== 'none' && $narrativeStateId <= 0) {
            $this->failValidation('Seleziona uno stato narrativo per questo effetto', 'ability_state_required');
        }

        $this->ensureNarrativeStateExists($narrativeStateId);

        $duplicate = $this->firstPrepared(
            'SELECT id
             FROM lf_abilities_spells_abilities
             WHERE slug = ?
               AND id <> ?
             LIMIT 1',
            [$slug, $id],
        );
        if (!empty($duplicate)) {
            $this->failValidation('Slug gia in uso', 'ability_slug_duplicate');
        }

        $this->execPrepared(
            'UPDATE lf_abilities_spells_abilities
             SET name = ?,
                 slug = ?,
                 description = ?,
                 type = ?,
                 point_category_id = ?,
                 target_type = ?,
                 effect_mode = ?,
                 narrative_state_id = ?,
                 cooldown_seconds = ?,
                 sort_order = ?,
                 is_active = ?,
                 is_public = ?,
                 is_hidden_when_locked = ?,
                 requires_learning = ?,
                 requires_staff_approval = ?,
                 max_level = ?,
                 metadata_json = ?,
                 date_updated = NOW()
             WHERE id = ?',
            [
                $name,
                $slug,
                $description,
                $type,
                $pointCategoryId > 0 ? $pointCategoryId : null,
                $targetType,
                $effectMode,
                $narrativeStateId > 0 ? $narrativeStateId : null,
                $cooldownSeconds,
                $sortOrder,
                $isActive,
                $isPublic,
                $isHiddenWhenLocked,
                $requiresLearning,
                $requiresStaffApproval,
                $maxLevel,
                $metadataJson,
                $id,
            ],
        );
    }

    public function adminDeleteAbility(int $abilityId): void
    {
        if ($abilityId <= 0) {
            $this->failValidation('ID abilita non valido', 'ability_id_invalid');
        }

        $this->execPrepared(
            'DELETE FROM lf_abilities_spells_character_abilities
             WHERE ability_id = ?',
            [$abilityId],
        );

        $this->execPrepared(
            'DELETE FROM lf_abilities_spells_abilities
             WHERE id = ?',
            [$abilityId],
        );
    }

    public function adminListAssignments(int $characterId): array
    {
        $this->ensureCharacterExists($characterId);

        $rows = $this->fetchPrepared(
            'SELECT ca.id,
                    ca.character_id,
                    ca.ability_id,
                    ca.status,
                    ca.level,
                    ca.approval_status,
                    ca.sort_order,
                    ca.is_active,
                    a.name AS ability_name,
                    a.slug AS ability_slug,
                    a.target_type,
                    a.effect_mode,
                    c.name AS character_name,
                    c.surname AS character_surname
             FROM lf_abilities_spells_character_abilities ca
             INNER JOIN lf_abilities_spells_abilities a ON a.id = ca.ability_id
             INNER JOIN characters c ON c.id = ca.character_id
             WHERE ca.character_id = ?
             ORDER BY ca.sort_order ASC, a.name ASC, ca.id ASC',
            [$characterId],
        );

        return $rows ?: [];
    }

    public function adminListAbilityGrants(int $abilityId): array
    {
        $this->ensureAbilityExists($abilityId);

        return $this->fetchPrepared(
            'SELECT id,
                    ability_id,
                    source_type,
                    source_id,
                    grant_mode,
                    retention_policy,
                    min_rank,
                    max_rank,
                    is_active,
                    priority,
                    metadata_json,
                    date_created,
                    date_updated
             FROM lf_abilities_spells_grants
             WHERE ability_id = ?
             ORDER BY priority ASC, id ASC',
            [$abilityId],
        ) ?: [];
    }

    public function adminUpsertAbilityGrant(object $data): array
    {
        $id = max(0, $this->normalizeInt($data->id ?? 0, 0));
        $abilityId = max(0, $this->normalizeInt($data->ability_id ?? 0, 0));
        $sourceType = $this->normalizeEnum($data->source_type ?? 'character', ['character', 'archetype', 'guild', 'custom'], 'character');
        $sourceId = max(0, $this->normalizeInt($data->source_id ?? 0, 0));
        $grantMode = $this->normalizeEnum($data->grant_mode ?? 'unlock', ['unlock', 'auto_learn', 'bonus', 'forbid'], 'unlock');
        $retentionPolicy = $this->normalizeEnum($data->retention_policy ?? 'keep_when_lost', ['while_source_active', 'keep_when_lost', 'disable_when_lost', 'refund_when_lost'], 'keep_when_lost');
        $minRank = max(0, $this->normalizeInt($data->min_rank ?? 0, 0));
        $maxRank = max(0, $this->normalizeInt($data->max_rank ?? 0, 0));
        $isActive = $this->normalizeBool($data->is_active ?? 1, 1);
        $priority = $this->normalizeInt($data->priority ?? 100, 100);
        $metadataJson = $this->normalizeJsonValue($data->metadata_json ?? null);

        $this->ensureAbilityExists($abilityId);
        if ($sourceId <= 0) {
            $this->failValidation('Source ID obbligatorio', 'grant_source_id_required');
        }
        if ($maxRank > 0 && $minRank > 0 && $maxRank < $minRank) {
            $this->failValidation('Rank massimo non valido', 'grant_rank_range_invalid');
        }

        if ($id > 0) {
            $this->execPrepared(
                'UPDATE lf_abilities_spells_grants
                 SET ability_id = ?,
                     source_type = ?,
                     source_id = ?,
                     grant_mode = ?,
                     retention_policy = ?,
                     min_rank = ?,
                     max_rank = ?,
                     is_active = ?,
                     priority = ?,
                     metadata_json = ?,
                     date_updated = NOW()
                 WHERE id = ?',
                [
                    $abilityId,
                    $sourceType,
                    $sourceId,
                    $grantMode,
                    $retentionPolicy,
                    $minRank > 0 ? $minRank : null,
                    $maxRank > 0 ? $maxRank : null,
                    $isActive,
                    $priority,
                    $metadataJson,
                    $id,
                ],
            );
        } else {
            $this->execPrepared(
                'INSERT INTO lf_abilities_spells_grants
                    (ability_id, source_type, source_id, grant_mode, retention_policy, min_rank, max_rank, is_active, priority, metadata_json, date_created, date_updated)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    $abilityId,
                    $sourceType,
                    $sourceId,
                    $grantMode,
                    $retentionPolicy,
                    $minRank > 0 ? $minRank : null,
                    $maxRank > 0 ? $maxRank : null,
                    $isActive,
                    $priority,
                    $metadataJson,
                ],
            );
            $id = (int) $this->db->lastInsertId();
        }

        $row = $this->firstPrepared('SELECT * FROM lf_abilities_spells_grants WHERE id = ? LIMIT 1', [$id]);
        return is_object($row) ? (array) $row : [];
    }

    public function adminDeleteAbilityGrant(int $id): void
    {
        if ($id <= 0) {
            $this->failValidation('Grant non valido', 'grant_id_invalid');
        }

        $this->execPrepared('DELETE FROM lf_abilities_spells_grants WHERE id = ?', [$id]);
    }

    public function adminListAbilityRequirements(int $abilityId): array
    {
        $this->ensureAbilityExists($abilityId);

        return $this->fetchPrepared(
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
                    metadata_json,
                    date_created,
                    date_updated
             FROM lf_abilities_spells_requirements
             WHERE ability_id = ?
             ORDER BY level ASC, id ASC',
            [$abilityId],
        ) ?: [];
    }

    public function adminUpsertAbilityRequirement(object $data): array
    {
        $id = max(0, $this->normalizeInt($data->id ?? 0, 0));
        $abilityId = max(0, $this->normalizeInt($data->ability_id ?? 0, 0));
        $level = max(1, $this->normalizeInt($data->level ?? 1, 1));
        $requirementType = $this->normalizeEnum($data->requirement_type ?? 'attribute', ['ability', 'rank', 'attribute', 'archetype', 'guild', 'custom'], 'attribute');
        $requirementKey = $this->normalizeText($data->requirement_key ?? '');
        $operator = $this->normalizeEnum($data->operator ?? '>=', ['=', '!=', '>', '>=', '<', '<='], '>=');
        $requiredValue = $this->normalizeText($data->required_value ?? '');
        $policy = $this->normalizeEnum($data->policy_when_unavailable ?? 'block', ['ignore', 'block', 'hide'], 'block');
        $isHidden = $this->normalizeBool($data->is_hidden ?? 0, 0);
        $isActive = $this->normalizeBool($data->is_active ?? 1, 1);
        $metadataJson = $this->normalizeJsonValue($data->metadata_json ?? null);

        $this->ensureAbilityExists($abilityId);
        if ($requirementKey === '') {
            $this->failValidation('Requirement key obbligatoria', 'requirement_key_required');
        }
        if ($requiredValue === '') {
            $this->failValidation('Requirement value obbligatorio', 'requirement_value_required');
        }

        if ($id > 0) {
            $this->execPrepared(
                'UPDATE lf_abilities_spells_requirements
                 SET ability_id = ?,
                     level = ?,
                     requirement_type = ?,
                     requirement_key = ?,
                     operator = ?,
                     required_value = ?,
                     policy_when_unavailable = ?,
                     is_hidden = ?,
                     is_active = ?,
                     metadata_json = ?,
                     date_updated = NOW()
                 WHERE id = ?',
                [$abilityId, $level, $requirementType, $requirementKey, $operator, $requiredValue, $policy, $isHidden, $isActive, $metadataJson, $id],
            );
        } else {
            $this->execPrepared(
                'INSERT INTO lf_abilities_spells_requirements
                    (ability_id, level, requirement_type, requirement_key, operator, required_value, policy_when_unavailable, is_hidden, is_active, metadata_json, date_created, date_updated)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [$abilityId, $level, $requirementType, $requirementKey, $operator, $requiredValue, $policy, $isHidden, $isActive, $metadataJson],
            );
            $id = (int) $this->db->lastInsertId();
        }

        $row = $this->firstPrepared('SELECT * FROM lf_abilities_spells_requirements WHERE id = ? LIMIT 1', [$id]);
        return is_object($row) ? (array) $row : [];
    }

    public function adminDeleteAbilityRequirement(int $id): void
    {
        if ($id <= 0) {
            $this->failValidation('Requirement non valido', 'requirement_id_invalid');
        }

        $this->execPrepared('DELETE FROM lf_abilities_spells_requirements WHERE id = ?', [$id]);
    }

    public function adminListAbilityEffects(int $abilityId): array
    {
        $this->ensureAbilityExists($abilityId);

        return $this->fetchPrepared(
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
                    metadata_json,
                    date_created,
                    date_updated
             FROM lf_abilities_spells_effects
             WHERE ability_id = ?
             ORDER BY level ASC, id ASC',
            [$abilityId],
        ) ?: [];
    }

    public function adminUpsertAbilityEffect(object $data): array
    {
        $id = max(0, $this->normalizeInt($data->id ?? 0, 0));
        $abilityId = max(0, $this->normalizeInt($data->ability_id ?? 0, 0));
        $level = max(1, $this->normalizeInt($data->level ?? 1, 1));
        $effectType = $this->normalizeEnum($data->effect_type ?? 'modifier', ['modifier', 'narrative_state', 'custom'], 'modifier');
        $targetSystem = $this->normalizeText($data->target_system ?? '');
        $targetKey = $this->normalizeText($data->target_key ?? '');
        $operation = $this->normalizeEnum($data->operation ?? 'add', ['add', 'apply', 'remove'], 'add');
        $value = $this->normalizeFloat($data->value ?? 0, 0);
        $activationPolicy = $this->normalizeEnum($data->activation_policy ?? 'while_ability_usable', ['while_ability_usable', 'while_ability_learned', 'manual_toggle', 'temporary', 'on_use'], 'while_ability_usable');
        $policy = $this->normalizeEnum($data->policy_when_unavailable ?? 'ignore', ['ignore', 'block', 'hide'], 'ignore');
        $isActive = $this->normalizeBool($data->is_active ?? 1, 1);
        $metadataJson = $this->normalizeJsonValue($data->metadata_json ?? null);

        $this->ensureAbilityExists($abilityId);
        if ($targetSystem === '') {
            $this->failValidation('Target system obbligatorio', 'effect_target_system_required');
        }
        if ($targetKey === '') {
            $this->failValidation('Target key obbligatoria', 'effect_target_key_required');
        }

        if ($id > 0) {
            $this->execPrepared(
                'UPDATE lf_abilities_spells_effects
                 SET ability_id = ?,
                     level = ?,
                     effect_type = ?,
                     target_system = ?,
                     target_key = ?,
                     operation = ?,
                     value = ?,
                     activation_policy = ?,
                     policy_when_unavailable = ?,
                     is_active = ?,
                     metadata_json = ?,
                     date_updated = NOW()
                 WHERE id = ?',
                [$abilityId, $level, $effectType, $targetSystem, $targetKey, $operation, $value, $activationPolicy, $policy, $isActive, $metadataJson, $id],
            );
        } else {
            $this->execPrepared(
                'INSERT INTO lf_abilities_spells_effects
                    (ability_id, level, effect_type, target_system, target_key, operation, value, activation_policy, policy_when_unavailable, is_active, metadata_json, date_created, date_updated)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [$abilityId, $level, $effectType, $targetSystem, $targetKey, $operation, $value, $activationPolicy, $policy, $isActive, $metadataJson],
            );
            $id = (int) $this->db->lastInsertId();
        }

        $row = $this->firstPrepared('SELECT * FROM lf_abilities_spells_effects WHERE id = ? LIMIT 1', [$id]);
        return is_object($row) ? (array) $row : [];
    }

    public function adminDeleteAbilityEffect(int $id): void
    {
        if ($id <= 0) {
            $this->failValidation('Effetto non valido', 'effect_id_invalid');
        }

        $this->execPrepared('DELETE FROM lf_abilities_spells_effects WHERE id = ?', [$id]);
    }

    public function adminListPointCategories(): array
    {
        return $this->fetchPrepared(
            'SELECT id, slug, name, description, is_active, sort_order
             FROM lf_abilities_spells_point_categories
             ORDER BY sort_order ASC, name ASC, id ASC'
        ) ?: [];
    }

    public function adminListRankRewards(): array
    {
        return $this->fetchPrepared(
            'SELECT r.id,
                    r.rank,
                    r.point_category_id,
                    r.points,
                    r.is_active,
                    r.date_created,
                    r.date_updated,
                    pc.slug AS point_category_slug,
                    pc.name AS point_category_name
             FROM lf_abilities_spells_rank_point_rewards r
             LEFT JOIN lf_abilities_spells_point_categories pc ON pc.id = r.point_category_id
             ORDER BY r.rank ASC, pc.name ASC, r.id ASC'
        ) ?: [];
    }

    public function adminUpsertRankReward(object $data): array
    {
        $id = max(0, $this->normalizeInt($data->id ?? 0, 0));
        $rank = max(1, $this->normalizeInt($data->rank ?? 1, 1));
        $pointCategoryId = max(0, $this->normalizeInt($data->point_category_id ?? 0, 0));
        $points = $this->normalizeInt($data->points ?? 0, 0);
        $isActive = $this->normalizeBool($data->is_active ?? 1, 1);

        if ($pointCategoryId <= 0) {
            $this->failValidation('Categoria punti obbligatoria', 'point_category_required');
        }

        $duplicate = $this->firstPrepared(
            'SELECT id
             FROM lf_abilities_spells_rank_point_rewards
             WHERE rank = ?
               AND point_category_id = ?
               AND id <> ?
             LIMIT 1',
            [$rank, $pointCategoryId, $id],
        );
        if (!empty($duplicate)) {
            $this->failValidation('Ricompensa rank gia presente per la categoria selezionata', 'rank_reward_duplicate');
        }

        if ($id > 0) {
            $this->execPrepared(
                'UPDATE lf_abilities_spells_rank_point_rewards
                 SET rank = ?,
                     point_category_id = ?,
                     points = ?,
                     is_active = ?,
                     date_updated = NOW()
                 WHERE id = ?',
                [$rank, $pointCategoryId, $points, $isActive, $id],
            );
        } else {
            $this->execPrepared(
                'INSERT INTO lf_abilities_spells_rank_point_rewards
                    (rank, point_category_id, points, is_active, date_created, date_updated)
                 VALUES (?, ?, ?, ?, NOW(), NOW())',
                [$rank, $pointCategoryId, $points, $isActive],
            );
            $id = (int) $this->db->lastInsertId();
        }

        $row = $this->firstPrepared(
            'SELECT r.*, pc.slug AS point_category_slug, pc.name AS point_category_name
             FROM lf_abilities_spells_rank_point_rewards r
             LEFT JOIN lf_abilities_spells_point_categories pc ON pc.id = r.point_category_id
             WHERE r.id = ?
             LIMIT 1',
            [$id],
        );
        return is_object($row) ? (array) $row : [];
    }

    public function adminDeleteRankReward(int $id): void
    {
        if ($id <= 0) {
            $this->failValidation('Ricompensa non valida', 'rank_reward_invalid');
        }

        $this->execPrepared('DELETE FROM lf_abilities_spells_rank_point_rewards WHERE id = ?', [$id]);
    }

    public function adminListPendingApprovals(): array
    {
        return $this->fetchPrepared(
            'SELECT ca.id,
                    ca.character_id,
                    ca.ability_id,
                    ca.status,
                    ca.level,
                    ca.pending_points,
                    ca.spent_points,
                    ca.approval_status,
                    ca.sort_order,
                    ca.is_active,
                    ca.date_created,
                    ca.date_updated,
                    a.name AS ability_name,
                    a.slug AS ability_slug,
                    a.type AS ability_type,
                    a.max_level,
                    pc.name AS point_category_name,
                    pc.slug AS point_category_slug,
                    c.name AS character_name,
                    c.surname AS character_surname
             FROM lf_abilities_spells_character_abilities ca
             INNER JOIN lf_abilities_spells_abilities a ON a.id = ca.ability_id
             INNER JOIN characters c ON c.id = ca.character_id
             LEFT JOIN lf_abilities_spells_point_categories pc ON pc.id = a.point_category_id
             WHERE ca.approval_status = ?
                OR ca.status = ?
             ORDER BY ca.date_updated DESC, ca.id DESC',
            ['pending', 'pending_approval'],
        ) ?: [];
    }

    public function adminResolvePendingApproval(object $data, ?int $resolvedByUserId = null): array
    {
        $assignmentId = max(0, $this->normalizeInt($data->id ?? ($data->assignment_id ?? 0), 0));
        $decision = $this->normalizeApprovalDecision($data->decision ?? 'approve', 'approve');

        if ($assignmentId <= 0) {
            $this->failValidation('Assegnazione non valida', 'assignment_id_invalid');
        }

        $current = $this->firstPrepared(
            'SELECT ca.id,
                    ca.character_id,
                    ca.ability_id,
                    ca.status,
                    ca.level,
                    ca.pending_points,
                    ca.approval_status,
                    a.name AS ability_name,
                    a.max_level
             FROM lf_abilities_spells_character_abilities ca
             INNER JOIN lf_abilities_spells_abilities a ON a.id = ca.ability_id
             WHERE ca.id = ?
             LIMIT 1',
            [$assignmentId],
        );

        if (!is_object($current) || (int) ($current->id ?? 0) <= 0) {
            $this->failValidation('Assegnazione non trovata', 'assignment_not_found');
        }

        $oldStatus = $this->normalizeStatus($current->status ?? 'available', 'available');
        $oldLevel = max(0, (int) ($current->level ?? 0));
        $pendingPoints = max(0, (int) ($current->pending_points ?? 0));
        $maxLevel = max(1, (int) ($current->max_level ?? 1));

        if ($this->normalizeApprovalStatus($current->approval_status ?? 'approved', 'approved') !== 'pending'
            && $oldStatus !== 'pending_approval'
        ) {
            $this->failValidation('Questa assegnazione non e in attesa di approvazione', 'assignment_not_pending');
        }

        if ($decision === 'approve') {
            $newStatus = 'learned';
            $newLevel = $oldLevel > 0 ? min($maxLevel, $oldLevel + 1) : 1;
            $newPendingPoints = 0;
            $approvalStatus = 'approved';
            $approvedByUserId = ($resolvedByUserId !== null && $resolvedByUserId > 0) ? $resolvedByUserId : null;
            $approvedAt = date('Y-m-d H:i:s');
            $reason = $oldLevel > 0 ? 'upgrade_approved' : 'learn_approved';
            $message = trim((string) ($current->ability_name ?? 'Abilita')) . ' approvata.';
        } else {
            $newStatus = $oldLevel > 0 ? 'learned' : 'learning';
            $newLevel = $oldLevel;
            $newPendingPoints = $pendingPoints;
            $approvalStatus = 'rejected';
            $approvedByUserId = null;
            $approvedAt = null;
            $reason = $oldLevel > 0 ? 'upgrade_rejected' : 'learn_rejected';
            $message = trim((string) ($current->ability_name ?? 'Abilita')) . ' respinta.';
        }

        $this->execPrepared(
            'UPDATE lf_abilities_spells_character_abilities
             SET status = ?,
                 level = ?,
                 pending_points = ?,
                 approval_status = ?,
                 approved_by_user_id = ?,
                 approved_at = ?,
                 date_updated = NOW()
             WHERE id = ?',
            [
                $newStatus,
                $newLevel,
                $newPendingPoints,
                $approvalStatus,
                $approvedByUserId,
                $approvedAt,
                $assignmentId,
            ],
        );

        $this->emitCharacterAbilityChanged(
            (int) ($current->character_id ?? 0),
            (int) ($current->ability_id ?? 0),
            $oldStatus,
            $newStatus,
            $oldLevel,
            $newLevel,
            $reason,
            $resolvedByUserId,
            [
                'assignment_id' => $assignmentId,
                'decision' => $decision,
                'pending_points' => $newPendingPoints,
            ],
        );

        AuditLogService::writeEvent(
            $decision === 'approve' ? 'abilities_spells.approval_approved' : 'abilities_spells.approval_rejected',
            [
                'assignment_id' => $assignmentId,
                'character_id' => (int) ($current->character_id ?? 0),
                'ability_id' => (int) ($current->ability_id ?? 0),
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'old_level' => $oldLevel,
                'new_level' => $newLevel,
                'pending_points' => $newPendingPoints,
                'resolved_by_user_id' => ($resolvedByUserId !== null && $resolvedByUserId > 0) ? $resolvedByUserId : null,
            ],
            'admin',
            $resolvedByUserId,
        );

        $this->resolveStaffApprovalNotifications($assignmentId, $decision);
        $this->notifyPlayerAboutApprovalDecision(
            $assignmentId,
            (int) ($current->character_id ?? 0),
            (int) ($current->ability_id ?? 0),
            (string) ($current->ability_name ?? ''),
            $decision,
            $resolvedByUserId,
        );

        return [
            'id' => $assignmentId,
            'character_id' => (int) ($current->character_id ?? 0),
            'ability_id' => (int) ($current->ability_id ?? 0),
            'status' => $newStatus,
            'level' => $newLevel,
            'pending_points' => $newPendingPoints,
            'approval_status' => $approvalStatus,
            'message' => $message,
        ];
    }

    public function adminCreateAssignment(object $data): int
    {
        $characterId = $this->normalizeInt($data->character_id ?? 0, 0);
        $abilityId = $this->normalizeInt($data->ability_id ?? 0, 0);
        $sortOrder = $this->normalizeInt($data->sort_order ?? 100, 100);
        $isActive = $this->normalizeBool($data->is_active ?? 1, 1);
        $status = $this->normalizeStatus($data->status ?? 'learned', 'learned');
        $level = max(0, $this->normalizeInt($data->level ?? 1, 1));
        $approvalStatus = $this->normalizeApprovalStatus($data->approval_status ?? 'approved', 'approved');

        $this->ensureCharacterExists($characterId);

        $ability = $this->findAbilityById($abilityId);
        if (empty($ability)) {
            $this->failValidation('Abilita non trovata', 'ability_not_found');
        }

        $duplicate = $this->firstPrepared(
            'SELECT id
             FROM lf_abilities_spells_character_abilities
             WHERE character_id = ?
               AND ability_id = ?
             LIMIT 1',
            [$characterId, $abilityId],
        );
        if (!empty($duplicate)) {
            $this->failValidation('Abilita gia assegnata al personaggio', 'ability_assignment_duplicate');
        }

        $this->execPrepared(
            'INSERT INTO lf_abilities_spells_character_abilities
                (character_id, ability_id, status, level, pending_points, spent_points, approval_status, approved_at, sort_order, is_active, date_created, date_updated)
             VALUES (?, ?, ?, ?, 0, 0, ?, ?, ?, ?, NOW(), NOW())',
            [
                $characterId,
                $abilityId,
                $status,
                $level,
                $approvalStatus,
                $approvalStatus === 'approved' ? date('Y-m-d H:i:s') : null,
                $sortOrder,
                $isActive,
            ],
        );

        $assignmentId = (int) $this->db->lastInsertId();
        $this->emitCharacterAbilityChanged(
            $characterId,
            $abilityId,
            'available',
            $status,
            0,
            $level,
            'direct_assignment',
        );

        return $assignmentId;
    }

    public function adminDeleteAssignment(int $assignmentId): void
    {
        if ($assignmentId <= 0) {
            $this->failValidation('Assegnazione non valida', 'assignment_id_invalid');
        }

        $current = $this->firstPrepared(
            'SELECT character_id, ability_id, status, level
             FROM lf_abilities_spells_character_abilities
             WHERE id = ?
             LIMIT 1',
            [$assignmentId],
        );

        $this->execPrepared(
            'DELETE FROM lf_abilities_spells_character_abilities
             WHERE id = ?',
            [$assignmentId],
        );

        if (is_object($current)) {
            $this->emitCharacterAbilityChanged(
                (int) ($current->character_id ?? 0),
                (int) ($current->ability_id ?? 0),
                $this->normalizeStatus($current->status ?? 'learned', 'learned'),
                'disabled',
                (int) ($current->level ?? 1),
                0,
                'direct_assignment_removed',
            );
        }
    }

    public function adminCharactersSearch(string $query): array
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
                'label' => ($label !== '') ? $label : ('#' . (int) ($row->id ?? 0)),
                'name' => (string) ($row->name ?? ''),
                'surname' => (string) ($row->surname ?? ''),
            ];
        }

        return $dataset;
    }

    public function listCharacterAbilities(int $characterId): array
    {
        $this->ensureCharacterExists($characterId);

        $rows = $this->resolver()->resolveForCharacter($characterId);
        $dataset = [];
        foreach ($rows as $row) {
            $isProgressionRow = (int) ($row['assignment_id'] ?? 0) > 0;
            $isUnlocked = !empty($row['sources']) || $isProgressionRow;
            if (!$isUnlocked && empty($row['visible'])) {
                continue;
            }
            if (!$isProgressionRow && !$isUnlocked) {
                continue;
            }
            $dataset[] = $row;
        }

        return $dataset;
    }

    public function listCharacterAbilityPoints(int $characterId): array
    {
        $this->ensureCharacterExists($characterId);

        $rows = $this->fetchPrepared(
            'SELECT pc.id AS point_category_id,
                    pc.slug,
                    pc.name,
                    pc.description,
                    pc.is_active,
                    pc.sort_order,
                    COALESCE(cp.available_points, 0) AS available_points,
                    COALESCE(cp.spent_points, 0) AS spent_points,
                    COALESCE(cp.lifetime_points, 0) AS lifetime_points,
                    cp.date_updated
             FROM lf_abilities_spells_point_categories pc
             LEFT JOIN lf_abilities_spells_character_points cp
               ON cp.point_category_id = pc.id
              AND cp.character_id = ?
             ORDER BY pc.sort_order ASC, pc.name ASC, pc.id ASC',
            [$characterId],
        );

        return $rows ?: [];
    }

    public function useAbility(int $characterId, object $data): array
    {
        $this->ensureCharacterExists($characterId);

        $abilityId = $this->normalizeInt($data->ability_id ?? 0, 0);
        $sceneId = $this->normalizeInt($data->scene_id ?? ($data->location_id ?? 0), 0);
        $resolved = $this->resolver()->resolveForCharacter($characterId);
        $assignment = null;
        foreach ($resolved as $row) {
            if ((int) ($row['id'] ?? 0) === $abilityId) {
                $assignment = $row;
                break;
            }
        }

        if (!is_array($assignment)) {
            $this->failValidation('Abilita non assegnata al personaggio', 'ability_not_found');
        }
        if (empty($assignment['usable'])) {
            $status = strtolower(trim((string) ($assignment['status'] ?? '')));
            if ($status === 'suspended') {
                $this->failValidation('Abilita sospesa e non utilizzabile', 'ability_suspended');
            }
            $this->failValidation('Abilita non attiva', 'ability_inactive');
        }

        $targetType = $this->normalizeEnum($assignment['target_type'] ?? 'self', ['self', 'scene'], 'self');
        $effectMode = $this->normalizeEnum($assignment['effect_mode'] ?? 'none', ['none', 'apply_state', 'remove_state'], 'none');
        $narrativeStateId = $this->normalizeInt($assignment['narrative_state_id'] ?? 0, 0);

        $targetPayloadType = $targetType === 'scene' ? 'scene' : 'character';
        $targetId = $targetType === 'scene' ? $sceneId : $characterId;
        if ($targetType === 'scene' && $sceneId <= 0) {
            $this->failValidation('Questa abilita richiede una scena/location attiva', 'ability_scene_required');
        }

        $effectResult = null;
        if ($effectMode === 'apply_state') {
            if ($narrativeStateId <= 0) {
                $this->failValidation('Stato narrativo mancante', 'state_not_found');
            }
            $effectResult = $this->narrativeStateApplicationService()->applyState([
                'state_id' => $narrativeStateId,
                'target_type' => $targetPayloadType,
                'target_id' => $targetId,
                'scene_id' => $sceneId,
                'applier_character_id' => $characterId,
                'source_ability_id' => 0,
            ]);
        } elseif ($effectMode === 'remove_state') {
            if ($narrativeStateId <= 0) {
                $this->failValidation('Stato narrativo mancante', 'state_not_found');
            }
            $effectResult = $this->narrativeStateApplicationService()->removeState([
                'state_id' => $narrativeStateId,
                'target_type' => $targetPayloadType,
                'target_id' => $targetId,
                'scene_id' => $sceneId,
                'reason' => 'module_ability_use',
            ]);
        }

        $message = 'Abilita usata con successo.';
        if ($effectMode === 'apply_state' && trim((string) ($assignment['applies_state_name'] ?? '')) !== '') {
            $message = 'Abilita usata: stato "' . trim((string) $assignment['applies_state_name']) . '" applicato.';
        } elseif ($effectMode === 'remove_state' && trim((string) ($assignment['applies_state_name'] ?? '')) !== '') {
            $message = 'Abilita usata: stato "' . trim((string) $assignment['applies_state_name']) . '" rimosso.';
        }

        return [
            'ability' => [
                'id' => (int) ($assignment['id'] ?? 0),
                'name' => (string) ($assignment['name'] ?? ''),
                'slug' => (string) ($assignment['slug'] ?? ''),
                'target_type' => $targetType,
                'effect_mode' => $effectMode,
                'status' => (string) ($assignment['status'] ?? ''),
                'level' => (int) ($assignment['level'] ?? 0),
            ],
            'target' => [
                'type' => $targetPayloadType,
                'id' => $targetId,
                'scene_id' => $sceneId,
            ],
            'message' => $message,
            'effect_result' => $effectResult,
        ];
    }

    public function learnAbility(int $characterId, object $data): array
    {
        $abilityId = $this->normalizeInt($data->ability_id ?? 0, 0);
        $requestedPoints = max(0, $this->normalizeInt($data->points_to_spend ?? 0, 0));
        return $this->investAbilityPoints($characterId, $abilityId, 'learn', $requestedPoints);
    }

    public function upgradeAbility(int $characterId, object $data): array
    {
        $abilityId = $this->normalizeInt($data->ability_id ?? 0, 0);
        $requestedPoints = max(0, $this->normalizeInt($data->points_to_spend ?? 0, 0));
        return $this->investAbilityPoints($characterId, $abilityId, 'upgrade', $requestedPoints);
    }

    public function resolveCharacterAbilities(int $characterId): array
    {
        $this->ensureCharacterExists($characterId);
        return $this->resolver()->resolveForCharacter($characterId);
    }

    public function getCharacterAttributeModifiers(int $characterId): array
    {
        $dataset = [];
        foreach ($this->resolveCharacterAbilities($characterId) as $ability) {
            if (empty($ability['effects']) || empty($ability['usable'])) {
                continue;
            }

            foreach ((array) $ability['effects'] as $effect) {
                if (!is_array($effect)) {
                    continue;
                }

                $targetSystem = strtolower(trim((string) ($effect['target_system'] ?? '')));
                $operation = strtolower(trim((string) ($effect['operation'] ?? '')));
                $targetKey = trim((string) ($effect['target_key'] ?? ''));
                if ($targetSystem !== 'character_attributes' || $operation !== 'add' || $targetKey === '') {
                    continue;
                }

                if (empty($effect['active'])) {
                    continue;
                }

                $dataset[] = [
                    'source_system' => 'abilities_spells',
                    'source_type' => 'ability',
                    'source_id' => (int) ($ability['id'] ?? 0),
                    'source_label' => trim((string) ($ability['name'] ?? '')),
                    'attribute_slug' => $targetKey,
                    'operation' => 'add',
                    'value' => (float) ($effect['value'] ?? 0),
                    'priority' => 100,
                    'stack_group' => 'ability:' . (int) ($ability['id'] ?? 0),
                    'is_active' => true,
                    'metadata' => [
                        'ability_slug' => (string) ($ability['slug'] ?? ''),
                        'ability_level' => (int) ($ability['level'] ?? 0),
                        'effect_id' => (int) ($effect['id'] ?? 0),
                    ],
                ];
            }
        }

        return $dataset;
    }

    private function resolveCharacterAbilityById(int $characterId, int $abilityId): ?array
    {
        foreach ($this->resolveCharacterAbilities($characterId) as $ability) {
            if ((int) ($ability['id'] ?? 0) === $abilityId) {
                return $ability;
            }
        }

        return null;
    }

    private function characterPointBalance(int $characterId, int $pointCategoryId): array
    {
        if ($pointCategoryId <= 0) {
            return [
                'point_category_id' => 0,
                'available_points' => 0,
                'spent_points' => 0,
                'lifetime_points' => 0,
            ];
        }

        $row = $this->firstPrepared(
            'SELECT point_category_id,
                    available_points,
                    spent_points,
                    lifetime_points
             FROM lf_abilities_spells_character_points
             WHERE character_id = ?
               AND point_category_id = ?
             LIMIT 1',
            [$characterId, $pointCategoryId],
        );

        if (!is_object($row)) {
            return [
                'point_category_id' => $pointCategoryId,
                'available_points' => 0,
                'spent_points' => 0,
                'lifetime_points' => 0,
            ];
        }

        return [
            'point_category_id' => (int) ($row->point_category_id ?? 0),
            'available_points' => (int) ($row->available_points ?? 0),
            'spent_points' => (int) ($row->spent_points ?? 0),
            'lifetime_points' => (int) ($row->lifetime_points ?? 0),
        ];
    }

    private function investAbilityPoints(int $characterId, int $abilityId, string $mode, int $requestedPoints = 0): array
    {
        $this->ensureCharacterExists($characterId);
        if ($abilityId <= 0) {
            $this->failValidation('Abilita non valida', 'ability_id_invalid');
        }

        $ability = $this->resolveCharacterAbilityById($characterId, $abilityId);
        if (!is_array($ability)) {
            $this->failValidation('Abilita non trovata', 'ability_not_found');
        }

        $status = strtolower(trim((string) ($ability['status'] ?? '')));
        $currentLevel = max(0, (int) ($ability['level'] ?? 0));
        $maxLevel = max(1, (int) ($ability['max_level'] ?? 1));
        $isLearnMode = $mode === 'learn';

        if ($isLearnMode) {
            if ($currentLevel > 0 || $status === 'learned') {
                $this->failValidation('Abilita gia appresa', 'ability_already_learned');
            }
            if (empty($ability['learnable']) && $status !== 'learning') {
                $this->failValidation('Abilita non apprendibile', 'ability_not_learnable');
            }
        } else {
            if ($currentLevel <= 0 || $status !== 'learned') {
                $this->failValidation('Abilita non upgradabile', 'ability_not_upgradeable');
            }
            if ($currentLevel >= $maxLevel) {
                $this->failValidation('Abilita gia al livello massimo', 'ability_max_level_reached');
            }
            if (empty($ability['upgradeable']) && $status !== 'learning') {
                $this->failValidation('Upgrade non disponibile', 'ability_upgrade_not_available');
            }
        }

        $pointCategoryId = max(0, (int) ($ability['point_category_id'] ?? 0));
        $pointsRequired = max(0, (int) ($ability['points_required'] ?? 0));
        $currentPending = max(0, (int) ($ability['pending_points'] ?? 0));
        $remainingToNext = max(0, (int) ($ability['points_remaining_to_next_level'] ?? ($pointsRequired - $currentPending)));
        $requiresApproval = (int) ($ability['requires_staff_approval'] ?? 0) === 1;
        $allowPartial = $this->settingEnabled('allow_partial_investment', true);

        $pointBalance = $this->characterPointBalance($characterId, $pointCategoryId);
        $availablePoints = max(0, (int) ($pointBalance['available_points'] ?? 0));

        $spendPoints = 0;
        if ($pointsRequired > 0) {
            if ($pointCategoryId <= 0) {
                $this->failValidation('Categoria punti non configurata per questa abilita', 'ability_point_category_missing');
            }

            if ($remainingToNext > 0) {
                if ($availablePoints <= 0) {
                    $this->failValidation('Punti abilita insufficienti', 'ability_points_insufficient');
                }

                $spendPoints = $requestedPoints > 0 ? min($requestedPoints, $remainingToNext, $availablePoints) : min($remainingToNext, $availablePoints);
                if (!$allowPartial && $spendPoints < $remainingToNext) {
                    $this->failValidation('Punti insufficienti per completare il livello', 'ability_points_insufficient');
                }
                if ($spendPoints <= 0) {
                    $this->failValidation('Investimento punti non valido', 'ability_points_invalid');
                }
            }
        }

        $thresholdReached = $pointsRequired === 0 || ($currentPending + $spendPoints) >= max(1, $pointsRequired);
        $oldStatus = $status !== '' ? $status : 'available';
        $oldLevel = $currentLevel;
        $newLevel = $currentLevel;
        $newPending = $currentPending + $spendPoints;
        $newStatus = $currentLevel > 0 ? 'learning' : 'available';
        $approvalStatus = 'approved';
        $reason = $isLearnMode ? 'learning_progress' : 'upgrade_progress';

        if ($thresholdReached) {
            if ($requiresApproval) {
                $newStatus = 'pending_approval';
                $approvalStatus = 'pending';
                $reason = $isLearnMode ? 'learn_pending_approval' : 'upgrade_pending_approval';
            } else {
                $newLevel = $isLearnMode ? 1 : min($maxLevel, $currentLevel + 1);
                $newPending = 0;
                $newStatus = 'learned';
                $reason = $isLearnMode ? 'learned' : 'upgraded';
            }
        } else {
            $newStatus = 'learning';
            $approvalStatus = 'approved';
        }

        $existingAssignmentId = max(0, (int) ($ability['assignment_id'] ?? 0));
        $resolvedAssignmentId = $existingAssignmentId;
        $assignmentSortOrder = max(0, (int) ($ability['assignment_sort_order'] ?? ($ability['sort_order'] ?? 100)));

        $this->beginTransaction();
        try {
            if ($spendPoints > 0) {
                $this->execPrepared(
                    'INSERT IGNORE INTO lf_abilities_spells_character_points
                        (character_id, point_category_id, available_points, spent_points, lifetime_points, date_updated)
                     VALUES (?, ?, 0, 0, 0, NOW())',
                    [$characterId, $pointCategoryId],
                );

                $this->execPrepared(
                    'UPDATE lf_abilities_spells_character_points
                     SET available_points = available_points - ?,
                         spent_points = spent_points + ?,
                         date_updated = NOW()
                     WHERE character_id = ?
                       AND point_category_id = ?',
                    [$spendPoints, $spendPoints, $characterId, $pointCategoryId],
                );

                $logReason = $isLearnMode ? 'ability_learn' : 'ability_upgrade';
                $this->execPrepared(
                    'INSERT INTO lf_abilities_spells_character_point_logs
                        (character_id, point_category_id, delta, reason, reference_type, reference_id, created_by_user_id, note, date_created)
                     VALUES (?, ?, ?, ?, ?, ?, NULL, ?, NOW())',
                    [
                        $characterId,
                        $pointCategoryId,
                        -$spendPoints,
                        $logReason,
                        'ability',
                        $abilityId,
                        ($isLearnMode ? 'Investimento punti su apprendimento ' : 'Investimento punti su upgrade ') . ((string) ($ability['name'] ?? 'abilita')),
                    ],
                );
            }

            if ($existingAssignmentId > 0) {
                $this->execPrepared(
                    'UPDATE lf_abilities_spells_character_abilities
                     SET status = ?,
                         level = ?,
                         pending_points = ?,
                         spent_points = spent_points + ?,
                         approval_status = ?,
                         approved_at = ?,
                         is_active = 1,
                         date_updated = NOW()
                     WHERE id = ?',
                    [
                        $newStatus,
                        $newLevel,
                        $newPending,
                        $spendPoints,
                        $approvalStatus,
                        $approvalStatus === 'approved' && $newStatus === 'learned' ? date('Y-m-d H:i:s') : null,
                        $existingAssignmentId,
                    ],
                );
            } else {
                $this->execPrepared(
                    'INSERT INTO lf_abilities_spells_character_abilities
                        (character_id, ability_id, status, level, pending_points, spent_points, approval_status, approved_at, sort_order, is_active, date_created, date_updated)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
                    [
                        $characterId,
                        $abilityId,
                        $newStatus,
                        $newLevel,
                        $newPending,
                        $spendPoints,
                        $approvalStatus,
                        $approvalStatus === 'approved' && $newStatus === 'learned' ? date('Y-m-d H:i:s') : null,
                        $assignmentSortOrder > 0 ? $assignmentSortOrder : 100,
                    ],
                );
                $resolvedAssignmentId = (int) $this->db->lastInsertId();
            }

            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }

        $this->emitCharacterAbilityChanged(
            $characterId,
            $abilityId,
            $oldStatus,
            $newStatus,
            $oldLevel,
            $newLevel,
            $reason,
        );

        if ($reason === 'learn_pending_approval' || $reason === 'upgrade_pending_approval') {
            $owner = $this->getCharacterOwnerContext($characterId);
            $this->notifyStaffAboutPendingApproval(
                $resolvedAssignmentId,
                $characterId,
                $abilityId,
                (string) ($ability['name'] ?? ''),
                !$isLearnMode,
                (int) ($owner['user_id'] ?? 0) > 0 ? (int) ($owner['user_id'] ?? 0) : null,
            );
        }

        $updated = $this->resolveCharacterAbilityById($characterId, $abilityId);
        return [
            'ability' => $updated ?: $ability,
            'points_spent' => $spendPoints,
            'message' => $this->abilityProgressMessage($updated ?: $ability, $reason, $spendPoints),
            'point_balances' => $this->listCharacterAbilityPoints($characterId),
        ];
    }

    private function abilityProgressMessage(array $ability, string $reason, int $pointsSpent): string
    {
        $name = trim((string) ($ability['name'] ?? 'Abilita'));
        $status = trim((string) ($ability['status'] ?? ''));
        $level = max(0, (int) ($ability['level'] ?? 0));

        if ($reason === 'learned') {
            return $name . ' appresa con successo.';
        }
        if ($reason === 'upgraded') {
            return $name . ' portata al livello ' . $level . '.';
        }
        if ($reason === 'learn_pending_approval' || $reason === 'upgrade_pending_approval') {
            return $name . ' ha raggiunto la soglia ed e in attesa di approvazione staff.';
        }

        if ($pointsSpent > 0) {
            return $name . ': investiti ' . $pointsSpent . ' punti. Stato attuale: ' . ($status !== '' ? $status : 'learning') . '.';
        }

        return $name . ' aggiornata.';
    }

    public function allocateRankPointRewardsFromEvent(array $payload): void
    {
        if (!$this->settingEnabled('rank_points_enabled', false)) {
            return;
        }

        $characterId = (int) ($payload['character_id'] ?? 0);
        $oldRank = max(0, (int) ($payload['old_rank'] ?? 0));
        $newRank = max(0, (int) ($payload['new_rank'] ?? 0));
        $changedByUserId = isset($payload['changed_by_user_id']) ? (int) $payload['changed_by_user_id'] : null;

        if ($characterId <= 0 || $newRank <= $oldRank) {
            return;
        }

        $rows = $this->fetchPrepared(
            'SELECT rank, point_category_id, points
             FROM lf_abilities_spells_rank_point_rewards
             WHERE is_active = 1
               AND rank > ?
               AND rank <= ?
             ORDER BY rank ASC, point_category_id ASC',
            [$oldRank, $newRank],
        );

        foreach ($rows as $row) {
            $pointCategoryId = (int) ($row->point_category_id ?? 0);
            $points = (int) ($row->points ?? 0);
            $rank = (int) ($row->rank ?? 0);
            if ($pointCategoryId <= 0 || $points === 0) {
                continue;
            }

            $this->execPrepared(
                'INSERT INTO lf_abilities_spells_character_points
                    (character_id, point_category_id, available_points, spent_points, lifetime_points, date_updated)
                 VALUES (?, ?, ?, 0, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    available_points = available_points + VALUES(available_points),
                    lifetime_points = lifetime_points + VALUES(lifetime_points),
                    date_updated = NOW()',
                [$characterId, $pointCategoryId, $points, $points],
            );

            $this->execPrepared(
                'INSERT INTO lf_abilities_spells_character_point_logs
                    (character_id, point_category_id, delta, reason, reference_type, reference_id, created_by_user_id, note, date_created)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    $characterId,
                    $pointCategoryId,
                    $points,
                    'rank_up',
                    'rank',
                    $rank,
                    $changedByUserId,
                    'Punti assegnati al passaggio rank ' . $rank,
                ],
            );
        }
    }
}
