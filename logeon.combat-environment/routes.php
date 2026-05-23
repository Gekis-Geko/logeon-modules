<?php

declare(strict_types=1);

/** @var \Core\Router $route */

$ctrl = \Modules\Logeon\CombatEnvironment\Controllers\CombatEnvironment::class;

$route->group('/admin/combat-environment', function ($route) use ($ctrl) {
    $route->apiPost('/bootstrap', $ctrl . '@adminBootstrap');
    $route->apiPost('/settings/update', $ctrl . '@adminSettingsUpdate');
    $route->apiPost('/feature/save', $ctrl . '@adminFeatureSave');
    $route->apiPost('/feature/delete', $ctrl . '@adminFeatureDelete');
});

$route->group('/combat-environment', function ($route) use ($ctrl) {
    $route->apiPost('/interact', $ctrl . '@interact');
});
