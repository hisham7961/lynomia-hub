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
                for ($i = 0; $i < 3; $i++) {
                    $head = (string) \Illuminate\Support\Facades\DB::table('audit_chain')->where('id', 1)->value('head');
                    if ($head === '') return;                     // الهجرة لم تطبق بعد — لا نkسر الكتابة
                    $hash = hash('sha256', $head . '|' . $m->canonical());
                    if (\Illuminate\Support\Facades\DB::table('audit_chain')
                        ->where('id', 1)->where('head', $head)->update(['head' => $hash])) {
                        $m->prev_hash = $head;
                        $m->hash = $hash;
                        return;
                    }
                }
            } catch (\Throwable $e) {
                // التدقيق نفسه لا يفشل بسبب السلسلة
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
     * القيم تؤخذ خاماً كما ستُخزن (JSON نصي للمصفوفات) فتتطابق بعد القراءة من القاعدة.
     */
    public function canonical(bool $legacy = false): string
    {
        $raw = $this->getAttributes();

        return ($legacy ? '' : 'v2|') . implode('|', array_map(
            fn ($k) => $k . '=' . (string) ($raw[$k] ?? ''),
            $legacy ? self::SEALED_V1 : self::SEALED
        ));
    }
}
