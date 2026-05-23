<?php

declare(strict_types=1);

namespace Modules\Logeon\AdvancedItems\Controllers;

use Core\Http\ApiResponse;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LoggerInterface;
use Modules\Logeon\AdvancedItems\Services\AdvancedItemsService;

class AdvancedItems
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var AdvancedItemsService|null */
    private $advancedItemsService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setAdvancedItemsService(AdvancedItemsService $advancedItemsService = null)
    {
        $this->advancedItemsService = $advancedItemsService;
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

    private function service(): AdvancedItemsService
    {
        if ($this->advancedItemsService instanceof AdvancedItemsService) {
            return $this->advancedItemsService;
        }

        $this->advancedItemsService = new AdvancedItemsService();
        return $this->advancedItemsService;
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
        $response = ['dataset' => $this->service()->listCharacterItems($characterId)];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function use($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();
        $response = ['dataset' => $this->service()->useAssignment($characterId, $this->requestDataObject())];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function restore($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();
        $response = ['dataset' => $this->service()->restoreAssignment($characterId, $this->requestDataObject())];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminProfilesList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = ['dataset' => $this->service()->adminListProfiles()];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminProfileCreate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = ['dataset' => ['id' => $this->service()->adminCreateProfile($this->requestDataObject())], 'success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminProfileUpdate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $this->service()->adminUpdateProfile($this->requestDataObject());
        $response = ['success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminProfileDelete($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $this->service()->adminDeleteProfile((int) ($data->id ?? 0));
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
        $response = ['dataset' => $this->service()->adminListAssignments((int) ($data->character_id ?? 0))];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminAssignmentCreate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = ['dataset' => ['id' => $this->service()->adminCreateAssignment($this->requestDataObject())], 'success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminAssignmentUpdate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $this->service()->adminUpdateAssignment($this->requestDataObject());
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
        $this->service()->adminDeleteAssignment((int) ($data->id ?? 0));
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
        $response = ['dataset' => $this->service()->adminSearchCharacters((string) ($data->query ?? ''))];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminItemsSearch($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $response = ['dataset' => $this->service()->adminSearchCoreItems((string) ($data->query ?? ''))];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }
}
