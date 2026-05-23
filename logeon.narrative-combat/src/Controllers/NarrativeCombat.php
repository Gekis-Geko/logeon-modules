<?php

declare(strict_types=1);

namespace Modules\Logeon\NarrativeCombat\Controllers;

use Core\AuthGuard;
use Core\Http\ApiResponse;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LoggerInterface;
use Modules\Logeon\NarrativeCombat\Services\NarrativeCombatService;

class NarrativeCombat
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var NarrativeCombatService|null */
    private $service = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setService(NarrativeCombatService $service = null)
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

    private function service(): NarrativeCombatService
    {
        if ($this->service instanceof NarrativeCombatService) {
            return $this->service;
        }

        $this->service = new NarrativeCombatService();
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

    /**
     * @return array{user_id:int, character_id:int, is_staff:bool}
     */
    private function requireUserCharacter(bool $allowStaffWithoutCharacter = false): array
    {
        $guard = AuthGuard::api();
        $userId = (int) $guard->requireUser();
        $isStaff = \Core\AppContext::authContext()->isStaff();
        $characterId = (int) \Core\AppContext::session()->get('character_id');
        if ($characterId <= 0 && !($allowStaffWithoutCharacter && $isStaff)) {
            $characterId = (int) $guard->requireCharacter();
        }

        return [
            'user_id' => $userId,
            'character_id' => $characterId,
            'is_staff' => $isStaff,
        ];
    }

    public function taxonomy($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireUserCharacter(true);
        $response = ['dataset' => $this->service()->taxonomy()];

        if ($echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function adminSettingsBootstrap($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = ['dataset' => $this->service()->adminSettingsBootstrap()];

        if ($echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function adminSettingsUpdate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = [
            'dataset' => $this->service()->updateAdminSettings($this->requestDataObject(false)),
            'success' => true,
        ];

        if ($echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function state($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $session = $this->requireUserCharacter(true);
        $data = $this->requestDataObject(false);
        $response = [
            'dataset' => $this->service()->getState(
                (int) ($data->conflict_id ?? 0),
                (int) $session['character_id'],
                !empty($session['is_staff']),
            ),
        ];

        if ($echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function start($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $session = $this->requireUserCharacter();
        $data = $this->requestDataObject(false);
        $response = [
            'dataset' => $this->service()->startCombatContext(
                (int) ($data->conflict_id ?? 0),
                (int) $session['character_id'],
                !empty($session['is_staff']),
            ),
            'success' => true,
        ];

        if ($echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function participantsSync($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $session = $this->requireUserCharacter();
        $data = $this->requestDataObject(false);
        $response = [
            'dataset' => $this->service()->syncParticipants(
                (int) ($data->conflict_id ?? 0),
                (int) $session['character_id'],
                !empty($session['is_staff']),
            ),
            'success' => true,
        ];

        if ($echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function actionDeclare($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $session = $this->requireUserCharacter();
        $data = $this->requestDataObject(false);
        $response = [
            'dataset' => $this->service()->declareAction(
                $data,
                (int) $session['character_id'],
                !empty($session['is_staff']),
            ),
            'success' => true,
        ];

        if ($echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function groupGuard($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $session = $this->requireUserCharacter();
        $data = $this->requestDataObject(false);
        $response = [
            'dataset' => $this->service()->addGuardRelation(
                $data,
                (int) $session['character_id'],
                !empty($session['is_staff']),
            ),
            'success' => true,
        ];

        if ($echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function groupUnguard($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $session = $this->requireUserCharacter();
        $data = $this->requestDataObject(false);
        $response = [
            'dataset' => $this->service()->removeGuardRelation(
                $data,
                (int) $session['character_id'],
                !empty($session['is_staff']),
            ),
            'success' => true,
        ];

        if ($echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function environmentSet($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $session = $this->requireUserCharacter();
        $data = $this->requestDataObject(false);
        $response = [
            'dataset' => $this->service()->saveEnvironment(
                $data,
                (int) $session['character_id'],
                !empty($session['is_staff']),
            ),
            'success' => true,
        ];

        if ($echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function actionResolve($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $session = $this->requireUserCharacter(true);
        $data = $this->requestDataObject(false);
        $response = [
            'dataset' => $this->service()->resolveAction(
                (int) ($data->action_intent_id ?? 0),
                (int) $session['character_id'],
                !empty($session['is_staff']),
            ),
            'success' => true,
        ];

        if ($echo) {
            $this->emitJson($response);
        }

        return $response;
    }
}
