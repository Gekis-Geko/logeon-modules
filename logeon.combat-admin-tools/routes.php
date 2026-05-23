<?php

declare(strict_types=1);

/** @var \Core\Router $route */

$ctrl = \Modules\Logeon\CombatAdminTools\Controllers\CombatAdminTools::class;

$route->group('/admin/combat-admin-tools', function ($route) use ($ctrl) {
    $route->apiPost('/bootstrap', $ctrl . '@adminBootstrap');
});
