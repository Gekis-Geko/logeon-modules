<?php

declare(strict_types=1);

/** @var \Core\Router $route */

$ctrl = \Modules\Logeon\ArchetypeAttributes\Controllers\ArchetypeAttributes::class;

$route->group('/admin/archetype-attributes', function ($route) use ($ctrl) {
    $route->apiPost('/meta', $ctrl . '@adminMeta');
    $route->apiPost('/rules/list', $ctrl . '@adminRulesList');
    $route->apiPost('/rules/upsert', $ctrl . '@adminRulesUpsert');
    $route->apiPost('/rules/delete', $ctrl . '@adminRulesDelete');
});

$route->group('/archetype-attributes', function ($route) use ($ctrl) {
    $route->apiPost('/character-create/bootstrap', $ctrl . '@characterCreateBootstrap');
    $route->apiPost('/character-create/rules', $ctrl . '@characterCreateRules');
    $route->apiPost('/profile/rules', $ctrl . '@profileRules');
    $route->apiPost('/profile/update-values', $ctrl . '@profileUpdateValues');
});
