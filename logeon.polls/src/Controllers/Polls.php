<?php

declare(strict_types=1);

namespace Modules\Logeon\Polls\Controllers;

use Core\AuthGuard;
use Core\Http\ApiResponse;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LoggerInterface;
use Core\SessionStore;
use Modules\Logeon\Polls\Services\PollsService;

class Polls
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var PollsService|null */
    private $service = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setService(PollsService $service = null)
    {
        $this->service = $service;
        return $this;
    }

    private function logger(): LoggerInterface
    {
        if ($this->logger instanceof LoggerInterface) {
            return $this->logger;
        }

        $this->logger = \Core\AppContext::logger();
        return $this->logger;
    }

    protected function trace($message, $context = false): void
    {
        $this->logger()->trace($message, $context);
    }

    private function service(): PollsService
    {
        if ($this->service instanceof PollsService) {
            return $this->service;
        }

        $this->service = new PollsService();
        return $this->service;
    }

    private function requestDataObject(bool $allowEmpty = true)
    {
        $request = RequestData::fromGlobals();
        return InputValidator::postJsonObject($request, 'data', $allowEmpty);
    }

    private function emitJson(array $payload): void
    {
        ResponseEmitter::emit(ApiResponse::json($payload));
    }

    private function requireAdmin(): int
    {
        AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');
        return (int) AuthGuard::api()->requireUser();
    }

    private function requireCharacterContext(): array
    {
        $guard = AuthGuard::api();
        return [
            'user_id' => (int) $guard->requireUser(),
            'character_id' => (int) $guard->requireCharacter(),
            'is_staff' => (
                (int) SessionStore::get('user_is_administrator') === 1
                || (int) SessionStore::get('user_is_moderator') === 1
                || (int) SessionStore::get('user_is_master') === 1
            ),
        ];
    }

    public function adminBootstrap($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = ['dataset' => $this->service()->adminBootstrap()];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminSave($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $userId = $this->requireAdmin();
        $response = ['dataset' => ['id' => $this->service()->savePoll($this->requestDataObject(false), $userId)], 'success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminDelete($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject(false);
        $this->service()->deletePoll((int) ($data->id ?? 0));
        $response = ['success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminResults($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject(false);
        $response = ['dataset' => $this->service()->adminResults((int) ($data->poll_id ?? $data->id ?? 0))];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function gameBootstrap($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $context = $this->requireCharacterContext();
        $response = ['dataset' => $this->service()->gameBootstrap((int) $context['user_id'], (bool) $context['is_staff'])];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function vote($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $context = $this->requireCharacterContext();
        $data = $this->requestDataObject(false);
        $response = [
            'dataset' => $this->service()->submitVote(
                (int) ($data->poll_id ?? 0),
                (int) ($data->option_id ?? 0),
                (int) $context['user_id'],
                (bool) $context['is_staff'],
            ),
            'success' => true,
        ];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function gameResults($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $context = $this->requireCharacterContext();
        $data = $this->requestDataObject(false);
        $response = [
            'dataset' => $this->service()->gameResults(
                (int) ($data->poll_id ?? $data->id ?? 0),
                (int) $context['user_id'],
                (bool) $context['is_staff'],
            ),
        ];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }
}
