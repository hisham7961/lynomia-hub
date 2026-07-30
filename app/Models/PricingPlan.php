<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuid;
use App\Traits\HasVersions;
use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** الباقات والتسعير (Pricing Plans) */
class PricingPlan extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'pricing_plans';
    public const MODULE = 'plans';
    public const DISPLAY = 'name';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'price' => 'decimal:3',
        'prev_price' => 'decimal:3',
        'unit_cost' => 'decimal:3',
        'free_tier' => 'boolean',
        'effective_from' => 'date',
        'price_changed_at' => 'date',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Service::class, 'service_id');
    }
}
