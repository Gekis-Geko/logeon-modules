<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Modules\\Logeon\\CombatAdminTools\\';
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

    \Core\Hooks::add('twig.slot.admin.dashboard.combat-admin-tools', static function ($fragments) {
        if (!is_array($fragments)) {
            $fragments = [];
        }
        $fragments[] = [
            'id' => 'combat-admin-tools-admin-dashboard-page',
            'template' => 'admin/pages/combat-admin-tools.twig',
            'after' => '',
            'before' => '',
            'data' => [],
        ];
        return $fragments;
    });

    \Core\Hooks::add('twig.slot.game.location.conflicts.combat.tier3.after', static function ($fragments) {
        if (!is_array($fragments)) {
            $fragments = [];
        }

        $fragments[] = [
            'id' => 'combat-admin-tools-panel',
            'template' => 'app/partials/combat-admin-tools-pane.twig',
            'after' => 'combat-coordination-panel',
            'before' => '',
            'data' => [],
        ];

        return $fragments;
    });

    \Core\Hooks::add('combat.state.payload', static function ($payload, $conflictId = 0, $viewerCharacterId = 0, $isStaff = false, $db = null) {
        try {
            $payload['tier3_admin_tools'] = (new \Modules\Logeon\CombatAdminTools\Services\CombatAdminToolsService($db))
                ->buildStateAddon((array) $payload, (int) $conflictId, (int) $viewerCharacterId, (bool) $isStaff);
        } catch (\Throwable $error) {
            $payload['tier3_admin_tools'] = [
                'enabled' => false,
                'message' => 'Diagnostica staff non disponibile.',
            ];
        }

        return $payload;
    });
};
