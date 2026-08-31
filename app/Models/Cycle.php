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

/**
 * الدورة/الحملة — نافذةٌ زمنيّة بأهداف تغطيةٍ وتكرار على منطقةٍ ومنتجات.
 * التغطيةُ الفعلية تُقاس من الزيارات المرتبطة لا من حقلٍ يُملأ.
 */
class Cycle extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'cycles';
    public const MODULE = 'cycles';
    public const DISPLAY = 'name';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'product_ids' => 'array',
        'date_start' => 'date',
        'date_end' => 'date',
        'tags' => 'array',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'territory_id');
    }

    /** التغطية الفعلية: زياراتٌ تمّت مقابل الهدف — تُحسب لا تُخزَّن */
    public function coverage(): array
    {
        $done = hub_scope(Visit::whereNull('deleted_at')->where('cycle_id', $this->id)
            ->where('status', 'تمت'), 'visits')->count();
        $target = (int) ($this->target_visits ?? 0);

        return [
            'done' => $done,
            'target' => $target,
            'pct' => $target > 0 ? min(100, (int) round($done * 100 / $target)) : null,
        ];
    }
}
