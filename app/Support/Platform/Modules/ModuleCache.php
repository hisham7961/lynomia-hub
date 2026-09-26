<?php

namespace App\Support\Platform\Modules;

use Illuminate\Database\Eloquent\Model;

/**
 * طرائقُ نُقلت من `ModuleController` بلا تغيير (docs/REORG_PLAN.md §R6) — والمتحكّمُ يفوّض
 * إليها بالتوقيعِ والظهورِ نفسَيهما، فالورثةُ العشرة (`V1Controller` ومتحكّماتُ الجوال الثمانية تحته · `ApprovalDecisionController`)
 * لا يتغيّرون؛ وإعادةُ `MobileResourceController` تعريفَ `buildQuery` نافذةٌ كما كانت — لا طريقةَ منقولةً
 * أخرى تناديها، والمفوِّضُ يصله `parent::buildQuery`.
 */
final class ModuleCache
{
    /**
     * إبطالُ الكاش المشتقّ (نسبة الإنجاز + ربحية المشروع + أجور الساعة) — يُشارَك
     * بين الحفظ **والحذف والاستعادة**: حذفُ مهمةٍ أو مستندٍ ماليّ يغيّر الحساب كما
     * الحفظُ، وكانا لا يُبطلانه فيبقى progress/pl قديماً حتى انتهاء عمر الكاش.
     */
    public static function bustDerivedCache(string $module, Model $m): void
    {
        if (in_array($module, ['tasks', 'feats'], true) && ($pid = $m->project_id ?? null)) {
            \Illuminate\Support\Facades\Cache::forget('hub:progress:' . $pid);
        }

        // ربحية المشروع: أي مدخل من مدخلاتها يُبطل حسابها المخبأ فوراً
        if (in_array($module, ['tasks', 'fin', 'servers', 'subs', 'purchases', 'projects'], true)) {
            $pid = $module === 'projects' ? $m->id : ($m->project_id ?? null);
            if ($pid) \Illuminate\Support\Facades\Cache::forget('pl:' . $pid);
        }

        // أجور الساعة مشتقة من رواتب الملفات الوظيفية — تعديلها يُبطل الجدول كله
        if ($module === 'hr') \Illuminate\Support\Facades\Cache::forget('cost:rates');
    }

    /** نسف كاش نسبة الإنجاز عند تغيّر مهمة أو بند خطة + ختم وقت حل التذاكر (SLA) */
    public static function bustProgress(string $module, Model $m): void
    {
        \App\Support\Platform\Modules\ModuleCache::bustDerivedCache($module, $m);

        // حالة الصنف تُشتق من كميته وحدّه فور أي حفظ — نفد/منخفض/متاح
        if ($module === 'stock' && $m instanceof \App\Models\StockItem) hub_stock_sync($m);

        // الحقول المقيسة (متابعون، إعجابات، تحميلات، تقييم) تُسجَّل نقطةً في
        // السلسلة الزمنية مع كل حفظ — الحقل وحده يدهس ما قبله فلا يبقى نمو
        \App\Support\Ops\Metrics::capture($module, $m);

        // سياسةٌ أو مقالٌ إلزامي تغيّرت نسخته: الإقرارات القديمة تسقط ويُعاد
        // الإعلان. بلا هذا يبقى الجميع «مُقِرّين» بنسخةٍ ماتت — امتثالٌ كاذب.
        // دورة الإقرار (إسقاط عند تحديث النسخة + إعادة الإعلان) انتقلت إلى
        // النموذج نفسه — Policy::booted و KbArticle::booted — فتعمل أياً كان
        // مصدر التغيير لا من هذا المسار وحده.

        // مزامنة الأصل مع صيانته: قيد التنفيذ تضعه «صيانة»، والمكتملة تختم
        // «آخر صيانة» وتعيده «قيد الاستخدام» — كان الحقلان يدويين متناقضين
        if ($module === 'assetlog' && $m->asset_id && ($asset = \App\Models\Asset::find($m->asset_id))) {
            // (تدقيقُ الطور النهائيّ · §39/§63) تغييرُ حالةِ الأصلِ يمرّ بالمحرّكِ الواحد
            // `Custody::transition` (تدقيقٌ + صفُّ تاريخٍ في asset_custody + مزامنةُ النقطة) لا
            // بكتابةٍ صامتةٍ (saveQuietly) تتخطّى العهدةَ وتُسقط الأثر؛ والانتقالُ غيرُ المشروع
            // (أصلٌ نهائيٌّ مباعٌ/مستبعد) يُتخطّى بلا إحياءٍ زائف. «آخرُ صيانة» حقلٌ حرٌّ يُختَم كما هو.
            $to = null;
            if ((string) $m->status === 'قيد التنفيذ') {
                $to = 'صيانة';
            } elseif ((string) $m->status === 'مكتملة') {
                if ((string) $asset->maint !== (string) $m->date) { $asset->maint = $m->date; $asset->saveQuietly(); }
                if (\App\Support\Assets\Custody::canonicalStatus($asset->status) === 'صيانة') $to = 'قيد الاستخدام';
            }
            if ($to !== null
                && \App\Support\Assets\Custody::canonicalStatus($asset->status) !== \App\Support\Assets\Custody::canonicalStatus($to)
                && \App\Support\Assets\Custody::canTransition($asset->status, $to)) {
                \App\Support\Assets\Custody::transition($asset, $to, now()->toDateString(), 'مزامنةٌ من سجل الصيانة');
            }
        }

        // إخلاء العهدة عند المغادرة: منتهية خدمته وبعهدته أصول ⟵ مهمة استرداد
        // واحدة (لا تتكرر) تسمّي الأصول — كانت العهدة تُنسى مع المغادر
        if ($module === 'hr' && (string) $m->status === 'منتهية خدمته' && $m->user_id) {
            $held = \App\Models\Asset::whereNull('deleted_at')
                ->where('holder_id', $m->user_id)->get(['id', 'name']);
            if ($held->isNotEmpty()) {
                $title = 'استرداد عهدة: ' . $m->name;
                $exists = \App\Models\Task::whereNull('deleted_at')->where('title', $title)
                    ->whereNotIn('status', ['منجزة', 'مكتملة', 'ملغاة'])->exists();
                if (! $exists) {
                    \App\Models\Task::create([
                        'title' => $title, 'status' => 'جديدة', 'priority' => 'عالية',
                        'company_id' => $m->company_id,
                        'description' => "الموظف منتهية خدمته وبعهدته:\n- "
                            . $held->pluck('name')->implode("\n- ")
                            . "\n\nاسترد الأصول ووثّق التسليم بإقرارٍ موقّع ثم حوّل حالتها إلى «متاح».",
                    ]);
                }
            }
        }

        if ($module === 'tickets') {
            $meta = (array) ($m->meta ?? []);
            $closed = in_array((string) $m->status, ['تم الحل', 'مغلقة'], true);
            if ($closed && empty($meta['resolved_at'])) {
                $meta['resolved_at'] = now()->toIso8601String();
                $m->meta = $meta;
                $m->saveQuietly();
            } elseif (! $closed && ! empty($meta['resolved_at'])) {
                unset($meta['resolved_at']);          // أُعيد فتحها
                // ── Control Plane: Phase 7 (WP-7.4) ── الارتدادُ كان يُمحى بصمت:
                // عدّادٌ تراكميّ على meta يقرؤه قارئُ الاختناقات
                // (ExecutionStats::bottlenecks) — لا عمودَ ولا جدولَ جديد.
                $meta['reopened'] = (int) ($meta['reopened'] ?? 0) + 1;
                $m->meta = $meta ?: null;
                $m->saveQuietly();
            }
        }
    }
}
