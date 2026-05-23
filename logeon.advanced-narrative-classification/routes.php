<?php

declare(strict_types=1);

/** @var \Core\Router $route */

$ctrl = \Modules\Logeon\AdvancedNarrativeClassification\Controllers\AdvancedNarrativeClassification::class;

$route->group('/admin/advanced-narrative-classification', function ($route) use ($ctrl) {
    $route->apiPost('/bootstrap', $ctrl . '@adminBootstrap');
    $route->apiPost('/taxonomy/upsert', $ctrl . '@adminTaxonomyUpsert');
    $route->apiPost('/taxonomy/delete', $ctrl . '@adminTaxonomyDelete');
    $route->apiPost('/node/upsert', $ctrl . '@adminNodeUpsert');
    $route->apiPost('/node/delete', $ctrl . '@adminNodeDelete');
    $route->apiPost('/node/tags/sync', $ctrl . '@adminNodeTagsSync');
    $route->apiPost('/alias/upsert', $ctrl . '@adminAliasUpsert');
    $route->apiPost('/alias/delete', $ctrl . '@adminAliasDelete');
    $route->apiPost('/discover', $ctrl . '@adminDiscover');
});

$route->group('/advanced-narrative-classification', function ($route) use ($ctrl) {
    $route->apiPost('/bootstrap', $ctrl . '@gameBootstrap');
    $route->apiPost('/search', $ctrl . '@gameSearch');
    $route->apiPost('/discover', $ctrl . '@gameDiscover');
    $route->apiPost('/tag/context', $ctrl . '@gameTagContext');
});

$route->group('/game/advanced-narrative-classification', function ($route) {
    $route->get('/', function () {
        $characterId = \Core\AuthGuard::html()->requireCharacter();
        $db = \Core\AppContext::dbProvider()->connection();
        $presence = new \App\Services\PresenceService($db);
        $presence->touchCharacter((int) $characterId);

        return \Core\AppContext::templateRenderer()->render('app/advanced-narrative-classification.twig');
    });
});
