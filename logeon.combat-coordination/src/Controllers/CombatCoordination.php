<?php

declare(strict_types=1);

namespace Modules\Logeon\CombatCoordination\Controllers;

use Core\AuthGuard;
use Core\Http\ApiResponse;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LoggerInterface;
use Modules\Logeon\CombatCoordination\Services\CombatCoordinationService;

class CombatCoordination
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var CombatCoordinationService|null */
    private $service = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setService(CombatCoordinationService $service = null)
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

    private function service(): CombatCoordinationService
    {
        if ($this->service instanceof CombatCoordinationService) {
            return $this->service;
        }

        $this->service = new CombatCoordinationService();
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

    public function adminPlanSave($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $session = $this->requireUserCharacter(true);
        $response = [
            'dataset' => ['id' => $this->service()->savePlan($this->requestDataObject(false), (int) $session['character_id'], true)],
            'success' => true,
        ];

        if ($echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function adminPlanCancel($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $session = $this->requireUserCharacter(true);
        $data = $this->requestDataObject(false);
        $response = [
            'dataset' => $this->service()->cancelPlan((int) ($data->id ?? 0), (int) $session['character_id'], true),
            'success' => true,
        ];

        if ($echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function planSave($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $session = $this->requireUserCharacter();
        $response = [
            'dataset' => ['id' => $this->service()->savePlan($this->requestDataObject(false), (int) $session['character_id'], !empty($session['is_staff']))],
            'success' => true,
        ];

        if ($echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function planCancel($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $session = $this->requireUserCharacter();
        $data = $this->requestDataObject(false);
        $response = [
            'dataset' => $this->service()->cancelPlan((int) ($data->id ?? 0), (int) $session['character_id'], !empty($session['is_staff'])),
            'success' => true,
        ];

        if ($echo) {
            $this->emitJson($response);
        }

        return $response;
    }
}
