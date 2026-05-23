<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Modules\\Logeon\\Economy\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $file = __DIR__ . '/src/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

return static function ($moduleRuntime = null, $moduleManifest = null): void {
    if (!class_exists('\\Core\\Hooks')) {
        return;
    }

    \Core\Hooks::add('twig.view_paths', static function ($paths) {
        if (!is_array($paths)) {
            $paths = [];
        }
        $viewPath = __DIR__ . '/views';
        if (!in_array($viewPath, $paths, true)) {
            $paths[] = $viewPath;
        }
        return $paths;
    });

    \Core\Hooks::add('twig.slot.admin.dashboard.economy-effects', static function ($fragments) {
        if (!is_array($fragments)) {
            $fragments = [];
        }
        $fragments[] = [
            'id' => 'economy-effects-admin-dashboard-page',
            'template' => 'admin/pages/economy-effects.twig',
            'after' => '',
            'before' => '',
            'data' => [],
        ];
        return $fragments;
    });

    \Core\Hooks::add('shop.catalog.item', static function ($payload, $db = null) {
        try {
            return (new \Modules\Logeon\Economy\Services\EconomyService($db))->filterCatalogItem($payload);
        } catch (\Throwable $error) {
            return $payload;
        }
    });

    \Core\Hooks::add('shop.purchase.resolve', static function ($payload, $db = null) {
        try {
            return (new \Modules\Logeon\Economy\Services\EconomyService($db))->resolvePurchasePayload($payload);
        } catch (\Throwable $error) {
            return $payload;
        }
    });

    \Core\Hooks::add('shop.sell.resolve', static function ($payload, $db = null) {
        try {
            return (new \Modules\Logeon\Economy\Services\EconomyService($db))->resolveSellPayload($payload);
        } catch (\Throwable $error) {
            return $payload;
        }
    });
};
