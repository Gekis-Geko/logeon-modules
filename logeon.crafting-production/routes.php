<?php

declare(strict_types=1);

/** @var \Core\Router $route */

$ctrl = \Modules\Logeon\CraftingProduction\Controllers\CraftingProduction::class;

$route->group('/admin/crafting-production', function ($route) use ($ctrl) {
    $route->apiPost('/bootstrap', $ctrl . '@adminBootstrap');
    $route->apiPost('/characters/search', $ctrl . '@adminCharacterSearch');
    $route->apiPost('/scope/search', $ctrl . '@adminScopeSearch');
    $route->apiPost('/save-profession', $ctrl . '@adminSaveProfession');
    $route->apiPost('/delete-profession', $ctrl . '@adminDeleteProfession');
    $route->apiPost('/save-process', $ctrl . '@adminSaveProcess');
    $route->apiPost('/delete-process', $ctrl . '@adminDeleteProcess');
    $route->apiPost('/save-source', $ctrl . '@adminSaveSource');
    $route->apiPost('/delete-source', $ctrl . '@adminDeleteSource');
});

$route->group('/crafting-production', function ($route) use ($ctrl) {
    $route->apiPost('/bootstrap', $ctrl . '@gameBootstrap');
    $route->apiPost('/execute', $ctrl . '@executeProcess');
});

$route->group('/game/crafting-production', function ($route) {
    $route->get('/', function () {
        $characterId = \Core\AuthGuard::html()->requireCharacter();
        $db = \Core\AppContext::dbProvider()->connection();
        $presence = new \App\Services\PresenceService($db);
        $presence->touchCharacter((int) $characterId);

        return \Core\AppContext::templateRenderer()->render('app/crafting-production.twig');
    });
});
