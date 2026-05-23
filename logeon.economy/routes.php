<?php

declare(strict_types=1);

/** @var \Core\Router $route */

$ctrl = \Modules\Logeon\Economy\Controllers\Economy::class;

$route->group('/admin/economy-effects', function ($route) use ($ctrl) {
    $route->apiPost('/bootstrap', $ctrl . '@adminBootstrap');
    $route->apiPost('/save', $ctrl . '@adminSave');
    $route->apiPost('/delete', $ctrl . '@adminDelete');
    $route->apiPost('/preview', $ctrl . '@adminPreview');
});
