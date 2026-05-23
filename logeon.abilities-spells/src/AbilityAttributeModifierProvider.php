<?php

declare(strict_types=1);

namespace Modules\Logeon\AbilitiesSpells;

use App\Contracts\CharacterAttributeModifierProviderInterface;
use Modules\Logeon\AbilitiesSpells\Services\AbilitiesSpellsService;

class AbilityAttributeModifierProvider implements CharacterAttributeModifierProviderInterface
{
    private AbilitiesSpellsService $service;

    public function __construct(AbilitiesSpellsService $service = null)
    {
        $this->service = $service ?: new AbilitiesSpellsService();
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getCharacterAttributeModifiers(int $characterId): array
    {
        return $this->service->getCharacterAttributeModifiers($characterId);
    }
}
