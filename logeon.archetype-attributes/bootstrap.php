<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Modules\\Logeon\\ArchetypeAttributes\\';
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

    $hasArchetypesCapability = static function (): bool {
        return \App\Services\CapabilityRegistry::has('character.archetypes');
    };

    $hasAttributesCapability = static function (): bool {
        return \App\Services\CapabilityRegistry::has('character.attributes');
    };

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

    \Core\Hooks::add('twig.slot.admin.dashboard.archetype-attributes', static function ($fragments) {
        if (!is_array($fragments)) {
            $fragments = [];
        }
        $fragments[] = [
            'id' => 'archetype-attributes-admin-dashboard-page',
            'template' => 'admin/pages/archetype-attributes.twig',
            'after' => '',
            'before' => '',
            'data' => [],
        ];
        return $fragments;
    });

    \Core\Hooks::add('twig.slot.character.create.form.extra', static function ($fragments) {
        if (!is_array($fragments)) {
            $fragments = [];
        }
        $fragments[] = [
            'id' => 'archetype-attributes-character-create',
            'template' => 'archetype-attributes/character-create-attribute-field.twig',
            'after' => 'archetypes-character-create',
            'before' => '',
            'data' => [],
        ];
        return $fragments;
    });

    \Core\Hooks::add('character.create.validate', static function ($errors, $payload) use ($hasArchetypesCapability, $hasAttributesCapability) {
        if (!$hasArchetypesCapability() || !$hasAttributesCapability()) {
            return is_array($errors) ? $errors : [];
        }

        (new \Modules\Logeon\ArchetypeAttributes\Services\ArchetypeAttributesService())
            ->validateCharacterCreate(is_object($payload) ? $payload : (object) []);
        return is_array($errors) ? $errors : [];
    });

    \Core\Hooks::add('character.created', static function ($characterId, $payload) use ($hasArchetypesCapability, $hasAttributesCapability) {
        if (!$hasArchetypesCapability() || !$hasAttributesCapability()) {
            return;
        }

        (new \Modules\Logeon\ArchetypeAttributes\Services\ArchetypeAttributesService())
            ->applyCharacterCreate((int) $characterId, is_object($payload) ? $payload : (object) []);
    });

    \Core\Hooks::add('character.attributes.modifier.providers', static function ($providers) {
        if (!is_array($providers)) {
            $providers = [];
        }

        $providers[] = new \Modules\Logeon\ArchetypeAttributes\ArchetypeAttributeModifierProvider();
        return $providers;
    });

    \Core\Hooks::add('character.archetypes.changed', static function ($payload) use ($hasArchetypesCapability, $hasAttributesCapability) {
        if (!is_array($payload)) {
            return;
        }

        $characterId = (int) ($payload['character_id'] ?? 0);
        if ($characterId <= 0 || !$hasArchetypesCapability() || !$hasAttributesCapability()) {
            return;
        }

        try {
            (new \Modules\Logeon\Attributes\Services\CharacterAttributesFacadeService())
                ->recomputeCharacter($characterId);
        } catch (\Throwable $e) {
            error_log('[character.archetypes.changed] attribute recompute failed: ' . $e->getMessage());
        }
    });
};
