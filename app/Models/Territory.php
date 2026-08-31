<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuid;
use App\Traits\HasVersions;
use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * المنطقة الميدانية — هرميةُ التغطية: بلد ← محافظة ← منطقة ← قطاع.
 *
 * أولُ مرجعٍ ذاتي في سجل الوحدات: `parent_id` يبني الشجرة، وحلقةُ
 * الحماية في `saving` تمنع أن تكون المنطقةُ جدَّ نفسها — فالمولّد العام
 * يعرض قائمةً مسطّحة ولا يعرف الدور (cycle) لو كُتب.
 */
class Territory extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'territories';
    public const MODULE = 'territories';
    public const DISPLAY = 'name';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'tags' => 'array',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $t) {
            // لا منطقة أبوها نفسُها ولا دورٌ في السلسلة — يُقطع الأب الفاسد
            // بصمتٍ بدل رفض الحفظ: البيانات أهم من الرابط المعطوب.
            if ($t->parent_id === $t->id) $t->parent_id = null;
            $seen = [$t->id];
            $p = $t->parent_id;
            for ($i = 0; $p && $i < 12; $i++) {
                if (in_array($p, $seen, true)) { $t->parent_id = null; break; }
                $seen[] = $p;
                $p = self::whereKey($p)->value('parent_id');
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    /** المنطقةُ ونسلُها كلُّه — لتجميع تغطية «المحافظة» من قطاعاتها */
    public function subtreeIds(): array
    {
        $ids = [$this->id];
        $layer = [$this->id];
        for ($i = 0; $layer && $i < 12; $i++) {
            $layer = self::whereIn('parent_id', $layer)->pluck('id')->all();
            $ids = array_merge($ids, $layer);
        }

        return array_values(array_unique($ids));
    }
}
