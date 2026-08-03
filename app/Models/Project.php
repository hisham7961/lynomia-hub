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

/** المشاريع */
class Project extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'projects';
    public const MODULE = 'projects';
    public const DISPLAY = 'name';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'members' => 'array',
        'progress' => 'decimal:3',
        'start_date' => 'date',
        'launch_exp' => 'date',
        'launch_act' => 'date',
        'budget' => 'decimal:3',
        'cost' => 'decimal:3',
        'rev_exp' => 'decimal:3',
        'tags' => 'array',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    protected static function booted(): void
    {
        // عضوية المشاريع بياناتُ صلاحية: تغيّرها يُبطل خبيئة User::visibleProjectIds
        // لكل متأثّر (مدير/عضو، قديماً وحديثاً) — وإلا ظل مسحوبُ الوصول يرى مهام
        // المشروع ومستنداته حتى ٥ دقائق. الإبطال القديم كان يمسّ مفتاح المحرِّر وحده.
        $bust = function (self $p) {
            $orig = $p->getOriginal('members');
            $origMembers = is_array($orig) ? $orig : (json_decode((string) $orig, true) ?: []);
            collect([$p->manager_id, $p->getOriginal('manager_id')])
                ->merge(is_array($p->members) ? $p->members : [])
                ->merge($origMembers)
                ->filter()->unique()
                ->each(fn ($uid) => \Illuminate\Support\Facades\Cache::forget("user:{$uid}:projects"));
        };
        static::saved($bust);
        static::deleted($bust);
        static::restored($bust);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Company::class, 'company_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'manager_id');
    }

    public function brandDesigner(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'brand_designer_id');
    }
}
