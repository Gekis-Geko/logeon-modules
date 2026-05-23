<?php

declare(strict_types=1);

/** @var \Core\Router $route */

$ctrl = \Modules\Logeon\CombatAi\Controllers\CombatAi::class;

$route->group('/admin/combat-ai', function ($route) use ($ctrl) {
    $route->apiPost('/bootstrap', $ctrl . '@adminBootstrap');
    $route->apiPost('/profile/save', $ctrl . '@adminProfileSave');
    $route->apiPost('/profile/delete', $ctrl . '@adminProfileDelete');
});

$route->group('/combat-ai', function ($route) use ($ctrl) {
    $route->apiPost('/declare', $ctrl . '@declareSuggestedAction');
});
