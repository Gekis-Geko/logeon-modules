<?php

declare(strict_types=1);

namespace Modules\Logeon\AbilitiesSpells\Models;

use Core\Models;

class CharacterAbility extends Models
{
    protected $table = 'lf_abilities_spells_character_abilities';
    protected $primary_key = 'id';
    protected $fillable = [
        'id',
        'character_id',
        'ability_id',
        'status',
        'level',
        'pending_points',
        'spent_points',
        'approval_status',
        'approved_by_user_id',
        'approved_at',
        'suspended_reason',
        'metadata_json',
        'sort_order',
        'is_active',
        'date_created',
        'date_updated',
    ];
}
