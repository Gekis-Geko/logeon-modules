<?php

declare(strict_types=1);

namespace Modules\Logeon\NarrativeStates\Services;

use App\Services\NarrativeStateApplicationService;
use App\Services\NarrativeStateService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class NarrativePresetService
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var NarrativeStateService|null */
    private $narrativeStateService = null;
    /** @var NarrativeStateApplicationService|null */
    private $narrativeStateApplicationService = null;

    public function __construct(
        DbAdapterInterface $db = null,
        NarrativeStateService $narrativeStateService = null,
        NarrativeStateApplicationService $narrativeStateApplicationService = null,
    ) {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->narrativeStateService = $narrativeStateService;
        $this->narrativeStateApplicationService = $narrativeStateApplicationService;
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

    private function narrativeStateService(): NarrativeStateService
    {
        if ($this->narrativeStateService instanceof NarrativeStateService) {
            return $this->narrativeStateService;
        }

        $this->narrativeStateService = new NarrativeStateService($this->db);
        return $this->narrativeStateService;
    }

    private function narrativeStateApplicationService(): NarrativeStateApplicationService
    {
        if ($this->narrativeStateApplicationService instanceof NarrativeStateApplicationService) {
            return $this->narrativeStateApplicationService;
        }

        $this->narrativeStateApplicationService = new NarrativeStateApplicationService($this->db);
        return $this->narrativeStateApplicationService;
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
        return is_numeric($raw) ? (float) $raw : $default;
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

    private function normalizeEnum($value, array $allowed, string $fallback): string
    {
        $candidate = strtolower(trim((string) $value));
        return in_array($candidate, $allowed, true) ? $candidate : $fallback;
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

    private function ensureSceneAccess(int $sceneId, int $characterId): void
    {
        if ($sceneId <= 0) {
            $this->failValidation('Scene ID obbligatorio per questo preset', 'scene_id_required');
        }

        $access = (new \Locations())->canAccess($sceneId, $characterId);
        if (empty($access['allowed'])) {
            $this->failValidation('Accesso non consentito alla scena', 'location_access_denied');
        }
    }

    private function ensureStateExists(int $stateId): void
    {
        if ($stateId <= 0) {
            $this->failValidation('Stato narrativo non valido', 'state_not_found');
        }

        $this->narrativeStateService()->getByIdOrCode($stateId, '', true);
    }

    private function findPresetById(int $presetId)
    {
        if ($presetId <= 0) {
            return null;
        }

        return $this->firstPrepared(
            'SELECT p.*,
                    (
                        SELECT COUNT(*)
                        FROM lf_narrative_preset_states ps
                        WHERE ps.preset_id = p.id
                    ) AS steps_count
             FROM lf_narrative_presets p
             WHERE p.id = ?
             LIMIT 1',
            [$presetId],
        );
    }

    private function findPresetStateById(int $presetStateId)
    {
        if ($presetStateId <= 0) {
            return null;
        }

        return $this->firstPrepared(
            'SELECT ps.*,
                    p.name AS preset_name,
                    ns.name AS state_name,
                    ns.code AS state_code
             FROM lf_narrative_preset_states ps
             INNER JOIN lf_narrative_presets p ON p.id = ps.preset_id
             INNER JOIN narrative_states ns ON ns.id = ps.state_id
             WHERE ps.id = ?
             LIMIT 1',
            [$presetStateId],
        );
    }

    private function findAssignmentById(int $assignmentId)
    {
        if ($assignmentId <= 0) {
            return null;
        }

        return $this->firstPrepared(
            'SELECT a.*,
                    p.name AS preset_name,
                    p.slug AS preset_slug,
                    p.description AS preset_description,
                    p.target_type,
                    p.category_label,
                    p.visible_to_players,
                    p.is_active AS preset_is_active,
                    c.name AS character_name,
                    c.surname AS character_surname
             FROM lf_narrative_character_presets a
             INNER JOIN lf_narrative_presets p ON p.id = a.preset_id
             INNER JOIN characters c ON c.id = a.character_id
             WHERE a.id = ?
             LIMIT 1',
            [$assignmentId],
        );
    }

    /** @return array<string,mixed> */
    private function normalizePresetPayload(object $data, ?int $presetId = null): array
    {
        $name = $this->normalizeText($data->name ?? '');
        $slug = $this->normalizeSlug($data->slug ?? '', $name);
        $description = $this->normalizeText($data->description ?? '');
        $targetType = $this->normalizeEnum($data->target_type ?? 'character', ['character', 'scene'], 'character');
        $categoryLabel = $this->normalizeText($data->category_label ?? '');
        $visibleToPlayers = $this->normalizeBool($data->visible_to_players ?? 1, 1);
        $sortOrder = $this->normalizeInt($data->sort_order ?? 100, 100);
        $isActive = $this->normalizeBool($data->is_active ?? 1, 1);

        if ($name === '') {
            $this->failValidation('Nome preset obbligatorio', 'preset_name_required');
        }
        if ($slug === '') {
            $this->failValidation('Slug preset non valido', 'preset_slug_invalid');
        }

        $duplicate = $this->firstPrepared(
            'SELECT id
             FROM lf_narrative_presets
             WHERE slug = ?
               AND (? IS NULL OR id <> ?)
             LIMIT 1',
            [$slug, $presetId, $presetId],
        );
        if (!empty($duplicate)) {
            $this->failValidation('Slug gia in uso', 'preset_slug_duplicate');
        }

        return [
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'target_type' => $targetType,
            'category_label' => $categoryLabel,
            'visible_to_players' => $visibleToPlayers,
            'sort_order' => $sortOrder,
            'is_active' => $isActive,
        ];
    }

    /** @return array<string,mixed> */
    private function normalizePresetStatePayload(object $data, ?int $presetStateId = null): array
    {
        $presetId = $this->normalizeInt($data->preset_id ?? 0, 0);
        $stateId = $this->normalizeInt($data->state_id ?? 0, 0);
        $effectMode = $this->normalizeEnum($data->effect_mode ?? 'apply', ['apply', 'remove'], 'apply');
        $intensity = max(0.0, $this->normalizeFloat($data->intensity ?? 1, 1.0));
        $durationValue = $this->normalizeInt($data->duration_value ?? 0, 0);
        $durationUnit = $this->normalizeEnum($data->duration_unit ?? 'scene', ['turn', 'minute', 'hour', 'day', 'scene'], 'scene');
        $sortOrder = $this->normalizeInt($data->sort_order ?? 100, 100);

        if (empty($this->findPresetById($presetId))) {
            $this->failValidation('Preset non trovato', 'preset_not_found');
        }
        $this->ensureStateExists($stateId);

        if ($effectMode === 'remove') {
            $intensity = 0.0;
            $durationValue = 0;
        }
        if ($durationValue <= 0) {
            $durationValue = 0;
            $durationUnit = 'scene';
        }

        $duplicate = $this->firstPrepared(
            'SELECT id
             FROM lf_narrative_preset_states
             WHERE preset_id = ?
               AND state_id = ?
               AND effect_mode = ?
               AND (? IS NULL OR id <> ?)
             LIMIT 1',
            [$presetId, $stateId, $effectMode, $presetStateId, $presetStateId],
        );
        if (!empty($duplicate)) {
            $this->failValidation('Questo stato e gia presente nel preset con la stessa azione', 'preset_state_duplicate');
        }

        return [
            'preset_id' => $presetId,
            'state_id' => $stateId,
            'effect_mode' => $effectMode,
            'intensity' => $intensity,
            'duration_value' => $durationValue > 0 ? $durationValue : null,
            'duration_unit' => $durationValue > 0 ? $durationUnit : null,
            'sort_order' => $sortOrder,
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeAssignmentPayload(object $data): array
    {
        $characterId = $this->normalizeInt($data->character_id ?? 0, 0);
        $presetId = $this->normalizeInt($data->preset_id ?? 0, 0);
        $sortOrder = $this->normalizeInt($data->sort_order ?? 100, 100);
        $isActive = $this->normalizeBool($data->is_active ?? 1, 1);

        $this->ensureCharacterExists($characterId);
        if (empty($this->findPresetById($presetId))) {
            $this->failValidation('Preset non trovato', 'preset_not_found');
        }

        return [
            'character_id' => $characterId,
            'preset_id' => $presetId,
            'sort_order' => $sortOrder,
            'is_active' => $isActive,
        ];
    }

    private function targetTypeLabel(string $targetType): string
    {
        return $targetType === 'scene' ? 'Scena' : 'Personaggio';
    }

    /** @return array<string,mixed> */
    private function serializePresetRow($row): array
    {
        $data = is_object($row) ? get_object_vars($row) : (array) $row;
        $data['target_type_label'] = $this->targetTypeLabel((string) ($data['target_type'] ?? 'character'));
        return $data;
    }

    /** @return array<string,mixed> */
    private function serializePresetStateRow($row): array
    {
        $data = is_object($row) ? get_object_vars($row) : (array) $row;
        $effectMode = (string) ($data['effect_mode'] ?? 'apply');
        $durationValue = (int) ($data['duration_value'] ?? 0);
        $durationUnit = trim((string) ($data['duration_unit'] ?? ''));
        $data['effect_mode_label'] = ($effectMode === 'remove') ? 'Rimuovi' : 'Applica';
        $data['duration_label'] = ($effectMode === 'remove' || $durationValue <= 0)
            ? '-'
            : ($durationValue . ' ' . ($durationUnit !== '' ? $durationUnit : 'scene'));
        return $data;
    }

    /** @return array<string,mixed> */
    private function serializeAssignmentRow($row): array
    {
        $data = is_object($row) ? get_object_vars($row) : (array) $row;
        $data['target_type_label'] = $this->targetTypeLabel((string) ($data['target_type'] ?? 'character'));
        $data['character_label'] = trim(((string) ($data['character_name'] ?? '')) . ' ' . ((string) ($data['character_surname'] ?? '')));
        return $data;
    }

    public function adminStatesCatalog(): array
    {
        return $this->narrativeStateService()->catalog(true);
    }

    public function adminPresetsList(): array
    {
        $rows = $this->fetchPrepared(
            'SELECT p.*,
                    (
                        SELECT COUNT(*)
                        FROM lf_narrative_preset_states ps
                        WHERE ps.preset_id = p.id
                    ) AS steps_count
             FROM lf_narrative_presets p
             ORDER BY p.sort_order ASC, p.name ASC, p.id ASC',
        );

        return array_map(fn($row) => $this->serializePresetRow($row), $rows ?: []);
    }

    public function adminPresetCreate(object $data): int
    {
        $payload = $this->normalizePresetPayload($data);

        $this->execPrepared(
            'INSERT INTO lf_narrative_presets
                (name, slug, description, target_type, category_label, visible_to_players, sort_order, is_active, date_created, date_updated)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $payload['name'],
                $payload['slug'],
                $payload['description'],
                $payload['target_type'],
                $payload['category_label'],
                $payload['visible_to_players'],
                $payload['sort_order'],
                $payload['is_active'],
            ],
        );

        return (int) $this->db->lastInsertId();
    }

    public function adminPresetUpdate(object $data): void
    {
        $id = $this->normalizeInt($data->id ?? 0, 0);
        if ($id <= 0 || empty($this->findPresetById($id))) {
            $this->failValidation('Preset non trovato', 'preset_not_found');
        }

        $payload = $this->normalizePresetPayload($data, $id);

        $this->execPrepared(
            'UPDATE lf_narrative_presets
             SET name = ?,
                 slug = ?,
                 description = ?,
                 target_type = ?,
                 category_label = ?,
                 visible_to_players = ?,
                 sort_order = ?,
                 is_active = ?,
                 date_updated = NOW()
             WHERE id = ?',
            [
                $payload['name'],
                $payload['slug'],
                $payload['description'],
                $payload['target_type'],
                $payload['category_label'],
                $payload['visible_to_players'],
                $payload['sort_order'],
                $payload['is_active'],
                $id,
            ],
        );
    }

    public function adminPresetDelete(int $presetId): void
    {
        if ($presetId <= 0) {
            $this->failValidation('Preset non valido', 'preset_not_found');
        }

        $this->execPrepared('DELETE FROM lf_narrative_character_presets WHERE preset_id = ?', [$presetId]);
        $this->execPrepared('DELETE FROM lf_narrative_preset_states WHERE preset_id = ?', [$presetId]);
        $this->execPrepared('DELETE FROM lf_narrative_presets WHERE id = ?', [$presetId]);
    }

    public function adminPresetStatesList(int $presetId): array
    {
        if ($presetId <= 0) {
            return [];
        }
        if (empty($this->findPresetById($presetId))) {
            $this->failValidation('Preset non trovato', 'preset_not_found');
        }

        $rows = $this->fetchPrepared(
            'SELECT ps.*,
                    p.name AS preset_name,
                    ns.name AS state_name,
                    ns.code AS state_code
             FROM lf_narrative_preset_states ps
             INNER JOIN lf_narrative_presets p ON p.id = ps.preset_id
             INNER JOIN narrative_states ns ON ns.id = ps.state_id
             WHERE ps.preset_id = ?
             ORDER BY ps.sort_order ASC, ps.id ASC',
            [$presetId],
        );

        return array_map(fn($row) => $this->serializePresetStateRow($row), $rows ?: []);
    }

    public function adminPresetStateCreate(object $data): int
    {
        $payload = $this->normalizePresetStatePayload($data);

        $this->execPrepared(
            'INSERT INTO lf_narrative_preset_states
                (preset_id, state_id, effect_mode, intensity, duration_value, duration_unit, sort_order, date_created, date_updated)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $payload['preset_id'],
                $payload['state_id'],
                $payload['effect_mode'],
                $payload['intensity'],
                $payload['duration_value'],
                $payload['duration_unit'],
                $payload['sort_order'],
            ],
        );

        return (int) $this->db->lastInsertId();
    }

    public function adminPresetStateUpdate(object $data): void
    {
        $id = $this->normalizeInt($data->id ?? 0, 0);
        if ($id <= 0 || empty($this->findPresetStateById($id))) {
            $this->failValidation('Step preset non trovato', 'preset_state_not_found');
        }

        $payload = $this->normalizePresetStatePayload($data, $id);

        $this->execPrepared(
            'UPDATE lf_narrative_preset_states
             SET preset_id = ?,
                 state_id = ?,
                 effect_mode = ?,
                 intensity = ?,
                 duration_value = ?,
                 duration_unit = ?,
                 sort_order = ?,
                 date_updated = NOW()
             WHERE id = ?',
            [
                $payload['preset_id'],
                $payload['state_id'],
                $payload['effect_mode'],
                $payload['intensity'],
                $payload['duration_value'],
                $payload['duration_unit'],
                $payload['sort_order'],
                $id,
            ],
        );
    }

    public function adminPresetStateDelete(int $presetStateId): void
    {
        if ($presetStateId <= 0) {
            $this->failValidation('Step preset non valido', 'preset_state_not_found');
        }

        $this->execPrepared('DELETE FROM lf_narrative_preset_states WHERE id = ?', [$presetStateId]);
    }

    public function adminAssignmentsList(int $characterId): array
    {
        $this->ensureCharacterExists($characterId);

        $rows = $this->fetchPrepared(
            'SELECT a.*,
                    p.name AS preset_name,
                    p.slug AS preset_slug,
                    p.description AS preset_description,
                    p.target_type,
                    p.category_label,
                    p.visible_to_players,
                    c.name AS character_name,
                    c.surname AS character_surname
             FROM lf_narrative_character_presets a
             INNER JOIN lf_narrative_presets p ON p.id = a.preset_id
             INNER JOIN characters c ON c.id = a.character_id
             WHERE a.character_id = ?
             ORDER BY a.sort_order ASC, a.id ASC',
            [$characterId],
        );

        return array_map(fn($row) => $this->serializeAssignmentRow($row), $rows ?: []);
    }

    public function adminAssignmentCreate(object $data): int
    {
        $payload = $this->normalizeAssignmentPayload($data);

        $duplicate = $this->firstPrepared(
            'SELECT id
             FROM lf_narrative_character_presets
             WHERE character_id = ?
               AND preset_id = ?
             LIMIT 1',
            [$payload['character_id'], $payload['preset_id']],
        );
        if (!empty($duplicate)) {
            $this->failValidation('Preset gia assegnato al personaggio', 'preset_assignment_duplicate');
        }

        $this->execPrepared(
            'INSERT INTO lf_narrative_character_presets
                (character_id, preset_id, sort_order, is_active, date_created, date_updated)
             VALUES (?, ?, ?, ?, NOW(), NOW())',
            [
                $payload['character_id'],
                $payload['preset_id'],
                $payload['sort_order'],
                $payload['is_active'],
            ],
        );

        return (int) $this->db->lastInsertId();
    }

    public function adminAssignmentUpdate(object $data): void
    {
        $id = $this->normalizeInt($data->id ?? 0, 0);
        $current = $this->findAssignmentById($id);
        if ($id <= 0 || empty($current)) {
            $this->failValidation('Assegnazione non trovata', 'preset_assignment_not_found');
        }

        $payload = $this->normalizeAssignmentPayload($data);
        $duplicate = $this->firstPrepared(
            'SELECT id
             FROM lf_narrative_character_presets
             WHERE character_id = ?
               AND preset_id = ?
               AND id <> ?
             LIMIT 1',
            [$payload['character_id'], $payload['preset_id'], $id],
        );
        if (!empty($duplicate)) {
            $this->failValidation('Preset gia assegnato al personaggio', 'preset_assignment_duplicate');
        }

        $this->execPrepared(
            'UPDATE lf_narrative_character_presets
             SET character_id = ?,
                 preset_id = ?,
                 sort_order = ?,
                 is_active = ?,
                 date_updated = NOW()
             WHERE id = ?',
            [
                $payload['character_id'],
                $payload['preset_id'],
                $payload['sort_order'],
                $payload['is_active'],
                $id,
            ],
        );
    }

    public function adminAssignmentDelete(int $assignmentId): void
    {
        if ($assignmentId <= 0) {
            $this->failValidation('Assegnazione non valida', 'preset_assignment_not_found');
        }

        $this->execPrepared('DELETE FROM lf_narrative_character_presets WHERE id = ?', [$assignmentId]);
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

    public function listCharacterPresets(int $characterId): array
    {
        $this->ensureCharacterExists($characterId);

        $rows = $this->fetchPrepared(
            'SELECT a.*,
                    p.name AS preset_name,
                    p.slug AS preset_slug,
                    p.description AS preset_description,
                    p.target_type,
                    p.category_label,
                    p.visible_to_players,
                    (
                        SELECT COUNT(*)
                        FROM lf_narrative_preset_states ps
                        WHERE ps.preset_id = a.preset_id
                    ) AS steps_count
             FROM lf_narrative_character_presets a
             INNER JOIN lf_narrative_presets p ON p.id = a.preset_id
             WHERE a.character_id = ?
               AND a.is_active = 1
               AND p.is_active = 1
             ORDER BY a.sort_order ASC, a.id ASC',
            [$characterId],
        );

        return array_map(fn($row) => $this->serializeAssignmentRow($row), $rows ?: []);
    }

    private function loadPresetStepsForAssignment(int $presetId): array
    {
        return $this->fetchPrepared(
            'SELECT ps.*,
                    ns.name AS state_name,
                    ns.code AS state_code
             FROM lf_narrative_preset_states ps
             INNER JOIN narrative_states ns ON ns.id = ps.state_id
             WHERE ps.preset_id = ?
             ORDER BY ps.sort_order ASC, ps.id ASC',
            [$presetId],
        );
    }

    public function applyPreset(int $characterId, object $data): array
    {
        $assignmentId = $this->normalizeInt($data->assignment_id ?? 0, 0);
        $assignment = $this->findAssignmentById($assignmentId);
        if (empty($assignment) || (int) ($assignment->character_id ?? 0) !== $characterId) {
            $this->failValidation('Preset non assegnato a questo personaggio', 'preset_assignment_not_found');
        }
        if ((int) ($assignment->is_active ?? 0) !== 1 || (int) ($assignment->preset_is_active ?? 1) !== 1) {
            $this->failValidation('Preset non attivo', 'preset_inactive');
        }

        $steps = $this->loadPresetStepsForAssignment((int) ($assignment->preset_id ?? 0));
        if (empty($steps)) {
            $this->failValidation('Il preset non contiene stati configurati', 'preset_steps_missing');
        }

        $targetType = (string) ($assignment->target_type ?? 'character');
        $targetId = $characterId;
        $sceneId = 0;
        if ($targetType === 'scene') {
            $sceneId = $this->normalizeInt($data->scene_id ?? $data->location_id ?? 0, 0);
            $this->ensureSceneAccess($sceneId, $characterId);
            $targetId = $sceneId;
        }

        $results = [];
        $errors = [];
        foreach ($steps as $step) {
            $effectMode = (string) ($step->effect_mode ?? 'apply');
            try {
                if ($effectMode === 'remove') {
                    $result = $this->narrativeStateApplicationService()->removeState([
                        'state_id' => (int) ($step->state_id ?? 0),
                        'target_type' => $targetType,
                        'target_id' => $targetId,
                        'scene_id' => $sceneId,
                        'reason' => 'logeon_preset_remove',
                    ]);
                } else {
                    $result = $this->narrativeStateApplicationService()->applyState([
                        'state_id' => (int) ($step->state_id ?? 0),
                        'target_type' => $targetType,
                        'target_id' => $targetId,
                        'scene_id' => $sceneId,
                        'applier_character_id' => $characterId,
                        'intensity' => $step->intensity ?? null,
                        'duration_value' => $step->duration_value ?? null,
                        'duration_unit' => $step->duration_unit ?? null,
                        'meta_json' => json_encode([
                            'source' => 'logeon_narrative_preset',
                            'preset_id' => (int) ($assignment->preset_id ?? 0),
                            'preset_assignment_id' => $assignmentId,
                        ]),
                    ]);
                }

                $results[] = [
                    'state_id' => (int) ($step->state_id ?? 0),
                    'state_name' => (string) ($step->state_name ?? ''),
                    'effect_mode' => $effectMode,
                    'result' => $result,
                ];
            } catch (\Throwable $error) {
                $errors[] = [
                    'state_id' => (int) ($step->state_id ?? 0),
                    'state_name' => (string) ($step->state_name ?? ''),
                    'effect_mode' => $effectMode,
                    'message' => $error->getMessage(),
                ];
            }
        }

        if (empty($results) && !empty($errors)) {
            $this->failValidation('Applicazione preset fallita', 'preset_apply_failed');
        }

        $message = 'Preset applicato: ' . count($results) . ' step eseguiti';
        if (!empty($errors)) {
            $message .= ', ' . count($errors) . ' con errore';
        }

        return [
            'preset_id' => (int) ($assignment->preset_id ?? 0),
            'preset_name' => (string) ($assignment->preset_name ?? ''),
            'target_type' => $targetType,
            'target_id' => $targetId,
            'scene_id' => $sceneId,
            'steps_executed' => $results,
            'step_errors' => $errors,
            'message' => $message . '.',
        ];
    }
}
