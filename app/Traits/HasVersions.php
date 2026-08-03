<?php

namespace App\Traits;

use App\Models\RecordVersion;
use Illuminate\Support\Facades\Auth;

/** إصدارات السجلات: رقم يتصاعد تلقائياً + لقطة كاملة لكل حفظ، مع استرجاع أي نسخة */
trait HasVersions
{
    protected static function bootHasVersions(): void
    {
        // v2.118: كانت arrow fn تُرجع قيمة التعيين (1) — وأحداث creating «موقِفة»:
        // أول ردٍّ غير null يقطع السلسلة، فكل مستمع creating يُسجَّل بعد هذا الـtrait
        // كان يُخرَس بصمت. مغلقة ببدنٍ صريح تُرجع null فلا توقف أحداً بعدها.
        static::creating(function ($m) {
            $m->version = $m->version ?? 1;
        });

        // نزيد الرقم فقط عند تغيّر فعلي — الحفظ بلا تغيير لا ينشئ نسخة جديدة
        static::updating(function ($m) {
            if ($m->isDirty()) {
                $m->version = ((int) $m->version) + 1;
            }
        });

        static::saved(function ($m) {
            $version = (int) ($m->version ?? 1);

            // حارس ضد التكرار: الحفظ بلا تغيير يُطلق saved دون زيادة الرقم
            $exists = RecordVersion::where('module', static::MODULE)
                ->where('record_id', $m->getKey())
                ->where('version', $version)
                ->exists();
            if ($exists) {
                return;
            }

            /*
             * **بين الفحص والإدراج نافذة**: كاتبٌ آخر يسبقنا فيها فيقع خرقُ قيد
             * التفرّد `(module, record_id, version)` — و`create()` يرمي، فيسقط
             * **الحفظُ كلُّه** بخطأ ٥٠٠ على مستخدمٍ لم يفعل شيئاً خاطئاً. اللقطةُ
             * سجلٌّ تاريخيّ لا يُكرَّر: من سبق كتبها، ويكفي. `insertOrIgnore`
             * يجعل الإدراج مُحتمِلاً للتزاحم بدل أن يعاقب الخاسر.
             */
            \Illuminate\Support\Facades\DB::table('record_versions')->insertOrIgnore([
                'module'     => static::MODULE,
                'record_id'  => $m->getKey(),
                'version'    => $version,
                'snapshot'   => json_encode($m->auditable(), JSON_UNESCAPED_UNICODE),
                'changed_by' => Auth::id(),
                'created_at' => now(),
            ]);
        });
    }

    public function versions()
    {
        return RecordVersion::where('module', static::MODULE)
            ->where('record_id', $this->getKey())
            ->orderByDesc('version');
    }

    /**
     * استعادةُ لقطةٍ قديمة.
     *
     * **وضعُ الحقول يسري هنا كما في نموذج التعديل**: اللقطةُ تحمل كل عمود، وكان
     * `fill()` يكتبها جميعاً — فمن حقلُ الراتب عنده «مخفيّ» أو «قراءة فقط»
     * يستعيد نسخةً فيُعيد كتابة راتبٍ لا يراه أصلاً، بضغطةٍ واحدةٍ لا يُقرأ فيها
     * ما يُكتب. ما لا يملك المستخدم كتابته لا يُستعاد، ويبقى على قيمته الحالية.
     */
    public function restoreVersion(int $version, $user = null): bool
    {
        $v = $this->versions()->where('version', $version)->first();
        if (! $v) return false;

        $data = collect($v->snapshot)->except(['id', 'version', 'created_at', 'updated_at', 'deleted_at']);

        $module = static::MODULE;
        $user = $user ?? Auth::user();
        if ($user && ! $user->role?->is_owner && ($def = hub_mod($module))) {
            // خريطةُ عمود ← مفتاح الحقل: اللقطة بأعمدة القاعدة وقواعد الحقول بمفاتيحها
            $locked = [];
            foreach ($def['fields'] ?? [] as $f) {
                if (hub_field_mode($user, $module, (string) ($f['key'] ?? '')) !== '') {
                    $locked[] = (string) ($f['col'] ?? $f['key'] ?? '');
                }
            }
            $data = $data->except(array_filter($locked));
        }

        $this->fill($data->all());

        return $this->save();
    }
}
