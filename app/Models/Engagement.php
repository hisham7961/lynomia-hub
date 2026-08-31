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
 * الارتباط (Engagement) — العلاقةُ المنظَّمة بين لينوميا وعميل.
 *
 * عميلٌ واحد قد نديرُ له خدماتِ IT ونبني له متجراً ونشغّل تسويقَه — ثلاثةُ
 * ارتباطاتٍ لكلٍّ منها عقودُه ومشاريعُه وفريقُه وفوترتُه وموعدُ تجديده.
 * الطبقةُ المنظِّمة بين العميل ومشاريعه: عميل ← ارتباط ← مشاريع ← كل شيء.
 */
class Engagement extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'engagements';
    public const MODULE = 'engagements';
    public const DISPLAY = 'name';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'date_start' => 'date',
        'date_end' => 'date',
        'renewal' => 'date',
        'budget' => 'decimal:3',
        'revenue' => 'decimal:3',
        'tags' => 'array',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'engagement_id');
    }
}
