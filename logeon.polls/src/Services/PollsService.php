<?php

declare(strict_types=1);

namespace Modules\Logeon\Polls\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class PollsService
{
    private const STATUS_DRAFT = 'draft';
    private const STATUS_ACTIVE = 'active';
    private const STATUS_CLOSED = 'closed';

    private const VIS_PUBLIC = 'public';
    private const VIS_PLAYER_ONLY = 'player_only';
    private const VIS_STAFF_ONLY = 'staff_only';

    /** @var DbAdapterInterface */
    private $db;

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

    private function rowToArray($row): array
    {
        if (is_object($row)) {
            return (array) $row;
        }
        return is_array($row) ? $row : [];
    }

    private function normalizeText($value, int $max = 255): string
    {
        return mb_substr(trim((string) $value), 0, $max);
    }

    private function normalizeStatus($value): string
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_CLOSED], true)
            ? $value
            : self::STATUS_DRAFT;
    }

    private function normalizeVisibility($value): string
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, [self::VIS_PUBLIC, self::VIS_PLAYER_ONLY, self::VIS_STAFF_ONLY], true)
            ? $value
            : self::VIS_PLAYER_ONLY;
    }

    private function normalizeDateTime($value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            throw AppError::validation('Formato data/ora non valido.', [], 'poll_invalid_datetime');
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function assertPositiveId(int $id, string $message, string $code): void
    {
        if ($id <= 0) {
            throw AppError::validation($message, [], $code);
        }
    }

    private function normalizeOptions($raw): array
    {
        if (is_object($raw)) {
            $raw = (array) $raw;
        }
        if (!is_array($raw)) {
            $raw = [];
        }

        $out = [];
        foreach ($raw as $index => $item) {
            if (is_object($item)) {
                $item = (array) $item;
            }
            if (!is_array($item)) {
                $item = ['label' => $item];
            }
            $label = $this->normalizeText($item['label'] ?? '', 160);
            if ($label === '') {
                continue;
            }
            $out[] = [
                'label' => $label,
                'order_index' => count($out) + 1,
            ];
        }

        if (count($out) < 2 || count($out) > 5) {
            throw AppError::validation('Ogni sondaggio deve avere da 2 a 5 opzioni.', [], 'poll_invalid_options_count');
        }

        return $out;
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

    private function getPollRow(int $pollId)
    {
        return $this->firstPrepared(
            'SELECT p.*
             FROM polls p
             WHERE p.id = ?
             LIMIT 1',
            [$pollId],
        );
    }

    private function getPoll(int $pollId): array
    {
        $row = $this->getPollRow($pollId);
        if (empty($row)) {
            throw AppError::notFound('Sondaggio non trovato.', [], 'poll_not_found');
        }
        return $this->rowToArray($row);
    }

    private function listOptions(int $pollId): array
    {
        $rows = $this->fetchPrepared(
            'SELECT id, poll_id, label, order_index
             FROM poll_options
             WHERE poll_id = ?
             ORDER BY order_index ASC, id ASC',
            [$pollId],
        );

        $out = [];
        foreach ($rows as $row) {
            $item = $this->rowToArray($row);
            $item['id'] = (int) ($item['id'] ?? 0);
            $item['poll_id'] = (int) ($item['poll_id'] ?? 0);
            $item['order_index'] = (int) ($item['order_index'] ?? 0);
            $out[] = $item;
        }
        return $out;
    }

    private function voteCountMap(int $pollId): array
    {
        $rows = $this->fetchPrepared(
            'SELECT option_id, COUNT(*) AS n
             FROM poll_votes
             WHERE poll_id = ?
             GROUP BY option_id',
            [$pollId],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) ($row->option_id ?? 0)] = (int) ($row->n ?? 0);
        }
        return $out;
    }

    private function totalVotes(int $pollId): int
    {
        $row = $this->firstPrepared(
            'SELECT COUNT(*) AS n
             FROM poll_votes
             WHERE poll_id = ?',
            [$pollId],
        );
        return (int) ($row->n ?? 0);
    }

    private function pollHasVotes(int $pollId): bool
    {
        return $this->totalVotes($pollId) > 0;
    }

    private function userVoteOptionId(int $pollId, int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $row = $this->firstPrepared(
            'SELECT option_id
             FROM poll_votes
             WHERE poll_id = ?
               AND voter_user_id = ?
             LIMIT 1',
            [$pollId, $userId],
        );

        return (int) ($row->option_id ?? 0);
    }

    private function visibilityAllowed(string $visibility, bool $isStaff): bool
    {
        if ($visibility === self::VIS_STAFF_ONLY) {
            return $isStaff;
        }

        return in_array($visibility, [self::VIS_PUBLIC, self::VIS_PLAYER_ONLY], true);
    }

    private function derivedStatus(array $poll): string
    {
        $status = $this->normalizeStatus($poll['status'] ?? self::STATUS_DRAFT);
        if ($status === self::STATUS_CLOSED || $status === self::STATUS_DRAFT) {
            return $status;
        }

        $now = $this->now();
        $opensAt = trim((string) ($poll['opens_at'] ?? ''));
        $closesAt = trim((string) ($poll['closes_at'] ?? ''));

        if ($opensAt !== '' && $opensAt > $now) {
            return self::STATUS_DRAFT;
        }
        if ($closesAt !== '' && $closesAt <= $now) {
            return self::STATUS_CLOSED;
        }

        return self::STATUS_ACTIVE;
    }

    private function isVoteOpen(array $poll): bool
    {
        return $this->derivedStatus($poll) === self::STATUS_ACTIVE;
    }

    private function playerCanSeeResults(array $poll): bool
    {
        return $this->derivedStatus($poll) === self::STATUS_CLOSED;
    }

    private function compareOptionsForLockedPoll(array $existingOptions, array $incomingOptions): bool
    {
        if (count($existingOptions) !== count($incomingOptions)) {
            return false;
        }

        foreach ($existingOptions as $index => $existingOption) {
            $incomingOption = $incomingOptions[$index] ?? null;
            if (!is_array($incomingOption)) {
                return false;
            }

            $existingLabel = $this->normalizeText($existingOption['label'] ?? '', 160);
            $incomingLabel = $this->normalizeText($incomingOption['label'] ?? '', 160);
            if ($existingLabel !== $incomingLabel) {
                return false;
            }
        }

        return true;
    }

    private function syncPollOptions(int $pollId, array $options, bool $preserveVotes): void
    {
        if (!$preserveVotes) {
            $this->execPrepared('DELETE FROM poll_options WHERE poll_id = ?', [$pollId]);

            foreach ($options as $option) {
                $this->execPrepared(
                    'INSERT INTO poll_options
                        (poll_id, label, order_index, date_created)
                     VALUES (?, ?, ?, NOW())',
                    [
                        $pollId,
                        (string) $option['label'],
                        (int) $option['order_index'],
                    ],
                );
            }

            return;
        }

        $existingOptions = $this->listOptions($pollId);
        if (!$this->compareOptionsForLockedPoll($existingOptions, $options)) {
            throw AppError::validation(
                'Non puoi modificare le opzioni di un sondaggio che ha gia ricevuto voti.',
                [],
                'poll_locked_options',
            );
        }

        foreach ($existingOptions as $index => $existingOption) {
            $incomingOption = $options[$index] ?? null;
            if (!is_array($incomingOption)) {
                continue;
            }

            $this->execPrepared(
                'UPDATE poll_options
                 SET label = ?,
                     order_index = ?
                 WHERE id = ?
                   AND poll_id = ?
                 LIMIT 1',
                [
                    (string) $incomingOption['label'],
                    (int) $incomingOption['order_index'],
                    (int) ($existingOption['id'] ?? 0),
                    $pollId,
                ],
            );
        }
    }

    private function enrichPoll(array $poll, int $userId = 0, bool $includeResults = false): array
    {
        $poll['id'] = (int) ($poll['id'] ?? 0);
        $poll['created_by'] = (int) ($poll['created_by'] ?? 0);
        $poll['options'] = $this->listOptions((int) $poll['id']);
        $poll['derived_status'] = $this->derivedStatus($poll);
        $poll['is_vote_open'] = $this->isVoteOpen($poll) ? 1 : 0;
        $poll['user_vote_option_id'] = $this->userVoteOptionId((int) $poll['id'], $userId);
        $poll['has_voted'] = $poll['user_vote_option_id'] > 0 ? 1 : 0;

        if ($includeResults) {
            $results = $this->buildResultsData((int) $poll['id']);
            $poll['results'] = $results['options'];
            $poll['results_total_votes'] = $results['total_votes'];
        }

        return $poll;
    }

    private function buildResultsData(int $pollId): array
    {
        $options = $this->listOptions($pollId);
        $countMap = $this->voteCountMap($pollId);
        $totalVotes = 0;
        foreach ($countMap as $count) {
            $totalVotes += (int) $count;
        }

        foreach ($options as &$option) {
            $votes = (int) ($countMap[(int) $option['id']] ?? 0);
            $option['votes'] = $votes;
            $option['percentage'] = $totalVotes > 0
                ? round(($votes / $totalVotes) * 100, 2)
                : 0.0;
        }
        unset($option);

        return [
            'options' => $options,
            'total_votes' => $totalVotes,
        ];
    }

    public function adminBootstrap(): array
    {
        $rows = $this->fetchPrepared(
            'SELECT *
             FROM polls
             ORDER BY date_created DESC, id DESC',
        );

        $dataset = [];
        foreach ($rows as $row) {
            $dataset[] = $this->enrichPoll($this->rowToArray($row), 0, true);
        }

        return [
            'summary' => [
                'total' => count($dataset),
                'draft' => count(array_filter($dataset, static function (array $row): bool {
                    return ($row['derived_status'] ?? '') === self::STATUS_DRAFT;
                })),
                'active' => count(array_filter($dataset, static function (array $row): bool {
                    return ($row['derived_status'] ?? '') === self::STATUS_ACTIVE;
                })),
                'closed' => count(array_filter($dataset, static function (array $row): bool {
                    return ($row['derived_status'] ?? '') === self::STATUS_CLOSED;
                })),
            ],
            'polls' => $dataset,
        ];
    }

    public function savePoll(object $data, int $userId): int
    {
        $id = (int) ($data->id ?? 0);
        $title = $this->normalizeText($data->title ?? '', 160);
        $description = $this->normalizeText($data->description ?? '', 5000);
        $status = $this->normalizeStatus($data->status ?? self::STATUS_DRAFT);
        $visibility = $this->normalizeVisibility($data->visibility ?? self::VIS_PLAYER_ONLY);
        $opensAt = $this->normalizeDateTime($data->opens_at ?? null);
        $closesAt = $this->normalizeDateTime($data->closes_at ?? null);
        $options = $this->normalizeOptions($data->options ?? []);

        if ($title === '') {
            throw AppError::validation('Titolo sondaggio obbligatorio.', [], 'poll_title_required');
        }
        if ($opensAt !== null && $closesAt !== null && $closesAt <= $opensAt) {
            throw AppError::validation('La chiusura deve essere successiva all\'apertura.', [], 'poll_invalid_schedule');
        }

        $this->begin();
        try {
            if ($id > 0) {
                $this->getPoll($id);
                $this->execPrepared(
                    'UPDATE polls
                     SET title = ?,
                         description = ?,
                         status = ?,
                         visibility = ?,
                         opens_at = ?,
                         closes_at = ?,
                         date_updated = NOW()
                     WHERE id = ?
                     LIMIT 1',
                    [
                        $title,
                        $description !== '' ? $description : null,
                        $status,
                        $visibility,
                        $opensAt,
                        $closesAt,
                        $id,
                    ],
                );
                $pollId = $id;
            } else {
                $this->execPrepared(
                    'INSERT INTO polls
                        (title, description, status, visibility, created_by, opens_at, closes_at, date_created)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                    [
                        $title,
                        $description !== '' ? $description : null,
                        $status,
                        $visibility,
                        $userId > 0 ? $userId : null,
                        $opensAt,
                        $closesAt,
                    ],
                );
                $pollId = (int) $this->db->lastInsertId();
            }

            $this->syncPollOptions($pollId, $options, $id > 0 && $this->pollHasVotes($pollId));

            $this->commit();
            return $pollId;
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function deletePoll(int $pollId): void
    {
        $this->assertPositiveId($pollId, 'Sondaggio non valido.', 'poll_id_required');
        $this->getPoll($pollId);
        $this->execPrepared('DELETE FROM polls WHERE id = ? LIMIT 1', [$pollId]);
    }

    public function adminResults(int $pollId): array
    {
        $this->assertPositiveId($pollId, 'Sondaggio non valido.', 'poll_id_required');
        $poll = $this->getPoll($pollId);
        $poll = $this->enrichPoll($poll, 0, true);
        $poll['votes_total'] = $this->totalVotes($pollId);
        return $poll;
    }

    private function listGameVisiblePolls(bool $isStaff): array
    {
        $rows = $this->fetchPrepared(
            'SELECT *
             FROM polls
             WHERE status <> ?
             ORDER BY COALESCE(opens_at, date_created) DESC, id DESC',
            [self::STATUS_DRAFT],
        );

        $out = [];
        foreach ($rows as $row) {
            $poll = $this->rowToArray($row);
            if (!$this->visibilityAllowed((string) ($poll['visibility'] ?? ''), $isStaff)) {
                continue;
            }
            $derived = $this->derivedStatus($poll);
            if ($derived === self::STATUS_DRAFT) {
                continue;
            }
            $out[] = $poll;
        }
        return $out;
    }

    public function gameBootstrap(int $userId, bool $isStaff): array
    {
        $rows = $this->listGameVisiblePolls($isStaff);
        $active = [];
        $closed = [];

        foreach ($rows as $row) {
            $derived = $this->derivedStatus($row);
            $includeResults = $derived === self::STATUS_CLOSED;
            $poll = $this->enrichPoll($row, $userId, $includeResults);

            if ($derived === self::STATUS_ACTIVE) {
                $active[] = $poll;
            } elseif ($derived === self::STATUS_CLOSED) {
                $closed[] = $poll;
            }
        }

        return [
            'summary' => [
                'active' => count($active),
                'closed' => count($closed),
            ],
            'active_polls' => $active,
            'closed_polls' => $closed,
        ];
    }

    public function submitVote(int $pollId, int $optionId, int $userId, bool $isStaff): array
    {
        $this->assertPositiveId($pollId, 'Sondaggio non valido.', 'poll_id_required');
        $this->assertPositiveId($optionId, 'Opzione non valida.', 'poll_option_required');
        $this->assertPositiveId($userId, 'Utente non valido.', 'poll_user_required');

        $poll = $this->getPoll($pollId);
        if (!$this->visibilityAllowed((string) ($poll['visibility'] ?? ''), $isStaff)) {
            throw AppError::unauthorized('Sondaggio non accessibile.', [], 'poll_not_visible');
        }
        if (!$this->isVoteOpen($poll)) {
            throw AppError::validation('Il sondaggio non è aperto al voto.', [], 'poll_not_open');
        }

        $optionRow = $this->firstPrepared(
            'SELECT id
             FROM poll_options
             WHERE id = ?
               AND poll_id = ?
             LIMIT 1',
            [$optionId, $pollId],
        );
        if (empty($optionRow)) {
            throw AppError::validation('Opzione non valida per il sondaggio selezionato.', [], 'poll_option_not_found');
        }

        $existingVote = $this->userVoteOptionId($pollId, $userId);
        if ($existingVote > 0) {
            throw AppError::validation('Hai già votato in questo sondaggio.', [], 'poll_duplicate_vote');
        }

        $this->execPrepared(
            'INSERT INTO poll_votes
                (poll_id, option_id, voter_user_id, date_created)
             VALUES (?, ?, ?, NOW())',
            [$pollId, $optionId, $userId],
        );

        $updated = $this->enrichPoll($poll, $userId, false);
        return [
            'poll_id' => $pollId,
            'option_id' => $optionId,
            'has_voted' => 1,
            'poll' => $updated,
        ];
    }

    public function gameResults(int $pollId, int $userId, bool $isStaff): array
    {
        $this->assertPositiveId($pollId, 'Sondaggio non valido.', 'poll_id_required');
        $poll = $this->getPoll($pollId);
        if (!$this->visibilityAllowed((string) ($poll['visibility'] ?? ''), $isStaff)) {
            throw AppError::unauthorized('Sondaggio non accessibile.', [], 'poll_not_visible');
        }
        if (!$this->playerCanSeeResults($poll)) {
            throw AppError::validation('I risultati non sono ancora visibili.', [], 'poll_results_not_visible');
        }

        return $this->enrichPoll($poll, $userId, true);
    }
}
