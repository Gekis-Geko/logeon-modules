<?php

declare(strict_types=1);

namespace Modules\Logeon\ArchetypeAttributes\Controllers;

use Core\AuthGuard;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LoggerInterface;
use Core\SessionStore;
use Modules\Logeon\ArchetypeAttributes\Services\ArchetypeAttributesService;

class ArchetypeAttributes
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var ArchetypeAttributesService|null */
    private $service = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setService(ArchetypeAttributesService $service = null)
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

    private function service(): ArchetypeAttributesService
    {
        if ($this->service instanceof ArchetypeAttributesService) {
            return $this->service;
        }

        $this->service = new ArchetypeAttributesService();
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

    private function requireAdmin(): void
    {
        AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');
    }

    private function requireUser(): int
    {
        return (int) AuthGuard::api()->requireUser();
    }

    private function requireStaffCharacter(): array
    {
        $guard = AuthGuard::api();
        $guard->requireUserCharacter();

        $isStaff = ((int) SessionStore::get('user_is_administrator') === 1)
            || ((int) SessionStore::get('user_is_moderator') === 1)
            || ((int) SessionStore::get('user_is_master') === 1);

        if (!$isStaff) {
            throw AppError::unauthorized('Operazione non autorizzata', [], 'attribute_update_forbidden');
        }

        return [
            'user_id' => (int) $guard->requireUser(),
            'character_id' => (int) $guard->requireCharacter(),
        ];
    }

    private function resolveTargetCharacterId(object $data, int $fallbackCharacterId): int
    {
        if (!empty($data->character_id)) {
            return (int) $data->character_id;
        }
        if (!empty($data->id)) {
            return (int) $data->id;
        }
        return $fallbackCharacterId;
    }

    public function adminMeta($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = ['dataset' => $this->service()->adminMeta()];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminRulesList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = ['dataset' => $this->service()->listRules()];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminRulesUpsert($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = ['dataset' => ['id' => $this->service()->upsertRule($this->requestDataObject(false))], 'success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminRulesDelete($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject(false);
        $this->service()->deleteRule((int) ($data->id ?? 0));
        $response = ['success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function characterCreateBootstrap($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireUser();
        $response = ['dataset' => $this->service()->characterCreateBootstrap()];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function characterCreateRules($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireUser();
        $data = $this->requestDataObject();
        $ids = [];
        if (isset($data->archetype_ids) && is_array($data->archetype_ids)) {
            $ids = $data->archetype_ids;
        } elseif (isset($data->archetype_id)) {
            $ids = [$data->archetype_id];
        }
        $response = ['dataset' => $this->service()->characterCreateRules($ids)];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function profileRules($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $session = $this->requireStaffCharacter();
        $data = $this->requestDataObject();
        $targetCharacterId = $this->resolveTargetCharacterId($data, (int) $session['character_id']);
        $response = ['dataset' => $this->service()->profileRules($targetCharacterId)];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function profileUpdateValues($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $session = $this->requireStaffCharacter();
        $data = $this->requestDataObject(false);
        $targetCharacterId = $this->resolveTargetCharacterId($data, (int) $session['character_id']);
        $values = isset($data->values) && is_array($data->values) ? $data->values : [];
        $response = ['dataset' => $this->service()->profileUpdateValues($targetCharacterId, $values)];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }
}
