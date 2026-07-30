<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmRequirementTemplateItem extends Model
{
    protected $table = 'crm_requirement_template_items';

    protected $fillable = ['template_id', 'name', 'description', 'is_required', 'sort_order'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'sort_order' => 'integer'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CrmRequirementTemplate::class, 'template_id');
    }
}
