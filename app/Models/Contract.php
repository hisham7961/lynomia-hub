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
        // ولا شاشةَ تصل إليها لتُغلقها يدوياً لأن عقدها غاب من كل القوائم.
        // **والحالةُ السابقة تُختَم قبل الإلغاء** كي تعود عند الاستعادة: التزامٌ
        // قانونيّ كان «متأخراً» لا يعود «قائماً» فيُغيّر معناه — يعود كما كان.
        static::deleting(function (self $c) {
            $open = \Illuminate\Support\Facades\DB::table('contract_obligations')
                ->where('contract_id', $c->id)
                ->whereNotIn('status', ['مكتمل', 'ملغي'])
                ->get(['id', 'status', 'meta']);
            foreach ($open as $o) {
                $meta = json_decode((string) $o->meta, true) ?: [];
                $meta['cancelled_by_contract_delete'] = $o->status;
                \Illuminate\Support\Facades\DB::table('contract_obligations')->where('id', $o->id)
                    ->update(['status' => 'ملغي',
                        'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
            }
        });

        // **الاستعادةُ تُعيد ما أُلغي بالحذف** (تناظرَ LeaveRequest): كلُّ التزامٍ
        // يحمل ختمَ الإلغاء يعود لحالته السابقة ويُمسح الختم — فلا يسقط التزامٌ
        // قانونيّ من كل رادارٍ بلا أثرٍ حين يُستعاد عقدُه.
        static::restored(function (self $c) {
            $stamped = \Illuminate\Support\Facades\DB::table('contract_obligations')
                ->where('contract_id', $c->id)->get(['id', 'meta']);
            foreach ($stamped as $o) {
                $meta = json_decode((string) $o->meta, true) ?: [];
                if (! array_key_exists('cancelled_by_contract_delete', $meta)) continue;
                $prev = (string) $meta['cancelled_by_contract_delete'];
                unset($meta['cancelled_by_contract_delete']);
                \Illuminate\Support\Facades\DB::table('contract_obligations')->where('id', $o->id)
                    ->update(['status' => $prev,
                        'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null, 'updated_at' => now()]);
            }
        });
    }

    /**
     * الحذفُ وإلغاءُ الالتزامات **صفقةٌ واحدة**.
     *
     * خطّافُ `deleting` أعلاه يُلغي التزاماتِ العقد ثم يمضي الحذف — وكلاهما
     * كتابتان منفصلتان بلا معاملة. فتعثُّرُ الحذف بعد الإلغاء (قفلٌ، قيدٌ
     * مرجعيّ، انقطاع) يترك **التزاماتٍ ملغاةً لعقدٍ قائم**: التزامٌ قانونيّ
     * سقط من رادار المتابعة ولا شيء يقول لماذا. اللفُّ هنا يغطّي `destroy`
     * والحذفَ الجماعيّ وأيّ نداءٍ آخر دفعةً واحدة، على نمط `LeaveRequest::save`.
     */
    public function delete()
    {
        return \Illuminate\Support\Facades\DB::transaction(fn () => parent::delete());
    }

    /**
     * تخصيص رقم الوثيقة يتصادم عند التزامن: معالجان يقرآن أعلى تسلسلٍ معاً
     * فيولّدان الرقم نفسه، وحلقةُ الوجود في nextDocNo تفحص المُثبَت لا المُدرَج
     * قيد التنفيذ — فيقع خرقُ القيد الفريد (500 على MySQL). نعيد المحاولة بترقيمٍ
     * جديد بدل إفشال الإنشاء (القفل الصفّيّ لا يسلسل عبر الاتصالات في كل محرّك).
     */
    public function save(array $options = []): bool
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return parent::save($options);
            } catch (\Illuminate\Database\QueryException $e) {
                if ($this->exists || $attempt >= 5 || ! self::isDupDocNo($e)) throw $e;
                $this->doc_no = self::nextDocNo();   // رقمٌ جديد ثم أعِد المحاولة
            }
        }
    }

    /** أهذا خطأُ خرقٍ للقيد الفريد على doc_no؟ (23000 على المحرّكين، والرسالة تسمّي العمود) */
    protected static function isDupDocNo(\Illuminate\Database\QueryException $e): bool
    {
        return (string) $e->getCode() === '23000'
            && str_contains(mb_strtolower($e->getMessage()), 'doc_no');
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
