<?php

declare(strict_types=1);

namespace Modules\Logeon\CombatAdminTools\Controllers;

use Core\AuthGuard;
use Core\Http\ApiResponse;
use Core\Http\ResponseEmitter;
use Core\Logging\LoggerInterface;
use Modules\Logeon\CombatAdminTools\Services\CombatAdminToolsService;

class CombatAdminTools
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var CombatAdminToolsService|null */
    private $service = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setService(CombatAdminToolsService $service = null)
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

    private function service(): CombatAdminToolsService
    {
        if ($this->service instanceof CombatAdminToolsService) {
            return $this->service;
        }

        $this->service = new CombatAdminToolsService();
        return $this->service;
    }

    private function requireAdmin(): void
    {
        AuthGuard::api()->requireAbility('settings.manage', [], 'Operazione non autorizzata');
    }

    private function emitJson(array $payload): void
    {
        ResponseEmitter::emit(ApiResponse::json($payload));
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
}
