<?php

declare(strict_types=1);

/** @var \Core\Router $route */

$ctrl = \Modules\Logeon\AdvancedItems\Controllers\AdvancedItems::class;

$route->get('/game/advanced-items', function () {
    $guard = \Core\AuthGuard::html();
    $characterId = (int) $guard->requireCharacter();
    (new \App\Services\PresenceService())->touchCharacter($characterId);

    return \Core\AppContext::templateRenderer()->render('app/advanced-items.twig', [
        'app_page' => 'advanced-items',
    ]);
});

$route->group('/advanced-items', function ($route) use ($ctrl) {
    $route->apiPost('/my', $ctrl . '@my');
    $route->apiPost('/use', $ctrl . '@use');
    $route->apiPost('/restore', $ctrl . '@restore');
});

$route->group('/admin/advanced-items', function ($route) use ($ctrl) {
    $route->apiPost('/profiles/list', $ctrl . '@adminProfilesList');
    $route->apiPost('/profiles/create', $ctrl . '@adminProfileCreate');
    $route->apiPost('/profiles/update', $ctrl . '@adminProfileUpdate');
    $route->apiPost('/profiles/delete', $ctrl . '@adminProfileDelete');
    $route->apiPost('/assignments/list', $ctrl . '@adminAssignmentsList');
    $route->apiPost('/assignments/create', $ctrl . '@adminAssignmentCreate');
    $route->apiPost('/assignments/update', $ctrl . '@adminAssignmentUpdate');
    $route->apiPost('/assignments/delete', $ctrl . '@adminAssignmentDelete');
    $route->apiPost('/characters/search', $ctrl . '@adminCharactersSearch');
    $route->apiPost('/items/search', $ctrl . '@adminItemsSearch');
});
