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
 * إسناد المنطقة — من يغطي أي منطقةٍ، من متى، وبأي صفة.
 *
 * جدولٌ حقيقي لا مصفوفةُ JSON عمداً: الإسنادُ المؤرَّخ تاريخٌ يُحفظ لا
 * حالةٌ تُستبدل — نقلُ مندوبٍ من منطقةٍ يعني إنهاءَ إسناده (حالة وتاريخ
 * نهاية) وفتحَ إسنادٍ جديد، فيبقى «من كان يغطي الحولّي في مارس؟» سؤالاً
 * تجيبه القاعدة لا الذاكرة.
 */
class TerritoryAssignment extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'terrassigns';
    public const MODULE = 'terrassigns';
    public const DISPLAY = 'name';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'date_start' => 'date',
        'date_end' => 'date',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $a) {
            // اسمُ العرض يُولَّد من طرفَي الإسناد إن تُرك فارغاً — فالقوائم
            // والمراجع تعرض «الحولّي ← أحمد» لا صفاً بلا وجه.
            if (! $a->name) {
                $t = Territory::whereKey($a->territory_id)->value('name');
                $e = Employee::whereKey($a->emp_id)->value('name');
                if ($t || $e) $a->name = trim(($t ?: '؟') . ' ← ' . ($e ?: '؟'));
            }
            if (! $a->date_start) $a->date_start = now()->toDateString();
            if (! $a->status) $a->status = 'ساري';
        });
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class, 'territory_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'emp_id');
    }
}
