<?php

declare(strict_types=1);

namespace Modules\Logeon\Economy\Controllers;

use Core\AuthGuard;
use Core\Http\ApiResponse;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LoggerInterface;
use Modules\Logeon\Economy\Services\EconomyService;

class Economy
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var EconomyService|null */
    private $service = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setService(EconomyService $service = null)
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

    private function service(): EconomyService
    {
        if ($this->service instanceof EconomyService) {
            return $this->service;
        }

        $this->service = new EconomyService();
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
        $response = [
            'dataset' => ['id' => $this->service()->saveEffect($this->requestDataObject(false), $userId)],
            'success' => true,
        ];

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
        $this->service()->deleteEffect((int) ($data->id ?? 0));
        $response = ['success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminPreview($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = ['dataset' => $this->service()->previewEffectDraft($this->requestDataObject(false))];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }
}
