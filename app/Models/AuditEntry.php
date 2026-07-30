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
    protected static function booted(): void
    {
        static::creating(function (self $m) {
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
                \App\Support\ErrorLog::capture('php',
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
