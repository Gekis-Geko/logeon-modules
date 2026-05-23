<?php

declare(strict_types=1);

/** @var \Core\Router $route */

$ctrl = \Modules\Logeon\Polls\Controllers\Polls::class;

$route->group('/admin/polls', function ($route) use ($ctrl) {
    $route->apiPost('/bootstrap', $ctrl . '@adminBootstrap');
    $route->apiPost('/save', $ctrl . '@adminSave');
    $route->apiPost('/delete', $ctrl . '@adminDelete');
    $route->apiPost('/results', $ctrl . '@adminResults');
});

$route->group('/polls', function ($route) use ($ctrl) {
    $route->apiPost('/bootstrap', $ctrl . '@gameBootstrap');
    $route->apiPost('/vote', $ctrl . '@vote');
    $route->apiPost('/results', $ctrl . '@gameResults');
});

$route->group('/game/polls', function ($route) {
    $route->get('/', function () {
        $characterId = \Core\AuthGuard::html()->requireCharacter();
        $db = \Core\AppContext::dbProvider()->connection();
        $presence = new \App\Services\PresenceService($db);
        $presence->touchCharacter((int) $characterId);

        return \Core\AppContext::templateRenderer()->render('app/polls.twig');
    });
});
