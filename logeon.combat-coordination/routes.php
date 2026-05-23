<?php

declare(strict_types=1);

/** @var \Core\Router $route */

$ctrl = \Modules\Logeon\CombatCoordination\Controllers\CombatCoordination::class;

$route->group('/admin/combat-coordination', function ($route) use ($ctrl) {
    $route->apiPost('/bootstrap', $ctrl . '@adminBootstrap');
    $route->apiPost('/plan/save', $ctrl . '@adminPlanSave');
    $route->apiPost('/plan/cancel', $ctrl . '@adminPlanCancel');
});

$route->group('/combat-coordination', function ($route) use ($ctrl) {
    $route->apiPost('/plan/save', $ctrl . '@planSave');
    $route->apiPost('/plan/cancel', $ctrl . '@planCancel');
});
