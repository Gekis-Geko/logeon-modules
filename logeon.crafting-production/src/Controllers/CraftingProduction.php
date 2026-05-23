<?php

declare(strict_types=1);

namespace Modules\Logeon\CraftingProduction\Controllers;

use Core\AuthGuard;
use Core\Http\ApiResponse;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LoggerInterface;
use Modules\Logeon\CraftingProduction\Services\CraftingProductionService;

class CraftingProduction
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var CraftingProductionService|null */
    private $service = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setService(CraftingProductionService $service = null)
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

    private function service(): CraftingProductionService
    {
        if ($this->service instanceof CraftingProductionService) {
            return $this->service;
        }

        $this->service = new CraftingProductionService();
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

    private function requireCharacter(): int
    {
        return (int) AuthGuard::api()->requireCharacter();
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

    public function adminCharacterSearch($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject(false);
        $response = ['dataset' => $this->service()->adminSearchCharacters((string) ($data->query ?? ''))];

        if ($echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function adminScopeSearch($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject(false);
        $response = ['dataset' => $this->service()->adminSearchScopeReferences(
            (string) ($data->scope_type ?? ''),
            (string) ($data->query ?? ''),
        )];

        if ($echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function adminSaveProfession($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $userId = $this->requireAdmin();
        $response = [
            'dataset' => ['id' => $this->service()->saveProfession($this->requestDataObject(false), $userId)],
            'success' => true,
        ];

        if ($echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function adminDeleteProfession($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject(false);
        $this->service()->deleteProfession((int) ($data->id ?? 0));
        $response = ['success' => true];

        if ($echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function adminSaveProcess($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $userId = $this->requireAdmin();
        $response = [
            'dataset' => ['id' => $this->service()->saveProcess($this->requestDataObject(false), $userId)],
            'success' => true,
        ];

        if ($echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function adminDeleteProcess($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject(false);
        $this->service()->deleteProcess((int) ($data->id ?? 0));
        $response = ['success' => true];

        if ($echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function adminSaveSource($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $userId = $this->requireAdmin();
        $response = [
            'dataset' => ['id' => $this->service()->saveSource($this->requestDataObject(false), $userId)],
            'success' => true,
        ];

        if ($echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function adminDeleteSource($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject(false);
        $this->service()->deleteSource((int) ($data->id ?? 0));
        $response = ['success' => true];

        if ($echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function gameBootstrap($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $response = ['dataset' => $this->service()->gameBootstrap($this->requireCharacter())];

        if ($echo) {
            $this->emitJson($response);
        }

        return $response;
    }

    public function executeProcess($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();
        $data = $this->requestDataObject(false);
        $response = [
            'dataset' => $this->service()->executeProcess(
                (int) ($data->process_id ?? 0),
                $characterId,
                trim((string) ($data->station_type ?? '')),
            ),
            'success' => true,
        ];

        if ($echo) {
            $this->emitJson($response);
        }

        return $response;
    }
}
