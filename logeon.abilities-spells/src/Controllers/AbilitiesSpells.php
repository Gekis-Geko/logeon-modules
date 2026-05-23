<?php

declare(strict_types=1);

namespace Modules\Logeon\AbilitiesSpells\Controllers;

use Core\Http\ApiResponse;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Logging\LoggerInterface;
use Modules\Logeon\AbilitiesSpells\Services\AbilitiesSpellsService;

class AbilitiesSpells
{
    /** @var LoggerInterface|null */
    private $logger = null;
    /** @var AbilitiesSpellsService|null */
    private $abilitiesSpellsService = null;

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        return $this;
    }

    public function setAbilitiesSpellsService(AbilitiesSpellsService $abilitiesSpellsService = null)
    {
        $this->abilitiesSpellsService = $abilitiesSpellsService;
        return $this;
    }

    private function logger(): LoggerInterface
    {
        if ($this->logger instanceof LoggerInterface) {
            return $this->logger;
        }

        $this->logger = \Core\AppContext::logger();
        return $this->logger;
    }

    protected function trace($message, $context = false): void
    {
        $this->logger()->trace($message, $context);
    }

    private function service(): AbilitiesSpellsService
    {
        if ($this->abilitiesSpellsService instanceof AbilitiesSpellsService) {
            return $this->abilitiesSpellsService;
        }

        $this->abilitiesSpellsService = new AbilitiesSpellsService();
        return $this->abilitiesSpellsService;
    }

    private function requestDataObject()
    {
        $request = RequestData::fromGlobals();
        return InputValidator::postJsonObject($request, 'data', true);
    }

    private function emitJson(array $payload): void
    {
        ResponseEmitter::emit(ApiResponse::json($payload));
    }

    private function requireCharacter(): int
    {
        return (int) \Core\AuthGuard::api()->requireCharacter();
    }

    private function requireAdmin(): void
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage');
    }

    private function requireAdminUserId(): int
    {
        $this->requireAdmin();
        return (int) \Core\AuthGuard::api()->requireUser();
    }

    public function my($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();
        $dataset = $this->service()->listCharacterAbilities($characterId);
        $response = ['dataset' => $dataset];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function use($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();
        $data = $this->requestDataObject();
        $dataset = $this->service()->useAbility($characterId, $data);
        $response = ['dataset' => $dataset];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function points($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();
        $response = ['dataset' => $this->service()->listCharacterAbilityPoints($characterId)];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function learn($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();
        $data = $this->requestDataObject();
        $response = ['dataset' => $this->service()->learnAbility($characterId, $data), 'success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function upgrade($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $characterId = $this->requireCharacter();
        $data = $this->requestDataObject();
        $response = ['dataset' => $this->service()->upgradeAbility($characterId, $data), 'success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminStatesList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = ['dataset' => $this->service()->adminListNarrativeStates()];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminAbilitiesList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = ['dataset' => $this->service()->adminListAbilities()];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminAbilityCreate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $id = $this->service()->adminCreateAbility($this->requestDataObject());
        $response = ['dataset' => ['id' => $id], 'success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminAbilityUpdate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $this->service()->adminUpdateAbility($this->requestDataObject());
        $response = ['success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminAbilityDelete($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $this->service()->adminDeleteAbility((int) ($data->id ?? 0));
        $response = ['success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminAbilityGrantsList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $response = ['dataset' => $this->service()->adminListAbilityGrants((int) ($data->ability_id ?? 0))];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminAbilityGrantUpsert($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = ['dataset' => $this->service()->adminUpsertAbilityGrant($this->requestDataObject()), 'success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminAbilityGrantDelete($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $this->service()->adminDeleteAbilityGrant((int) ($data->id ?? 0));
        $response = ['success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminAbilityRequirementsList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $response = ['dataset' => $this->service()->adminListAbilityRequirements((int) ($data->ability_id ?? 0))];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminAbilityRequirementUpsert($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = ['dataset' => $this->service()->adminUpsertAbilityRequirement($this->requestDataObject()), 'success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminAbilityRequirementDelete($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $this->service()->adminDeleteAbilityRequirement((int) ($data->id ?? 0));
        $response = ['success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminAbilityEffectsList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $response = ['dataset' => $this->service()->adminListAbilityEffects((int) ($data->ability_id ?? 0))];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminAbilityEffectUpsert($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = ['dataset' => $this->service()->adminUpsertAbilityEffect($this->requestDataObject()), 'success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminAbilityEffectDelete($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $this->service()->adminDeleteAbilityEffect((int) ($data->id ?? 0));
        $response = ['success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminRankRewardsList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = ['dataset' => $this->service()->adminListRankRewards()];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminRankRewardUpsert($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = ['dataset' => $this->service()->adminUpsertRankReward($this->requestDataObject()), 'success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminRankRewardDelete($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $this->service()->adminDeleteRankReward((int) ($data->id ?? 0));
        $response = ['success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminPointCategoriesList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = ['dataset' => $this->service()->adminListPointCategories()];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminPendingApprovalsList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $response = ['dataset' => $this->service()->adminListPendingApprovals()];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminPendingApprovalResolve($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $userId = $this->requireAdminUserId();
        $response = ['dataset' => $this->service()->adminResolvePendingApproval($this->requestDataObject(), $userId), 'success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminAssignmentsList($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $characterId = (int) ($data->character_id ?? 0);
        $response = ['dataset' => $this->service()->adminListAssignments($characterId)];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminAssignmentCreate($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $id = $this->service()->adminCreateAssignment($this->requestDataObject());
        $response = ['dataset' => ['id' => $id], 'success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminAssignmentDelete($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $this->service()->adminDeleteAssignment((int) ($data->id ?? 0));
        $response = ['success' => true];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }

    public function adminCharactersSearch($echo = true)
    {
        $this->trace('Richiamato il metodo: ' . __METHOD__);
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $response = ['dataset' => $this->service()->adminCharactersSearch((string) ($data->query ?? ''))];

        if ($echo) {
            $this->emitJson($response);
        }
        return $response;
    }
}
