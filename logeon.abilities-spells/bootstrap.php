<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Modules\\Logeon\\AbilitiesSpells\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $file = __DIR__ . '/src/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

return static function ($moduleRuntime = null, $moduleManifest = null): void {
    if (!class_exists('\\Core\\Hooks')) {
        return;
    }

    \Core\Hooks::add('character.attributes.modifier.providers', static function ($providers) {
        if (!is_array($providers)) {
            $providers = [];
        }
        $providers[] = new \Modules\Logeon\AbilitiesSpells\AbilityAttributeModifierProvider();
        return $providers;
    });

    \Core\Hooks::add('capability.registry.capabilities', static function ($capabilities) {
        if (!is_array($capabilities)) {
            $capabilities = [];
        }
        $capabilities['character.abilities'] = true;
        return $capabilities;
    });

    \Core\Hooks::add('twig.view_paths', static function ($paths) {
        if (!is_array($paths)) {
            $paths = [];
        }
        $viewPath = __DIR__ . '/views';
        if (!in_array($viewPath, $paths, true)) {
            $paths[] = $viewPath;
        }
        return $paths;
    });

    \Core\Hooks::add('twig.slot.admin.dashboard.abilities-spells', static function ($fragments) {
        if (!is_array($fragments)) {
            $fragments = [];
        }
        $fragments[] = [
            'id' => 'abilities-spells-admin-dashboard-page',
            'template' => 'admin/pages/abilities-spells.twig',
            'after' => '',
            'before' => '',
            'data' => [],
        ];
        return $fragments;
    });

    \Core\Hooks::add('view.slot.game.location.scene_launcher.abilities_tab', static function ($html) {
        $rendered = \Core\AppContext::templateRenderer()->render('app/location/scene-launcher-abilities-tab.twig');
        return is_string($html) ? ($html . $rendered) : $rendered;
    });

    \Core\Hooks::add('view.slot.game.location.scene_launcher.abilities_pane', static function ($html) {
        $rendered = \Core\AppContext::templateRenderer()->render('app/location/scene-launcher-abilities-pane.twig');
        return is_string($html) ? ($html . $rendered) : $rendered;
    });

    \Core\Hooks::add('view.slot.game.location.character_abilities', static function ($html) {
        $rendered = \Core\AppContext::templateRenderer()->render('app/location/character-abilities.twig');
        return is_string($html) ? ($html . $rendered) : $rendered;
    });

    $attributeRecomputeListener = static function ($payload) {
        if (!is_array($payload)) {
            return $payload;
        }

        $characterId = (int) ($payload['character_id'] ?? 0);
        if ($characterId <= 0 || !\App\Services\CapabilityRegistry::has('character.attributes')) {
            return $payload;
        }

        try {
            (new \Modules\Logeon\Attributes\Services\CharacterAttributesFacadeService())->recomputeCharacter($characterId);
        } catch (\Throwable $e) {
            error_log('[abilities-spells] attribute recompute failed: ' . $e->getMessage());
        }

        return $payload;
    };

    \Core\Hooks::add('character.abilities.changed', $attributeRecomputeListener);
    \Core\Hooks::add('character.archetypes.changed', $attributeRecomputeListener);
    \Core\Hooks::add('character.rank.changed', static function ($payload) use ($attributeRecomputeListener) {
        $attributeRecomputeListener($payload);
        if (is_array($payload)) {
            try {
                (new \Modules\Logeon\AbilitiesSpells\Services\AbilitiesSpellsService())->allocateRankPointRewardsFromEvent($payload);
            } catch (\Throwable $e) {
                error_log('[abilities-spells] rank reward allocation failed: ' . $e->getMessage());
            }
        }
        return $payload;
    });
};
