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
 * الزيارة — مخططةً أو طارئةً أو فائتة، بتقريرٍ مهيكلٍ ومنتجاتٍ عُرِضت.
 *
 * السياقُ يُورَث لا يُسأل عنه: عيادةُ الطبيب تعطي منطقتَه، والمنشأةُ عميلاً
 * تُلحقها العزلَ. والاسمُ يُولَّد من الطبيب والتاريخ إن تُرك.
 */
class Visit extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'visits';
    public const MODULE = 'visits';
    public const DISPLAY = 'name';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'product_ids' => 'array',
        'planned_date' => 'date',
        'visit_at' => 'datetime',
        'tags' => 'array',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $v) {
            // السياقُ الموروث: منطقةُ الطبيب، وعميلُ المنشأة — فيلحق العزلُ الزيارةَ
            if (! $v->territory_id && $v->hcp_id) {
                $v->territory_id = Hcp::whereKey($v->hcp_id)->value('territory_id');
            }
            if (! $v->client_id && $v->facility_id) {
                $v->client_id = Facility::whereKey($v->facility_id)->value('client_id');
            }
            // اسمُ العرض: الطبيب — التاريخ، إن تُرك فارغاً
            if (! $v->name) {
                $hcp = $v->hcp_id ? Hcp::whereKey($v->hcp_id)->value('name') : null;
                $d = $v->planned_date ?: ($v->visit_at ? substr((string) $v->visit_at, 0, 10) : now()->toDateString());
                $v->name = trim(($hcp ?: 'زيارة') . ' — ' . $d);
            }
        });
    }

    public function hcp(): BelongsTo
    {
        return $this->belongsTo(Hcp::class, 'hcp_id');
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class, 'cycle_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'emp_id');
    }
}
