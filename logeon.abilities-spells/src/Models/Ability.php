<?php

declare(strict_types=1);

namespace Modules\Logeon\AbilitiesSpells\Models;

use Core\Models;

class Ability extends Models
{
    protected $table = 'lf_abilities_spells_abilities';
    protected $primary_key = 'id';
    protected $fillable = [
        'id',
        'name',
        'slug',
        'description',
        'type',
        'category_id',
        'point_category_id',
        'target_type',
        'effect_mode',
        'narrative_state_id',
        'cooldown_seconds',
        'sort_order',
        'is_active',
        'is_public',
        'is_hidden_when_locked',
        'requires_learning',
        'requires_staff_approval',
        'max_level',
        'metadata_json',
        'date_created',
        'date_updated',
    ];
}
