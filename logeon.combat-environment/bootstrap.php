<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Modules\\Logeon\\CombatEnvironment\\';
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

    \Core\Hooks::add('twig.slot.admin.dashboard.combat-environment', static function ($fragments) {
        if (!is_array($fragments)) {
            $fragments = [];
        }
        $fragments[] = [
            'id' => 'combat-environment-admin-dashboard-page',
            'template' => 'admin/pages/combat-environment.twig',
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
            'id' => 'combat-environment-panel',
            'template' => 'app/partials/combat-environment-pane.twig',
            'after' => '',
            'before' => '',
            'data' => [],
        ];

        return $fragments;
    });

    \Core\Hooks::add('combat.state.payload', static function ($payload, $conflictId = 0, $viewerCharacterId = 0, $isStaff = false, $db = null) {
        try {
            $payload['tier3_environment'] = (new \Modules\Logeon\CombatEnvironment\Services\CombatEnvironmentService($db))
                ->buildStateAddon((array) $payload, (int) $conflictId, (int) $viewerCharacterId, (bool) $isStaff);
        } catch (\Throwable $error) {
            $payload['tier3_environment'] = [
                'enabled' => false,
                'message' => 'Layer ambiente avanzato non disponibile.',
            ];
        }

        return $payload;
    });

    \Core\Hooks::add('combat.resolve.scores', static function ($payload, $intent = [], $actorState = [], $targetState = null, $environmentRaw = null, $db = null) {
        try {
            return (new \Modules\Logeon\CombatEnvironment\Services\CombatEnvironmentService($db))
                ->applyScoreModifiers(
                    is_array($payload) ? $payload : [],
                    is_array($intent) ? $intent : [],
                    is_array($actorState) ? $actorState : [],
                    is_array($targetState) ? $targetState : null,
                    is_array($environmentRaw) ? $environmentRaw : null,
                );
        } catch (\Throwable $error) {
            return $payload;
        }
    });
};
