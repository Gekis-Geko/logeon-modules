<?php

declare(strict_types=1);

namespace Modules\Logeon\ArchetypeAttributes;

use App\Contracts\CharacterAttributeModifierProviderInterface;
use Modules\Logeon\ArchetypeAttributes\Services\ArchetypeAttributesService;

class ArchetypeAttributeModifierProvider implements CharacterAttributeModifierProviderInterface
{
    private ArchetypeAttributesService $service;

    public function __construct(ArchetypeAttributesService $service = null)
    {
        $this->service = $service ?: new ArchetypeAttributesService();
    }

    public function isAvailable(): bool
    {
        return $this->service->ensureAttributesEnabled();
    }

    public function getCharacterAttributeModifiers(int $characterId): array
    {
        return $this->service->getCharacterAttributeModifiers($characterId);
    }
}
