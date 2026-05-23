<?php

declare(strict_types=1);

/** @var \Core\Router $route */

$ctrl = \Modules\Logeon\AbilitiesSpells\Controllers\AbilitiesSpells::class;

$route->get('/game/abilities-spells', function () {
    $guard = \Core\AuthGuard::html();
    $characterId = (int) $guard->requireCharacter();
    (new \App\Services\PresenceService())->touchCharacter($characterId);

    return \Core\AppContext::templateRenderer()->render('app/abilities-spells.twig', [
        'app_page' => 'abilities-spells',
    ]);
});

$route->group('/abilities-spells', function ($route) use ($ctrl) {
    $route->apiPost('/my', $ctrl . '@my');
    $route->apiPost('/points', $ctrl . '@points');
    $route->apiPost('/learn', $ctrl . '@learn');
    $route->apiPost('/upgrade', $ctrl . '@upgrade');
    $route->apiPost('/use', $ctrl . '@use');
});

$route->group('/admin/abilities-spells', function ($route) use ($ctrl) {
    $route->apiPost('/states/list', $ctrl . '@adminStatesList');
    $route->apiPost('/abilities/list', $ctrl . '@adminAbilitiesList');
    $route->apiPost('/abilities/create', $ctrl . '@adminAbilityCreate');
    $route->apiPost('/abilities/update', $ctrl . '@adminAbilityUpdate');
    $route->apiPost('/abilities/delete', $ctrl . '@adminAbilityDelete');
    $route->apiPost('/abilities/grants/list', $ctrl . '@adminAbilityGrantsList');
    $route->apiPost('/abilities/grants/upsert', $ctrl . '@adminAbilityGrantUpsert');
    $route->apiPost('/abilities/grants/delete', $ctrl . '@adminAbilityGrantDelete');
    $route->apiPost('/abilities/requirements/list', $ctrl . '@adminAbilityRequirementsList');
    $route->apiPost('/abilities/requirements/upsert', $ctrl . '@adminAbilityRequirementUpsert');
    $route->apiPost('/abilities/requirements/delete', $ctrl . '@adminAbilityRequirementDelete');
    $route->apiPost('/abilities/effects/list', $ctrl . '@adminAbilityEffectsList');
    $route->apiPost('/abilities/effects/upsert', $ctrl . '@adminAbilityEffectUpsert');
    $route->apiPost('/abilities/effects/delete', $ctrl . '@adminAbilityEffectDelete');
    $route->apiPost('/rewards/list', $ctrl . '@adminRankRewardsList');
    $route->apiPost('/rewards/upsert', $ctrl . '@adminRankRewardUpsert');
    $route->apiPost('/rewards/delete', $ctrl . '@adminRankRewardDelete');
    $route->apiPost('/point-categories/list', $ctrl . '@adminPointCategoriesList');
    $route->apiPost('/approvals/pending', $ctrl . '@adminPendingApprovalsList');
    $route->apiPost('/approvals/resolve', $ctrl . '@adminPendingApprovalResolve');
    $route->apiPost('/assignments/list', $ctrl . '@adminAssignmentsList');
    $route->apiPost('/assignments/create', $ctrl . '@adminAssignmentCreate');
    $route->apiPost('/assignments/delete', $ctrl . '@adminAssignmentDelete');
    $route->apiPost('/characters/search', $ctrl . '@adminCharactersSearch');
});
