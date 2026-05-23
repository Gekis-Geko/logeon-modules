<?php

declare(strict_types=1);

/** @var \Core\Router $route */

$ctrl = \Modules\Logeon\AdvanceMaps\Controllers\AdvanceMaps::class;

$route->group('/advance-maps', function ($route) use ($ctrl) {
    $route->apiPost('/list', $ctrl . '@runtimeList');
    $route->apiPost('/context', $ctrl . '@runtimeContext');
});

$route->group('/admin/advance-maps', function ($route) use ($ctrl) {
    $route->apiPost('/maps/list', $ctrl . '@adminMapsList');
    $route->apiPost('/maps/get', $ctrl . '@adminMapGet');
    $route->apiPost('/maps/save', $ctrl . '@adminMapSave');
    $route->apiPost('/maps/delete', $ctrl . '@adminMapDelete');

    $route->apiPost('/hotspots/list', $ctrl . '@adminHotspotsList');
    $route->apiPost('/hotspots/save', $ctrl . '@adminHotspotSave');
    $route->apiPost('/hotspots/delete', $ctrl . '@adminHotspotDelete');
});
