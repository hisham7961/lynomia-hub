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

    /** البصمة القانونية: الحقول الجوهرية بترتيب ثابت — تُعاد للتحقق لاحقاً كما هي */
    public function canonical(): string
    {
        $raw = $this->getAttributes();   // القيم كما ستُخزن (JSON نصي للمصفوفات)

        return implode('|', array_map(
            fn ($k) => $k . '=' . (string) ($raw[$k] ?? ''),
            ['user_id', 'action', 'module', 'record_id', 'name', 'reason', 'before', 'after', 'created_at']
        ));
    }
}
