<?php

declare(strict_types=1);

namespace Modules\Logeon\CombatEnvironment\Controllers;

use Core\AuthGuard;
use Core\Http\ApiResponse;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LoggerInterface;
use Modules\Logeon\CombatEnvironment\Services\CombatEnvironmentService;

class CombatEnvironment
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var CombatEnvironmentService|null */
    private $service = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setService(CombatEnvironmentService $service = null)
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

    private function service(): CombatEnvironmentService
    {
        if ($this->service instanceof CombatEnvironmentService) {
            return $this->service;
        }

        $this->service = new CombatEnvironmentService();
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
     * @return array{user_id:int,character_id:int,is_staff:bool}
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

    public function adminSettingsUpdate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = [
            'dataset' => $this->service()->updateSettings($this->requestDataObject(false)),
            'success' => true,
        ];

        if ($echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function adminFeatureSave($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $userId = $this->requireAdmin();
        $response = [
            'dataset' => ['id' => $this->service()->saveFeature($this->requestDataObject(false), $userId)],
            'success' => true,
        ];

        if ($echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function adminFeatureDelete($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject(false);
        $this->service()->deleteFeature((int) ($data->id ?? 0));
        $response = ['success' => true];

        if ($echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function interact($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $session = $this->requireUserCharacter();
        $response = [
            'dataset' => $this->service()->interact(
                $this->requestDataObject(false),
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
