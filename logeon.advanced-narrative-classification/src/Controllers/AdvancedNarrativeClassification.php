<?php

declare(strict_types=1);

namespace Modules\Logeon\AdvancedNarrativeClassification\Controllers;

use Core\AuthGuard;
use Core\Http\ApiResponse;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LoggerInterface;
use Modules\Logeon\AdvancedNarrativeClassification\Services\AdvancedNarrativeClassificationService;

class AdvancedNarrativeClassification
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var AdvancedNarrativeClassificationService|null */
    private $service = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setService(AdvancedNarrativeClassificationService $service = null)
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

    private function service(): AdvancedNarrativeClassificationService
    {
        if ($this->service instanceof AdvancedNarrativeClassificationService) {
            return $this->service;
        }

        $this->service = new AdvancedNarrativeClassificationService();
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

    public function adminTaxonomyUpsert($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = ['dataset' => ['id' => $this->service()->upsertTaxonomy($this->requestDataObject(false))], 'success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminTaxonomyDelete($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject(false);
        $this->service()->deleteTaxonomy((int) ($data->id ?? 0));
        $response = ['success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminNodeUpsert($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = ['dataset' => ['id' => $this->service()->upsertNode($this->requestDataObject(false))], 'success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminNodeDelete($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject(false);
        $this->service()->deleteNode((int) ($data->id ?? 0));
        $response = ['success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminNodeTagsSync($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject(false);
        $this->service()->syncNodeTags((int) ($data->node_id ?? 0), $data->tag_ids ?? []);
        $response = ['success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminAliasUpsert($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = ['dataset' => ['id' => $this->service()->upsertAlias($this->requestDataObject(false))], 'success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminAliasDelete($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject(false);
        $this->service()->deleteAlias((int) ($data->id ?? 0));
        $response = ['success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminDiscover($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = ['dataset' => $this->service()->discover((array) $this->requestDataObject())];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function gameBootstrap($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireCharacter();
        $response = ['dataset' => $this->service()->gameBootstrap()];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function gameSearch($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireCharacter();
        $data = $this->requestDataObject();
        $response = ['dataset' => $this->service()->searchCatalog((string) ($data->query ?? ''))];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function gameDiscover($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireCharacter();
        $response = ['dataset' => $this->service()->discover((array) $this->requestDataObject())];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function gameTagContext($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireCharacter();
        $data = $this->requestDataObject(false);
        $response = ['dataset' => $this->service()->tagContext((int) ($data->tag_id ?? 0))];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }
}
