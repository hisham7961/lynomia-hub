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

    /**
     * جمهورُ المشروع (Work OS · الطور D · WP-D.1 · SF-4) — المصنِّفُ الصلب:
     *   • `internal` — مشروعٌ داخليٌّ لِـلينوميا (الافتراض).
     *   • `client`   — مشروعٌ خارجيٌّ لعميلٍ بعينه، له غرفتاه المنفصلتان.
     * allowlist في التطبيق لا كـenum على القاعدة (درسُ C10): إضافةُ قيمةٍ مستقبلاً
     * سطرٌ هنا لا ALTER. والافتراضُ داخليّ — لا يتحوّل مشروعٌ لخارجيٍّ بالسهو.
     */
    public const AUDIENCE_INTERNAL = 'internal';
    public const AUDIENCE_CLIENT = 'client';

    public const AUDIENCES = [self::AUDIENCE_INTERNAL, self::AUDIENCE_CLIENT];

    protected $guarded = ['id', 'version', 'created_by'];

    /** الجمهورُ الافتراضيّ على النموذج نفسِه — داخليّ، لا يُترك للقاعدة وحدَها (SF-4) */
    protected $attributes = [
        'audience' => self::AUDIENCE_INTERNAL,
    ];

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
        // (Work OS · الطور D · WP-D.3) إشارةُ الحجب الداخليّ — إضافيّةٌ لا حالةُ حياة.
        // `hold_reason` نصٌّ حرٌّ فلا cast له؛ كلاهما قابلُ الإسناد عبر $guarded القائم.
        'blocked' => 'boolean',
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

        // حارسُ الجمهور — «enum التطبيق» البديلُ عن DB enum (C10): الفراغُ يعود إلى
        // `internal` (مشروعٌ بلا جمهورٍ صريحٍ داخليٌّ لا يتحوّل بالسهو)، وأيُّ قيمةٍ
        // خارج allowlist تُرفض قبل الكتابة. إضافةُ قيمةٍ مستقبلاً سطرٌ هنا لا ALTER.
        static::saving(function (self $p): void {
            if ($p->audience === null || $p->audience === '') {
                $p->audience = self::AUDIENCE_INTERNAL;
            }
            if (! in_array($p->audience, self::AUDIENCES, true)) {
                throw new \InvalidArgumentException("جمهورُ مشروعٍ غيرُ صالح: {$p->audience}");
            }
        });
    }

    /**
     * مشروعٌ خارجيّ (Work OS · الطور D) — منسوبٌ لعميلٍ فله غرفتاه المنفصلتان.
     * الكاشفُ العمليُّ هو وجودُ `client_id` (المشاريعُ القائمةُ من سكّة العرض تحمله
     * قبل أن يُملأ `audience`)، ويُشدّ بجمهورٍ صريحٍ `client` متى وُسم.
     */
    public function isExternal(): bool
    {
        return $this->client_id !== null || $this->audience === self::AUDIENCE_CLIENT;
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
