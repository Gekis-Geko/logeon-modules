<?php

declare(strict_types=1);

namespace Modules\Logeon\AdvanceMaps\Controllers;

use Core\AuthGuard;
use Core\Http\ApiResponse;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LoggerInterface;
use Modules\Logeon\AdvanceMaps\Services\AdvanceMapsService;

class AdvanceMaps
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var AdvanceMapsService|null */
    private $service = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setService(AdvanceMapsService $service = null)
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

    private function service(): AdvanceMapsService
    {
        if ($this->service instanceof AdvanceMapsService) {
            return $this->service;
        }
        $this->service = new AdvanceMapsService();
        return $this->service;
    }

    protected function trace($message, $context = false): void
    {
        $this->logger()->trace($message, $context);
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

    private function isStaffRuntime(): bool
    {
        try {
            return \Core\AppContext::authContext()->isStaff();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function runtimeList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireCharacter();
        $data = $this->requestDataObject();
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : (object) [];

        $parentMapIdRaw = property_exists($query, 'parent_map_id') ? (int) ($query->parent_map_id ?? 0) : 0;
        $parentMapId = $parentMapIdRaw > 0 ? $parentMapIdRaw : null;
        $rootOnly = InputValidator::boolean($query, 'root_only', false);
        $idFilter = property_exists($query, 'id') ? (int) ($query->id ?? 0) : 0;
        $results = max(1, min(500, InputValidator::integer($data, 'results', 200)));
        $page = max(1, InputValidator::integer($data, 'page', 1));
        $orderBy = InputValidator::string($data, 'orderBy', 'position|ASC');

        $response = $this->service()->runtimeList(
            $parentMapId,
            $rootOnly,
            $idFilter > 0 ? $idFilter : null,
            $this->isStaffRuntime(),
            $results,
            $page,
            $orderBy,
        );

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function runtimeContext($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();
        $data = $this->requestDataObject();
        $mapId = InputValidator::integer($data, 'map_id', 0);
        $response = [
            'dataset' => $this->service()->runtimeContext($mapId, $characterId, $this->isStaffRuntime()),
        ];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminMapsList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : (object) [];

        $response = $this->service()->adminList(
            InputValidator::string($query, 'name', ''),
            InputValidator::string($query, 'render_mode', ''),
            InputValidator::string($query, 'initial', ''),
            InputValidator::string($query, 'mobile', ''),
            InputValidator::string($query, 'map_type', ''),
            InputValidator::string($query, 'is_visible', ''),
            max(1, min(500, InputValidator::integer($data, 'results', 20))),
            max(1, InputValidator::integer($data, 'page', 1)),
            InputValidator::string($data, 'orderBy', 'position|ASC'),
        );

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminMapGet($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject(false);
        $id = InputValidator::integer($data, 'id', 0);
        $response = [
            'dataset' => $this->service()->adminGet($id),
        ];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminMapSave($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $actorUserId = $this->requireAdmin();
        $data = $this->requestDataObject(false);
        $payload = (array) $data;
        $response = [
            'dataset' => $this->service()->adminSave($payload, $actorUserId),
            'success' => true,
        ];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminMapDelete($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject(false);
        $id = InputValidator::integer($data, 'id', 0);
        $this->service()->adminDelete($id);
        $response = ['success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminHotspotsList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject(false);
        $mapId = InputValidator::integer($data, 'map_id', 0);
        $response = $this->service()->adminHotspotsList($mapId);

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminHotspotSave($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject(false);
        $response = [
            'dataset' => $this->service()->adminHotspotSave((array) $data),
            'success' => true,
        ];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminHotspotDelete($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject(false);
        $id = InputValidator::integer($data, 'id', 0);
        $mapId = InputValidator::integer($data, 'map_id', 0);
        $this->service()->adminHotspotDelete($id, $mapId);
        $response = ['success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }
}

