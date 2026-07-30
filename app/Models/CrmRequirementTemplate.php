<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmRequirementTemplate extends Model
{
    protected $table = 'crm_requirement_templates';

    protected $fillable = ['name', 'service_key', 'description', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CrmRequirementTemplateItem::class, 'template_id')->orderBy('sort_order');
    }
}
