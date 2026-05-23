<?php

declare(strict_types=1);

/** @var \Core\Router $route */

$ctrl = \Modules\Logeon\NarrativeStates\Controllers\NarrativeStatesPreset::class;

$route->get('/game/narrative-states', function () {
    $guard = \Core\AuthGuard::html();
    $characterId = (int) $guard->requireCharacter();
    (new \App\Services\PresenceService())->touchCharacter($characterId);

    return \Core\AppContext::templateRenderer()->render('app/narrative-states.twig', [
        'app_page' => 'narrative-states',
    ]);
});

$route->group('/logeon-narrative-presets', function ($route) use ($ctrl) {
    $route->apiPost('/my', $ctrl . '@my');
    $route->apiPost('/apply', $ctrl . '@applyPreset');
});

$route->group('/admin/narrative-states', function ($route) use ($ctrl) {
    $route->apiPost('/states/catalog', $ctrl . '@adminStatesCatalog');
    $route->apiPost('/presets/list', $ctrl . '@adminPresetsList');
    $route->apiPost('/presets/create', $ctrl . '@adminPresetCreate');
    $route->apiPost('/presets/update', $ctrl . '@adminPresetUpdate');
    $route->apiPost('/presets/delete', $ctrl . '@adminPresetDelete');
    $route->apiPost('/preset-states/list', $ctrl . '@adminPresetStatesList');
    $route->apiPost('/preset-states/create', $ctrl . '@adminPresetStateCreate');
    $route->apiPost('/preset-states/update', $ctrl . '@adminPresetStateUpdate');
    $route->apiPost('/preset-states/delete', $ctrl . '@adminPresetStateDelete');
    $route->apiPost('/assignments/list', $ctrl . '@adminAssignmentsList');
    $route->apiPost('/assignments/create', $ctrl . '@adminAssignmentCreate');
    $route->apiPost('/assignments/update', $ctrl . '@adminAssignmentUpdate');
    $route->apiPost('/assignments/delete', $ctrl . '@adminAssignmentDelete');
    $route->apiPost('/characters/search', $ctrl . '@adminCharactersSearch');
});
