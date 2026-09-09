<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * **تخصيصُ أصلٍ لمشروع** (Project 360 · §15) — علاقةٌ زمنيّةٌ: صفٌّ لكلِّ تخصيصٍ لا يُعاد
 * كتابتُه. **ليس عهدةً:** لا يمسّ الحائزَ ولا المحطّةَ ولا `asset_custody`. الحاليُّ مشتقٌّ
 * (`ended_at IS NULL` ⇔ `active_flag=1`)، والإنهاءُ يحفظ التاريخ (لا حذف). أصلٌ واحدٌ قد
 * يدعم عدّةَ مشاريعَ نشطةً؛ والزوجُ (أصل، مشروع) النشطُ فريدٌ (فهرسٌ فريدٌ عبر المحرّكين).
 *
 * لا CRUD عامٌّ عليه (ليس وحدةَ `hub.modules`): يُكتَب عبر `AssetProjectService` وحدَه
 * (معاملة + قفل + تدقيق)، فلا يُنشأ زوجٌ نشطٌ مكرَّرٌ بالتزامن.
 */
class AssetProjectAssignment extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $table = 'asset_project_assignments';

    protected $guarded = ['id'];

    protected $casts = [
        'assigned_at' => 'datetime',
        'ended_at' => 'datetime',
        'active_flag' => 'integer',
    ];

    /** قيمةُ رايةِ النشاط (1)؛ NULL = مُنهى — والفهرسُ الفريدُ يمنع تكرارَ النشط */
    public const ACTIVE = 1;

    /** الصفوفُ النشطةُ (لم تُنهَ بعد) — ترتيبٌ حتميّ للقراءة */
    public function scopeActive($q)
    {
        return $q->whereNull('ended_at');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    /** هل هذا التخصيصُ نشطٌ الآن؟ */
    public function isActive(): bool
    {
        return $this->ended_at === null;
    }
}
