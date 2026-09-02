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
            $before = $m->auditRedact(collect($m->getOriginal())->only(array_keys($m->getDirty()))->all());
            $after  = $m->auditRedact(collect($m->getDirty())->except(['updated_at', 'search_vec'])->all());
            if ($after) $m->writeAudit('تعديل', $before, $after);
        });
        static::deleted(fn ($m) => $m->writeAudit('حذف', $m->auditable(), null));
    }

    /**
     * أعمدةٌ لا تُكتب قيمتُها في سجل التدقيق أبداً — تُستبدَل ببصمةٍ قصيرة.
     *
     * **ولماذا بصمةٌ لا حذفٌ ولا نجوم؟** الحذفُ يُخفي أنّ الحقلَ تغيّر أصلاً،
     * والنجومُ تتساوى فتقول «تغيّر» ولا تقول «إلى غيرِ ما كان». والبصمةُ تحفظ
     * السؤالَ التدقيقيَّ كاملاً — تغيّر أم لا، ومتى عاد إلى قيمةٍ سابقة — بلا
     * تسريبِ محرفٍ واحد.
     *
     * **والسببُ المباشر:** `VaultSecret::booted` تُرقّي سرّاً قديماً غيرَ مشفَّر
     * عند أوّل حفظ، فيصير `getOriginal()` هو **النصَّ الصريح** ويُكتب في
     * `audits.before`. و`before`/`after` من أعمدة بصمة التدقيق (`SEALED`)،
     * فمحوُهما بعد الكتابة يكسر السلسلةَ نفسَها — أي يُتلف الضمانَ لإخفاء
     * أثرِ خرقه. فالمنعُ عند المصدر هو العلاجُ الوحيد الذي لا يُتلف شيئاً.
     */
    public function auditSecretCols(): array
    {
        return (array) (defined('static::AUDIT_SECRET') ? static::AUDIT_SECRET : []);
    }

    /**
     * استبدالُ قيَم الأعمدة السرّية ببصمةٍ لا تُعكس.
     *
     * والبصمةُ تقع على **القيمة الصريحة** لا على تخزينها: تعميةُ Laravel بمتّجهٍ
     * ابتدائيّ عشوائيّ، فالسرُّ نفسُه يُنتج نصّاً مشفَّراً مختلفاً كلَّ مرة —
     * وبصمةٌ على المشفَّر تقول «تغيّر السرّ» في كل حفظٍ لأيّ حقلٍ آخر. فتُفكّ
     * التعميةُ للبصمة وحدها، وتُرمى القيمةُ فوراً.
     */
    public function auditRedact(array $data): array
    {
        foreach ($this->auditSecretCols() as $col) {
            if (! array_key_exists($col, $data)) continue;
            $v = $data[$col];
            if ($v === null || $v === '') continue;

            $plain = (string) $v;
            try { $plain = \Illuminate\Support\Facades\Crypt::decryptString($plain); }
            catch (\Throwable $e) { /* نصٌّ صريحٌ قديم — يُبصَم كما هو ولا يُكتب */ }

            $data[$col] = 'sha256:' . substr(hash('sha256', $plain), 0, 16);
        }

        return $data;
    }

    public function auditable(): array
    {
        return collect($this->auditRedact($this->getAttributes()))
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
            // hub_str قبل hub_fit (v2.324): `_reason` مصفوفةً كان يرمي TypeError
            // فيُسقط قيدَ التدقيق **ويُبقي الكتابة** — تعديلٌ يقع بلا أثرٍ في السجل
            'reason'     => hub_fit(hub_str(Request::input('_reason')) ?: hub_str(Request::header('X-Change-Reason')),
                hub_col_max('audits', 'reason') ?? 400),
            'device'     => hub_fit(hub_str(Request::header('X-Device', Request::userAgent())), 200),
            'ip'         => Request::ip(),
            // ربطُ الأثر بطلبه (v2.399): به يُجمَع ما كتبه طلبٌ واحد عبر التدقيق والصندوق الصادر والويبهوك
            'request_id' => \App\Support\Api::requestId(),
            'created_at' => now(),
        ]);
    }
}