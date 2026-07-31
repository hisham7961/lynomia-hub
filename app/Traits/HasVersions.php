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

            RecordVersion::create([
                'module'     => static::MODULE,
                'record_id'  => $m->getKey(),
                'version'    => $version,
                'snapshot'   => $m->auditable(),
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

    public function restoreVersion(int $version): bool
    {
        $v = $this->versions()->where('version', $version)->first();
        if (! $v) return false;
        $data = collect($v->snapshot)->except(['id', 'version', 'created_at', 'updated_at', 'deleted_at'])->all();
        $this->fill($data);

        return $this->save();
    }
}
