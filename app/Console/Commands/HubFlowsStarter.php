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
