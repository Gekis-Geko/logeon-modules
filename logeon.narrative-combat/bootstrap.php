<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Modules\\Logeon\\NarrativeCombat\\';
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

    \Core\Hooks::add('view.slot.game.location.conflicts.combat_pane', static function ($html) {
        $rendered = \Core\AppContext::templateRenderer()->render('app/location-conflicts-combat-pane.twig');
        return is_string($html) ? ($html . $rendered) : $rendered;
    });

    \Core\Hooks::add('twig.slot.admin.dashboard.narrative-combat', static function ($fragments) {
        if (!is_array($fragments)) {
            $fragments = [];
        }
        $fragments[] = [
            'id' => 'narrative-combat-admin-dashboard-page',
            'template' => 'admin/pages/narrative-combat.twig',
            'after' => '',
            'before' => '',
            'data' => [],
        ];
        return $fragments;
    });
};
