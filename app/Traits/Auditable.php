<?php

namespace App\Traits;

use App\Models\AuditEntry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/** يسجّل كل إضافة/تعديل/حذف مع صورة قبل وبعد وسبب التعديل والجهاز والـIP */
trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(fn ($m) => $m->writeAudit('إضافة', null, $m->auditable()));
        static::updated(function ($m) {
            $before = collect($m->getOriginal())->only(array_keys($m->getDirty()))->all();
            $after  = collect($m->getDirty())->except(['updated_at', 'search_vec'])->all();
            if ($after) $m->writeAudit('تعديل', $before, $after);
        });
        static::deleted(fn ($m) => $m->writeAudit('حذف', $m->auditable(), null));
    }

    public function auditable(): array
    {
        return collect($this->getAttributes())
            ->except(['search_vec', 'custom', 'meta'])
            // بالمحارف لا بالبايتات: `substr` تقطع الحرف العربي نصفين فتُنتج
            // UTF-8 فاسدةً يرفضها `json_encode` — فلا يُكتب القيد أصلاً
            ->map(fn ($v) => is_string($v) && mb_strlen($v) > 300 ? mb_substr($v, 0, 300) . '…' : $v)
            ->all();
    }

    public function writeAudit(string $action, ?array $before, ?array $after): void
    {
        AuditEntry::create([
            'user_id'    => Auth::id(),
            'action'     => $action,
            'module'     => static::MODULE ?? $this->getTable(),
            'record_id'  => $this->getKey(),
            'project_id' => $this->project_id ?? null,
            // شركة السجل تُحفظ مع القيد: العزل يُفرض على التدقيق كما على البيانات،
            // وجدول الشركات نفسه شركتُه هي سجلّه
            'company_id' => $this->company_id
                ?? ($this->getTable() === 'companies' ? $this->getKey() : null),
            // اسمُ القيد من عمود العرض — وقد يكون نصّاً طويلاً (وحدة التحديثات
            // عرضُها «ما أُنجز اليوم»)، فيُقصّ لعرض عموده لا يُرسل خاماً
            'name'       => hub_fit((string) (
                $this->{defined('static::DISPLAY') ? static::DISPLAY : 'name'} ?? ''
            ), hub_col_max('audits', 'name') ?? 300),
            'before'     => $before,
            'after'      => $after,
            // السبب يصل من نموذجٍ أو من API بلا حدٍّ في الخادم
            'reason'     => hub_fit(Request::input('_reason') ?: Request::header('X-Change-Reason'),
                hub_col_max('audits', 'reason') ?? 400),
            'device'     => hub_fit((string) Request::header('X-Device', Request::userAgent()), 200),
            'ip'         => Request::ip(),
            'created_at' => now(),
        ]);
    }
}