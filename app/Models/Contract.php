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

/** العقود والالتزامات */
class Contract extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'contracts';
    public const MODULE = 'contracts';
    public const DISPLAY = 'title';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'value' => 'decimal:3',
        'date_start' => 'date',
        'date_end' => 'date',
        'notice' => 'decimal:3',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    /**
     * v2.118: ترقيم رسمي تلقائي عند الإنشاء إن تُرك فارغاً — الصيغة من الإعدادات
     * (contracts.doc_no_format، الافتراضي CTR-{YEAR}-{SEQ}) بتسلسل سنوي، وkind
     * الافتراضي «أصلي» (الملاحق والتجديدات تضبطه صراحةً).
     */
    protected static function booted(): void
    {
        static::creating(function (self $c) {
            $c->kind = $c->kind ?: 'أصلي';
            if (! $c->doc_no) $c->doc_no = self::nextDocNo();
        });

        // حذف العقد يُغلق التزاماته المفتوحة: كانت تبقى «قائمة» بمرجعٍ لسجل
        // محذوف فتُنذر للأبد في رادار المركز القانوني والموجز اليومي ولوحة CEO —
        // ولا شاشةَ تصل إليها لتُغلقها يدوياً لأن عقدها غاب من كل القوائم
        static::deleting(function (self $c) {
            \Illuminate\Support\Facades\DB::table('contract_obligations')
                ->where('contract_id', $c->id)
                ->whereNotIn('status', ['مكتمل', 'ملغي'])
                ->update(['status' => 'ملغي', 'updated_at' => now()]);
        });
    }

    public static function nextDocNo(): string
    {
        $format = (string) (setting('contracts.doc_no_format') ?: 'CTR-{YEAR}-{SEQ}');
        $year = now()->format('Y');
        // آخر تسلسل للسنة الحالية من الأرقام المولدة بالصيغة نفسها
        $prefix = str_replace(['{YEAR}', '{SEQ}'], [$year, ''], $format);
        $last = self::withTrashed()->where('doc_no', 'like', $prefix . '%')
            ->orderByDesc('doc_no')->value('doc_no');
        $n = $last ? ((int) preg_replace('/\D/', '', substr($last, strlen($prefix)))) + 1 : 1;
        do {
            $candidate = str_replace(['{YEAR}', '{SEQ}'], [$year, sprintf('%04d', $n)], $format);
            $n++;
        } while (self::withTrashed()->where('doc_no', $candidate)->exists());

        return $candidate;
    }

    /** العقد الأصل (لملحق أو تجديد) وسلسلة فروعه */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function amendments()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function signRequests()
    {
        return $this->hasMany(\App\Models\SignRequest::class, 'contract_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Client::class, 'client_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Company::class, 'company_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project::class, 'project_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'owner_id');
    }
}
