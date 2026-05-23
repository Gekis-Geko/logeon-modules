<?php

declare(strict_types=1);

namespace Modules\Logeon\NarrativeStates\Controllers;

use Core\Http\ApiResponse;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LoggerInterface;
use Modules\Logeon\NarrativeStates\Services\NarrativePresetService;

class NarrativeStatesPreset
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var NarrativePresetService|null */
    private $narrativePresetService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setNarrativePresetService(NarrativePresetService $narrativePresetService = null)
    {
        $this->narrativePresetService = $narrativePresetService;
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

    private function service(): NarrativePresetService
    {
        if ($this->narrativePresetService instanceof NarrativePresetService) {
            return $this->narrativePresetService;
        }

        $this->narrativePresetService = new NarrativePresetService();
        return $this->narrativePresetService;
    }

    private function requestDataObject()
    {
        $request = RequestData::fromGlobals();
        return InputValidator::postJsonObject($request, 'data', true);
    }

    private function emitJson(array $payload): void
    {
        ResponseEmitter::emit(ApiResponse::json($payload));
    }

    private function requireCharacter(): int
    {
        return (int) \Core\AuthGuard::api()->requireCharacter();
    }

    private function requireAdmin(): void
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage');
    }

    public function my($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();
        $response = ['dataset' => $this->service()->listCharacterPresets($characterId)];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function applyPreset($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();
        $response = ['dataset' => $this->service()->applyPreset($characterId, $this->requestDataObject())];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminStatesCatalog($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = ['dataset' => $this->service()->adminStatesCatalog()];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminPresetsList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = ['dataset' => $this->service()->adminPresetsList()];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminPresetCreate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = ['dataset' => ['id' => $this->service()->adminPresetCreate($this->requestDataObject())], 'success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminPresetUpdate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $this->service()->adminPresetUpdate($this->requestDataObject());
        $response = ['success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminPresetDelete($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $this->service()->adminPresetDelete((int) ($data->id ?? 0));
        $response = ['success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminPresetStatesList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $presetId = (int) ($data->preset_id ?? 0);
        $response = ['dataset' => $this->service()->adminPresetStatesList($presetId)];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminPresetStateCreate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = ['dataset' => ['id' => $this->service()->adminPresetStateCreate($this->requestDataObject())], 'success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminPresetStateUpdate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $this->service()->adminPresetStateUpdate($this->requestDataObject());
        $response = ['success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminPresetStateDelete($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $this->service()->adminPresetStateDelete((int) ($data->id ?? 0));
        $response = ['success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminAssignmentsList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $response = ['dataset' => $this->service()->adminAssignmentsList((int) ($data->character_id ?? 0))];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminAssignmentCreate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = ['dataset' => ['id' => $this->service()->adminAssignmentCreate($this->requestDataObject())], 'success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminAssignmentUpdate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $this->service()->adminAssignmentUpdate($this->requestDataObject());
        $response = ['success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminAssignmentDelete($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $this->service()->adminAssignmentDelete((int) ($data->id ?? 0));
        $response = ['success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminCharactersSearch($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $response = ['dataset' => $this->service()->adminCharactersSearch((string) ($data->query ?? ''))];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }
}
