<?php

namespace App\Console\Commands;

use App\Models\Flow;
use Illuminate\Console\Command;

/**
 * عدّة الانطلاق: مسارات عملٍ جاهزة تغطي دورة حياة المشروع المتوقعة —
 * مهام وتذاكر وعملاء وعروض وفواتير ومشاريع وإجازات وموظفين ومشتريات وتغييرات.
 *
 * ليست بياناتٍ تجريبية: تُنشأ مرةً واحدة (المطابقة بالاسم فلا تكرار) وتبقى
 * ليُعدّلها المستخدم — يُبدّل المستلمين والمسؤولين من شاشة «مسارات العمل».
 * كل القيم (الوحدات، الأحداث، الحالات، مفاتيح الشروط) من سجل الوحدات حرفياً.
 */
class HubFlowsStarter extends Command
{
    protected $signature = 'hub:flows-starter';
    protected $description = 'إنشاء مسارات عمل انطلاقية جاهزة (لا يكرر الموجود بالاسم)';

    /** [الاسم، الوحدة، الحدث، الحالة الهدف، [شرط]، [الإجراءات]] */
    protected const FLOWS = [
        // ── المهام ──
        ['🚨 مهمة عاجلة جديدة', 'tasks', 'created', null,
            ['priority', 'eq', 'عاجلة'],
            [['type' => 'notify', 'to' => 'owners', 'text' => '🚨 مهمة عاجلة: {_display} — أنشأها {_by}']]],
        ['✅ إشعار إنجاز مهمة', 'tasks', 'status', 'منجزة', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '✅ أُنجزت المهمة: {_display}']]],
        // ── التذاكر ──
        ['🎫 تصعيد تذكرة عاجلة', 'tickets', 'created', null,
            ['priority', 'has', 'عاجلة'],
            [['type' => 'notify', 'to' => 'owners', 'text' => '🎫 تذكرة عاجلة جديدة: {_display}']]],
        ['🟢 تم حل تذكرة', 'tickets', 'status', 'تم الحل', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🟢 حُلّت التذكرة: {_display}']]],
        // ── العملاء ──
        ['👋 ترحيب بعميل جديد', 'clients', 'created', null, null,
            [['type' => 'task', 'text' => 'التواصل الترحيبي مع العميل الجديد: {_display}']]],
        ['🏆 عميل ربحناه', 'clients', 'status', 'فوز', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🏆 فوز! صار {_display} عميلاً'],
             ['type' => 'task', 'text' => 'فتح ملف التعاقد والتشغيل للعميل {_display}']]],
        // ── عروض الأسعار ──
        ['📝 عرض سعر قُبل — ابدأ التعاقد', 'quotes', 'status', 'مقبول', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '📝 قُبل عرض السعر {_display}'],
             ['type' => 'task', 'text' => 'إعداد العقد وبدء التنفيذ لعرض {_display}']]],
        // ── المالية ──
        ['💰 فاتورة سُدّدت', 'fin', 'status', 'مدفوعة', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '💰 سُدّدت: {_display}']]],
        ['⏰ فاتورة متأخرة — تحصيل', 'fin', 'status', 'متأخرة', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '⏰ تأخرت: {_display}'],
             ['type' => 'task', 'text' => 'متابعة تحصيل الفاتورة المتأخرة {_display}']]],
        ['💸 مستند مالي كبير', 'fin', 'created', null,
            ['total', 'gt', '500'],
            [['type' => 'notify', 'to' => 'owners', 'text' => '💸 مستند مالي كبير ({total}): {_display}']]],
        // ── المشاريع ──
        ['🎉 مشروع اكتمل', 'projects', 'status', 'مكتمل', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🎉 اكتمل المشروع: {_display}'],
             ['type' => 'task', 'text' => 'التسليم النهائي وطلب تقييم العميل لمشروع {_display}']]],
        // ── الموارد البشرية ──
        ['🗓️ طلب إجازة جديد', 'leaves', 'created', null, null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🗓️ طلب جديد: {_display}']]],
        ['✔️ إجازة اعتُمدت', 'leaves', 'status', 'معتمد', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '✔️ اعتُمد الطلب: {_display}']]],
        ['🧳 موظف جديد — تجهيز', 'hr', 'created', null, null,
            [['type' => 'task', 'text' => 'تجهيز الحساب والعُهدة والتعريف للموظف الجديد {_display}']]],
        // ── المشتريات والتغييرات والأفكار ──
        ['🛒 طلب شراء بانتظار الاعتماد', 'purchases', 'status', 'بانتظار الاعتماد', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🛒 بانتظار اعتمادك: {_display}']]],
        ['⚠️ تغيير عالي الخطورة', 'changes', 'created', null,
            ['risk', 'has', 'عالي'],
            [['type' => 'notify', 'to' => 'owners', 'text' => '⚠️ تغيير عالي الخطورة مقترح: {_display}']]],
        ['💡 فكرة اعتُمدت — للتنفيذ', 'ideas', 'status', 'معتمدة', null,
            [['type' => 'task', 'text' => 'تخطيط تنفيذ الفكرة المعتمدة: {_display}']]],
        // ── الحوادث والتشغيل التقني ──
        ['🚨 حادث حرج — غرفة عمليات', 'incidents', 'created', null,
            ['severity', 'eq', 'حرج'],
            [['type' => 'notify', 'to' => 'owners', 'text' => '🚨 حادث حرج مفتوح: {_display}'],
             ['type' => 'task', 'text' => 'غرفة عمليات فورية للحادث الحرج: {_display}']]],
        ['🟢 حادث استُعيد', 'incidents', 'status', 'مُستعاد', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🟢 استُعيدت الخدمة: {_display}']]],
        ['💥 نشر فشل — تحقيق', 'deploys', 'status', 'فشل', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '💥 فشل النشر: {_display}'],
             ['type' => 'task', 'text' => 'تحقيق فشل النشر وخطة الإصلاح: {_display}']]],
        ['↩️ نشر متراجع عنه', 'deploys', 'status', 'متراجع عنه', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '↩️ تُرُوجع عن النشر: {_display}']]],
        ['⚠️ مشكلة/خطر حرج', 'issues', 'created', null,
            ['severity', 'has', 'حرج'],
            [['type' => 'notify', 'to' => 'owners', 'text' => '⚠️ خطرٌ حرج سُجّل: {_display}']]],
        ['✅ مشكلة حُلّت', 'issues', 'status', 'محلولة', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '✅ حُلّت: {_display}']]],
        // ── التصميم والطلبات والمزايا ──
        ['🎨 تصميم بانتظار مراجعتك', 'designs', 'status', 'بانتظار مراجعة', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🎨 تصميم جاهز للمراجعة: {_display}']]],
        ['🖼️ تصميم اكتمل', 'designs', 'status', 'جاهز', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🖼️ اكتمل التصميم: {_display}']]],
        ['📨 طلب وارد جديد', 'requests', 'created', null, null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '📨 طلب وارد جديد: {_display}']]],
        ['🚀 ميزة نُشرت', 'feats', 'status', 'منشورة', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🚀 نُشرت الميزة: {_display}']]],
        // ── التوظيف ──
        ['🤝 مرشح بلغ العرض الوظيفي', 'recruit', 'status', 'عرض وظيفي', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🤝 مرشح وصل مرحلة العرض: {_display}']]],
        ['🎉 مرشح عُيّن — تجهيز', 'recruit', 'status', 'تم التعيين', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🎉 تم تعيين: {_display}'],
             ['type' => 'task', 'text' => 'إعداد العقد والتجهيز للمعيَّن الجديد {_display}']]],
        // ── الامتثال والأهداف والقرارات ──
        ['📋 امتثال يتطلب إجراء', 'compliance', 'status', 'مطلوب إجراء', null,
            [['type' => 'task', 'text' => 'إجراء التزام مطلوب: {_display}']]],
        ['🔴 امتثال متأخر', 'compliance', 'status', 'متأخر', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🔴 التزام متأخر — خطر غرامة: {_display}'],
             ['type' => 'task', 'text' => 'معالجة الالتزام المتأخر فوراً: {_display}']]],
        ['📉 هدف OKR متعثر', 'okrs', 'status', 'متعثر', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '📉 هدف متعثر يحتاج تدخلاً: {_display}']]],
        ['🧭 قرار متعثر التنفيذ', 'decisions', 'status', 'متعثر', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🧭 قرارٌ تعثر تنفيذه: {_display}']]],
        // ── المخزون والاشتراكات والدومينات ──
        ['📦 مخزون نفد — إعادة طلب', 'stock', 'status', 'نفد', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '📦 نفد المخزون: {_display}'],
             ['type' => 'task', 'text' => 'إعادة طلب صنفٍ نفد: {_display}']]],
        ['🟠 مخزون منخفض', 'stock', 'status', 'منخفض', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🟠 انخفض المخزون: {_display}']]],
        ['🔁 اشتراك انتهى — قرار تجديد', 'subs', 'status', 'منتهي', null,
            [['type' => 'task', 'text' => 'قرار تجديد أو إنهاء الاشتراك: {_display}']]],
        ['🔐 شهادة SSL انتهت', 'domains', 'status', 'منتهي', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🔐 شهادة SSL منتهية: {_display}'],
             ['type' => 'task', 'text' => 'تجديد شهادة SSL للدومين {_display}']]],
    ];

    public function handle(): int
    {
        $made = 0;
        foreach (self::FLOWS as [$name, $module, $event, $statusTo, $cond, $actions]) {
            if (! config("hub.modules.{$module}")) continue;   // وحدة أُزيلت مستقبلاً؟ نتخطى بأمان
            if (Flow::where('name', $name)->exists()) continue;

            Flow::create([
                'name' => $name, 'module' => $module, 'event' => $event,
                'status_to' => $statusTo,
                'cond_field' => $cond[0] ?? null, 'cond_op' => $cond[1] ?? null, 'cond_value' => $cond[2] ?? null,
                'actions' => $actions, 'enabled' => true,
            ]);
            $made++;
        }

        $this->info($made
            ? "أُنشئ {$made} مسار عمل انطلاقي — عدّل المستلمين والمسؤولين من شاشة مسارات العمل"
            : 'كل مسارات الانطلاق موجودة أصلاً — لم يُكرَّر شيء');

        return self::SUCCESS;
    }
}
