<?php

declare(strict_types=1);

/** @var \Core\Router $route */

$ctrl = \Modules\Logeon\NarrativeCombat\Controllers\NarrativeCombat::class;

$route->group('/admin/narrative-combat/settings', function ($route) use ($ctrl) {
    $route->apiPost('/bootstrap', $ctrl . '@adminSettingsBootstrap');
    $route->apiPost('/update', $ctrl . '@adminSettingsUpdate');
});

$route->group('/combat', function ($route) use ($ctrl) {
    $route->apiPost('/taxonomy', $ctrl . '@taxonomy');
    $route->apiPost('/state', $ctrl . '@state');
    $route->apiPost('/start', $ctrl . '@start');
    $route->apiPost('/participants/sync', $ctrl . '@participantsSync');
    $route->apiPost('/group/guard', $ctrl . '@groupGuard');
    $route->apiPost('/group/unguard', $ctrl . '@groupUnguard');
    $route->apiPost('/env/set', $ctrl . '@environmentSet');
    $route->apiPost('/action/declare', $ctrl . '@actionDeclare');
    $route->apiPost('/action/resolve', $ctrl . '@actionResolve');
});
