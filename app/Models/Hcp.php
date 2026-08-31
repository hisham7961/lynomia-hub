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
 * مقدم الرعاية الصحية (HCP) — طبيبٌ أو صيدليٌّ أو ممرضٌ يزوره الميدان.
 *
 * سجلُّ معرفةٍ مهنيّ: من هو، وتخصصُه، وتصنيفُ أهميته، وأين يعمل. **ليس
 * طرفاً بيعياً**: لا باركود له ولا كود إحالة ولا عمولة ولا يُربط بطلبِ
 * متجرٍ أو مبيعةٍ — قاعدةُ منتجٍ صريحة يفرضها حارسُ اختبارٍ لا وثيقةٌ تُنسى.
 */
class Hcp extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'hcps';
    public const MODULE = 'hcps';
    public const DISPLAY = 'name';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'facility_ids' => 'array',
        'tags' => 'array',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'territory_id');
    }

    /** منشآتُ عمله — من مصفوفة الربط المتعدد، منطَّقةً كما كل قارئ */
    public function facilities()
    {
        $ids = array_values(array_filter((array) $this->facility_ids, 'is_string'));

        return Facility::whereIn('id', $ids)->orderBy('name')->get();
    }
}
