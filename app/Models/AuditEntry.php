<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditEntry extends Model
{
    protected $table = 'audits';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['before' => 'array', 'after' => 'array', 'snapshot' => 'array', 'value' => 'array', 'flags' => 'array'];

    /**
     * سلسلة تجزئة غير قابلة للعبث: hash = sha256(prev_hash | البصمة القانونية للصف).
     * الرأس في جدول audit_chain يُقدَّم بتحديث مشروط (تفاؤلي) فلا يتفرع تحت التزامن.
     */
    /** أعمدة جدول التدقيق كما هي فعلاً في القاعدة — تُقرأ مرة وتُخبّأ داخل العملية */
    protected static ?array $liveColumns = null;

    /** يُعيد قراءة مخطط الجدول — تستدعيه الاختبارات بعد تغيير الأعمدة */
    public static function forgetColumnCache(): void
    {
        self::$liveColumns = null;
    }

    /** أعمدةُ الجدولِ للقرّاءِ الخارجيّين (حارسُ `hub_audit`) — نفسُ المخبأ */
    public static function liveColumnNames(): array
    {
        return self::liveColumns();
    }

    protected static function liveColumns(): array
    {
        if (self::$liveColumns === null) {
            try {
                self::$liveColumns = \Illuminate\Support\Facades\Schema::getColumnListing((new self)->getTable());
            } catch (\Throwable $e) {
                self::$liveColumns = [];        // تعذّر فحص المخطط: لا نُجرّد شيئاً
            }
        }

        return self::$liveColumns;
    }

    /**
     * **تقديمُ الرأس وكتابةُ القيد ذرّةٌ واحدة.**
     *
     * البصمةُ تُحسب ويُقدَّم رأسُ السلسلة في `creating`، ثم يقع الإدراج. فإن فشل
     * الإدراج — عمودٌ يرفض قيمة، قاعدةٌ تحت ضغط، مستمعٌ يرمي — بقي الرأس على
     * بصمةٍ **لا يحملها أي سجل**، فيقول الفحص بعدها إلى الأبد «⚠️ عبث — حُذفت
     * قيودٌ من الذيل». وإنذارُ تلاعبٍ كاذبٌ دائم أسوأ من لا إنذار: يُدرّب الجميع
     * على تجاهل الإشارة الوحيدة التي تهمّ.
     *
     * بلفّ الإدراج بمعاملة، يرتدّ تقديمُ الرأس مع ارتداد الصفّ فيبقيان متّسقين.
     *
     * **والختمُ المؤجَّل داخل معاملة العمل (AUD-07).** كان قفلُ صفِّ الرأس
     * (`lockForUpdate`) يُؤخذ في `creating` — فإن وقع القيدُ داخل معاملةِ عملٍ
     * أوسع (فاتورةٌ وقيودُها، استيرادٌ بمئات الصفوف) بقي القفلُ محمولاً **حتى
     * تلتزم المعاملةُ كلُّها**، فتصطفّ خلفه كلُّ كتابةٍ مُدقَّقةٍ في النظام.
     * الآن: داخل معاملةٍ مفتوحة يُدرَج القيدُ **بلا بصمة** ذرّةً مع العمل نفسه
     * (فلا يضيع إن التزم، ولا يبقى إن ارتدّ)، ويُختم بعد الالتزام في خطوةٍ
     * قصيرةٍ مستقلّة (`sealCommitted`) هي وحدها ما يمسك القفل. وخارج أيّ معاملة
     * يبقى الختمُ فورياً كما كان.
     *
     * فترتيبُ السلسلة = **ترتيبُ الالتزام** لا ترتيبُ `id` — وهو الترتيبُ الحقّ:
     * القيدُ لا يوجد لغيره قبل التزامه. والقيودُ في المعاملة الواحدة تُختم بترتيب
     * إدراجها (نداءاتُ ما بعد الالتزام تُنفَّذ بترتيب تسجيلها). والفاحصان يمشيان
     * الروابط (`prev_hash`) لا `id`، فكشفُ العبث باقٍ كما هو.
     */
    public function save(array $options = [])
    {
        if ($this->exists) return parent::save($options);

        $this->sealAfterCommit = $this->insideOpenTransaction();

        $saved = \Illuminate\Support\Facades\DB::transaction(fn () => parent::save($options));

        if ($saved && $this->sealAfterCommit && $this->getKey() !== null) {
            // يُسجَّل على المعاملة الخارجية نفسها: ارتدادُها يُسقط النداءَ مع الصفّ
            $this->getConnection()->afterCommit(fn () => self::sealCommitted($this));
        }

        return $saved;
    }

    /** هل الحفظُ داخل معاملةِ عملٍ مفتوحة؟ (معاملةُ غلافِ الاختبار لا تُحسب — كما يفعل `afterCommit`) */
    protected bool $sealAfterCommit = false;

    protected function insideOpenTransaction(): bool
    {
        $conn = $this->getConnection();
        if ($conn->transactionLevel() === 0) return false;

        $manager = app()->bound('db.transactions') ? app('db.transactions') : null;
        if (! $manager) return false;   // بلا مدير معاملات لا نداءَ بعد الالتزام — نختم فوراً كما كان

        return $manager->callbackApplicableTransactions()
            ->contains(fn ($t) => $t->connection === $conn->getName());
    }

    /**
     * ختمُ قيدٍ التزم بلا بصمة — الخطوةُ القصيرة الوحيدة التي تمسك قفلَ الرأس.
     *
     * تُعاد قراءةُ الصفّ من القاعدة فتُحسب البصمةُ على **المخزون** — وهو عينُ ما
     * يُعيد الفاحصُ حسابَه. و`whereNull('hash')` حارسُ ختمٍ مزدوج. وفشلُها لا يُسقط
     * العملَ الذي التزم أصلاً: يُسجَّل في مركز الأخطاء، ويبقى القيدُ بلا بصمة
     * فيُفشل `hub:audit-verify` صراحةً (حقبة `started_at`) — لا صمت.
     */
    public static function sealCommitted(self $entry): void
    {
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($entry) {
                $head = (string) \Illuminate\Support\Facades\DB::table('audit_chain')
                    ->where('id', 1)->lockForUpdate()->value('head');
                if ($head === '') return;                         // الهجرة لم تطبق بعد

                $row = self::query()->whereKey($entry->getKey())->whereNull('hash')->first();
                if (! $row) return;                               // حُذف أو خُتم — لا شيء يُختم

                $hash = hash('sha256', $head . '|' . $row->canonical());
                \Illuminate\Support\Facades\DB::table('audits')->where('id', $row->getKey())->whereNull('hash')
                    ->update(['prev_hash' => $head, 'hash' => $hash]);
                \Illuminate\Support\Facades\DB::table('audit_chain')->where('id', 1)->update(['head' => $hash]);

                // النسخةُ في الذاكرة تعكس المختوم (من يقرأ `->hash` بعد الالتزام)
                $entry->forceFill(['prev_hash' => $head, 'hash' => $hash])->syncOriginalAttributes(['prev_hash', 'hash']);
            }, 3);
        } catch (\Throwable $e) {
            \App\Support\Ops\ErrorLog::capture('php',
                'audit-chain: فشل ختم سجل تدقيق بعد الالتزام (' . $entry->action . '/' . $entry->module . ') — ' . $e->getMessage(),
                __FILE__, __LINE__);
        }
    }

    /** مهلةُ الختم المؤجَّل: قيدٌ بلا بصمةٍ أحدثُ منها «قيدَ الختم» لا «عبث» */
    public const SEAL_GRACE_SECONDS = 120;

    /**
     * خارجَ مهلة الختم: أقدمُ من المهلة **أو في المستقبل** — فالتاريخُ المستقبليُّ لا يختبئ في المهلة
     * (قيدٌ بلا بصمةٍ بتاريخٍ قادمٍ عبثٌ لا ختمٌ جارٍ).
     */
    public static function outsideSealGrace($q)
    {
        return $q->where('created_at', '<', now()->subSeconds(self::SEAL_GRACE_SECONDS))->orWhere('created_at', '>', now());
    }

    /** أقصى عمرٍ لقيدٍ يُستدرَك ختمُه — ما هو أقدمُ يبقى إنذاراً صريحاً لا يُختم آليّاً */
    public const SEAL_RECOVER_HOURS = 24;

    /**
     * **استدراكُ ختمٍ ضاع بعد الالتزام** — عمليّةٌ ماتت بين الالتزام ونداء الختم (نفادُ ذاكرة · مهلةٌ · قتلُ عامل)،
     * أو نداءٌ سابقٌ بعد الالتزام رمى فلم يبلغ نداءَ الختم. يُختم القيدُ الملتزمُ بلا بصمةٍ **بعد حقبة السلسلة وفي
     * آخر ٢٤ ساعة وأقدمَ من مهلة الختم** (فلا يسابق ختماً جارياً)، بترتيب `id`، بالقفل القصير نفسِه، ويُسجَّل كلُّ
     * استدراكٍ في مركز الأخطاء — فلا يمرّ صامتاً. وما هو أقدمُ من ٢٤ ساعة يبقى إنذاراً لـ`hub:audit-verify`:
     * الاستدراكُ للحادث القريب، لا لتبييض صفوفٍ مجهولةِ المصدر.
     */
    public static function sealPending(): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('audit_chain') || ! \App\Support\Platform\SchemaCache::hasColumn('audit_chain', 'started_at')) return 0;
        $epoch = \Illuminate\Support\Facades\DB::table('audit_chain')->where('id', 1)->value('started_at');
        if (! $epoch) return 0;
        $from = max((string) $epoch, now()->subHours(self::SEAL_RECOVER_HOURS)->toDateTimeString());

        $sealed = 0;
        self::query()->whereNull('hash')->where('created_at', '>=', $from)
            ->where('created_at', '<=', now()->subSeconds(self::SEAL_GRACE_SECONDS))
            ->orderBy('id')->limit(1000)->get()
            ->each(function (self $row) use (&$sealed) {
                self::sealCommitted($row);
                if ($row->hash !== null) $sealed++;
            });
        if ($sealed > 0) {
            \App\Support\Ops\ErrorLog::capture('php', "audit-chain: استُدرك ختمُ {$sealed} قيداً التزم بلا بصمة (ختمٌ مؤجَّلٌ لم يقع)", __FILE__, __LINE__);
        }

        return $sealed;
    }

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            // درعُ «النشر قبل الترحيل»: إن نُشر كودٌ يكتب عموداً لم تُطبَّق هجرته بعد
            // (مثل company_id)، لا يُسقِط قيدُ التدقيق العمليةَ الحقيقية — نُجرّد المجهول
            // بدل أن نكسر خروجَ المستخدم أو أيّ حفظ. البصمة لا تتأثر: الأعمدة المختومة
            // (SEALED) أساسيةٌ حاضرةٌ منذ البداية، والمُجرَّد إنما هو المشتقّ غير المختوم.
            if ($live = self::liveColumns()) {
                foreach (array_keys($m->getAttributes()) as $attr) {
                    if (! in_array($attr, $live, true)) unset($m->{$attr});
                }
            }

            // داخل معاملة عمل: يُدرَج بلا بصمة ويُختم بعد الالتزام (انظر save) — لا قفلَ هنا
            if ($m->sealAfterCommit) return;

            try {
                // قفل صف الرأس داخل معاملة قصيرة: القراءة والتقديم ذرّة واحدة —
                // كان تحديثاً تفاؤلياً بثلاث محاولات، ومن يخسرها كلها يُدرَج بلا بصمة بصمت
                \Illuminate\Support\Facades\DB::transaction(function () use ($m) {
                    $head = (string) \Illuminate\Support\Facades\DB::table('audit_chain')
                        ->where('id', 1)->lockForUpdate()->value('head');
                    if ($head === '') return;                     // الهجرة لم تطبق بعد — لا نكسر الكتابة
                    $hash = hash('sha256', $head . '|' . $m->canonical());
                    \Illuminate\Support\Facades\DB::table('audit_chain')->where('id', 1)->update(['head' => $hash]);
                    $m->prev_hash = $head;
                    $m->hash = $hash;
                }, 3);
            } catch (\Throwable $e) {
                // فشل استثنائي (قاعدة تحت ضغط مثلاً): الكتابة تمضي بلا بصمة كي لا يتعطل
                // العمل — لكن لا صمت بعد اليوم: يُسجَّل هنا، وhub:audit-verify يفشل صراحةً
                // على أي سجل بلا بصمة كُتب بعد بدء السلسلة (حقبة audit_chain.started_at)
                \App\Support\Ops\ErrorLog::capture('php',
                    'audit-chain: فشل ختم سجل تدقيق (' . $m->action . '/' . $m->module . ') — ' . $e->getMessage(),
                    __FILE__, __LINE__);
            }
        });
    }

    /** أعمدة البصمة: من هو، ماذا فعل، بأي سجل، ولماذا — **ومن أين** (الأعمدة الجنائية) */
    public const SEALED = ['user_id', 'action', 'module', 'record_id', 'project_id',
                           'name', 'reason', 'before', 'after', 'device', 'ip', 'created_at'];

    /** بصمة الجيل الأول (قبل ضم project_id وdevice وip) — للتحقق من السجلات القديمة فقط */
    public const SEALED_V1 = ['user_id', 'action', 'module', 'record_id',
                              'name', 'reason', 'before', 'after', 'created_at'];

    /**
     * البصمة القانونية: الحقول الجوهرية بترتيب ثابت — تُعاد للتحقق لاحقاً كما هي.
     *
     * `before` و`after` عمودا json، وMySQL يعيد صياغة المستند عند التخزين (مسافات
     * وترميز يونيكود وترتيب مفاتيح)، فبصمة تُحسب على النص الخام تنجح على sqlite
     * وتفشل على MySQL فيصرخ التحقق «معدَّل» على قاعدة سليمة تماماً. لذا يُعاد ترميز
     * الوثيقة صياغةً موحّدة (مفاتيح مرتبة، بلا هروب) فتتطابق على المحركين.
     *
     * @param string $mode  v2 (الحالية) · v2raw (نص خام — لسجلات كُتبت قبل التوحيد) · v1 (بلا الأعمدة الجنائية)
     */
    public function canonical(string $mode = 'v2'): string
    {
        $raw = $this->getAttributes();
        $keys = $mode === 'v1' ? self::SEALED_V1 : self::SEALED;
        $norm = $mode === 'v2';

        return ($mode === 'v1' ? '' : 'v2|') . implode('|', array_map(
            fn ($k) => $k . '=' . ($norm ? self::sealValue($k, $raw[$k] ?? '') : (string) ($raw[$k] ?? '')),
            $keys
        ));
    }

    /** صياغة موحّدة لأعمدة JSON — أي تمثيل للوثيقة نفسها يعطي النص نفسه */
    protected static function sealValue(string $key, $value): string
    {
        if (! in_array($key, ['before', 'after'], true) || $value === null || $value === '') {
            return (string) $value;
        }

        $data = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($data)) return (string) $value;

        self::ksortDeep($data);

        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected static function ksortDeep(array &$a): void
    {
        ksort($a);
        foreach ($a as &$v) if (is_array($v)) self::ksortDeep($v);
    }
}
