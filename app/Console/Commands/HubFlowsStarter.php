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

    /**
     * التوسعة: بقية جوانب النظام — العقود والالتزامات والاجتماعات والأصول
     * وحركات المخزون والرواتب والقيود والنتائج الرئيسية والبنية التحتية
     * والتسويق والامتثال. تُنشأ **معطّلة** خلافاً للمجموعة الأولى: دفعةٌ بهذا
     * الحجم لو وُلدت مفعّلةً لأغرقت المالك بالإشعارات في أول يوم، فيُطفئها
     * كلها — والمطفأة المُراجَعة خيرٌ من المفعّلة المهجورة. تُراجَع وتُفعَّل
     * من شاشة المسارات بمجموعاتها.
     */
    protected const MORE = [
        // ── العقود والالتزامات ──
        ['📜 عقد صار ساري — ابدأ التنفيذ', 'contracts', 'contract.signed', null, null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '📜 صار العقد ساري المفعول: {_display}'],
             ['type' => 'task', 'text' => 'بدء تنفيذ العقد وتسجيل التزاماته: {_display}']]],
        ['📕 عقد انتهى', 'contracts', 'contract.expired', null, null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '📕 انتهى العقد: {_display}'],
             ['type' => 'task', 'text' => 'قرار تجديد العقد المنتهي أو إغلاق ملفه: {_display}']]],
        ['🔄 عقد دخل التجديد', 'contracts', 'status', 'قيد التجديد', null,
            [['type' => 'task', 'text' => 'إعداد مسودة التجديد ومراجعة بنودها: {_display}']]],
        ['🔴 التزام تعاقدي متأخر', 'obligations', 'status', 'متأخر', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🔴 التزام تعاقدي تأخر — خطر إخلال: {_display}'],
             ['type' => 'task', 'text' => 'معالجة الالتزام المتأخر فوراً: {_display}']]],
        ['✅ التزام اكتمل', 'obligations', 'status', 'مكتمل', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '✅ نُفّذ التزام العقد: {_display}']]],

        // ── الاجتماعات والحوكمة ──
        ['📝 اجتماع انعقد بلا محضر', 'meetings', 'status', 'بانتظار المحضر', null,
            [['type' => 'task', 'text' => 'توثيق محضر الاجتماع واستخراج قراراته: {_display}']]],
        ['✋ موافقة تنتظر الحسم', 'approvals', 'status', 'معلّق', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '✋ طلب موافقة ينتظر حسمك: {_display}']]],
        ['🚫 موافقة رُفضت', 'approvals', 'status', 'مرفوض', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🚫 رُفض الطلب: {_display}']]],
        ['📉 نتيجة رئيسية متعثرة', 'krs', 'status', 'متعثرة', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '📉 نتيجة رئيسية تعثرت — الهدف في خطر: {_display}']]],
        ['🏁 نتيجة رئيسية اكتملت', 'krs', 'status', 'مكتملة', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🏁 اكتملت النتيجة الرئيسية: {_display}']]],

        // ── المالية ──
        ['🔏 قيد يومية رُحّل', 'entries', 'status', 'مرحّل', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🔏 رُحّل قيد وقُفل: {_display}']]],
        ['🧾 مسيّر رواتب اعتُمد', 'payroll', 'status', 'معتمد', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🧾 اعتُمد مسيّر الرواتب: {_display}'],
             ['type' => 'task', 'text' => 'تنفيذ تحويل الرواتب للبنك: {_display}']]],
        ['💸 رواتب صُرفت', 'payroll', 'status', 'مدفوع', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '💸 صُرفت رواتب: {_display}']]],
        ['🏦 حساب بنكي جُمّد', 'banks', 'status', 'مجمّد', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🏦 حساب بنكي مجمّد — أثرٌ على السيولة: {_display}']]],
        ['🔁 مصروف متكرر انتهى', 'recur', 'status', 'منتهي', null,
            [['type' => 'task', 'text' => 'مراجعة المصروف المتكرر المنتهي وقرار تمديده: {_display}']]],
        ['📊 ميزانية أُغلقت', 'budgets', 'status', 'مغلقة', null,
            [['type' => 'task', 'text' => 'مراجعة أداء الميزانية المغلقة مقابل المصروف الفعلي: {_display}']]],

        // ── المخزون والأصول ──
        ['📦 حركة مخزون رُحّلت', 'stockmv', 'status', 'مؤكدة', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '📦 رُحّلت حركة مخزون وتحرّك الرصيد: {_display}']]],
        ['🔧 أصل دخل الصيانة', 'assets', 'status', 'صيانة', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🔧 أصل دخل الصيانة: {_display}']]],
        ['💥 أصل تالف', 'assets', 'status', 'تالف', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '💥 أصل تالف — قرار إصلاح أو استبعاد: {_display}'],
             ['type' => 'task', 'text' => 'تقييم الأصل التالف وقرار الاستبدال: {_display}']]],
        ['🗓️ صيانة مجدولة — تجهيز', 'assetlog', 'status', 'مجدولة', null,
            [['type' => 'task', 'text' => 'تجهيز الصيانة المجدولة وحجز موعدها: {_display}']]],
        ['🛠️ صيانة اكتملت', 'assetlog', 'status', 'مكتملة', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🛠️ اكتملت صيانة: {_display}']]],

        // ── البنية التحتية والتقنية ──
        ['🖥️ سيرفر متوقف — طوارئ', 'servers', 'status', 'متوقف', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🖥️ سيرفر متوقف: {_display}'],
             ['type' => 'task', 'text' => 'استعادة خدمة السيرفر المتوقف فوراً: {_display}']]],
        ['🌐 موقع متوقف — طوارئ', 'websites', 'status', 'متوقف', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🌐 موقع متوقف عن العمل: {_display}'],
             ['type' => 'task', 'text' => 'إعادة الموقع للعمل: {_display}']]],
        ['🗄️ قاعدة بيانات متوقفة', 'dbs', 'status', 'متوقفة', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🗄️ قاعدة بيانات متوقفة: {_display}']]],
        ['🔑 مفتاح تكامل انتهى', 'apis', 'status', 'منتهي', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🔑 انتهى مفتاح التكامل: {_display}'],
             ['type' => 'task', 'text' => 'تدوير مفتاح التكامل المنتهي: {_display}']]],
        ['💾 فشلت استعادة نسخة', 'restores', 'backup.restore_failed', null, null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '💾 فشل اختبار الاستعادة — نسختك غير موثوقة: {_display}'],
             ['type' => 'task', 'text' => 'تحقيق فشل الاستعادة وإصلاح خطة النسخ: {_display}']]],
        ['📱 تطبيق يحتاج تحديثاً', 'apps', 'status', 'بحاجة لتحديث', null,
            [['type' => 'task', 'text' => 'تخطيط تحديث التطبيق: {_display}']]],
        ['🏷️ إصدار كود نُشر', 'code', 'status', 'منشورة', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🏷️ نُشر إصدار: {_display}']]],
        ['🔗 اعتمادية استُبدلت', 'deps', 'status', 'مُستبدَلة', null,
            [['type' => 'task', 'text' => 'تحديث التوثيق بعد استبدال الاعتمادية: {_display}']]],
        ['⚙️ تغيير تقني نُفّذ', 'changes', 'change.executed', null, null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '⚙️ نُفّذ التغيير التقني: {_display}']]],
        ['↩️ تراجعٌ عن تغيير تقني', 'changes', 'change.rolled_back', null, null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '↩️ تُرُوجع عن التغيير: {_display}'],
             ['type' => 'task', 'text' => 'تحليل سبب التراجع عن التغيير: {_display}']]],
        ['🩹 حادثة احتُويت', 'incidents', 'incident.contained', null, null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🩹 احتُويت الحادثة — الخدمة تتعافى: {_display}']]],
        ['📘 حادثة أُغلقت بتقرير', 'incidents', 'incident.closed', null, null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '📘 أُغلقت الحادثة بتقريرها: {_display}']]],

        // ── الحسابات والاعتمادات ──
        ['🔒 حساب منصة أُوقف', 'accounts', 'status', 'موقوف', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🔒 أُوقف حساب منصة: {_display}']]],
        ['📧 بريد أُوقف', 'emails', 'status', 'موقوف', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '📧 أُوقف بريد — تحقق مما يعتمد عليه: {_display}']]],
        ['📵 خط هاتف انتهى', 'phones', 'status', 'منتهي', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '📵 انتهى خط هاتف — قد يكون رقم استرداد: {_display}']]],
        ['🗝️ سرٌّ جديد في الخزنة', 'vault', 'created', null, null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🗝️ أُضيف سرٌّ جديد للخزنة: {_display}']]],

        // ── الموارد البشرية ──
        ['🚪 موظف انتهت خدمته', 'hr', 'status', 'منتهية خدمته', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🚪 انتهت خدمة موظف — أخلِ عهدته وأوقف صلاحياته: {_display}'],
             ['type' => 'task', 'text' => 'إنهاء إجراءات المغادرة: العهدة والصلاحيات والمخالصة — {_display}']]],
        ['🏝️ غياب مسجَّل', 'attend', 'status', 'غائب', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🏝️ غياب مسجَّل: {_display}']]],
        ['🎓 مهارة مطلوبة — خطة تدريب', 'skills', 'status', 'مطلوبة', null,
            [['type' => 'task', 'text' => 'إعداد خطة تدريب لسدّ فجوة المهارة: {_display}']]],
        ['📄 سجل موظف انتهى', 'hrlog', 'status', 'منتهي', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '📄 انتهى سجل/شهادة موظف: {_display}']]],
        ['📜 سياسة صارت سارية', 'policies', 'status', 'سارية', null,
            [['type' => 'task', 'text' => 'تعميم السياسة السارية وجمع إقراراتها: {_display}']]],
        ['🖊️ إقرار سقط بتحديث النسخة', 'policyacks', 'status', 'منتهية بتحديث النسخة', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🖊️ إقرارٌ سقط بتحديث نسخة السياسة — يلزم إقرارٌ جديد: {_display}']]],

        // ── التسويق والعلامة والسوق ──
        ['📣 منشور نُشر', 'posts', 'status', 'منشور', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '📣 نُشر منشور: {_display}']]],
        ['📰 ظهور إعلامي نُشر', 'media', 'status', 'منشور', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '📰 ظهور إعلامي جديد: {_display}']]],
        ['🎪 حدث انتهى — تقرير', 'events', 'status', 'منتهٍ', null,
            [['type' => 'task', 'text' => 'إعداد تقرير الحدث وحصر عملائه المحتملين: {_display}']]],
        ['⚖️ أصل فكري انتهت حمايته', 'ip', 'status', 'منتهية', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '⚖️ انتهت حماية أصل فكري: {_display}'],
             ['type' => 'task', 'text' => 'تجديد تسجيل الأصل الفكري: {_display}']]],
        ['🥊 منافس جديد رُصد', 'competitors', 'created', null, null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🥊 رُصد منافس جديد: {_display}']]],
        ['🏷️ باقة أُوقفت عن البيع', 'plans', 'status', 'متوقفة عن البيع', null,
            [['type' => 'task', 'text' => 'إبلاغ عملاء الباقة الموقوفة وخطة الترحيل: {_display}']]],
        ['🛠️ خدمة توقفت', 'services', 'status', 'متوقفة', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '🛠️ توقفت خدمة — راجع عقودها القائمة: {_display}']]],
        ['📚 مقال معرفة نُشر', 'kb', 'status', 'منشور', null,
            [['type' => 'notify', 'to' => 'owners', 'text' => '📚 نُشر مقال في قاعدة المعرفة: {_display}']]],

        // ── الشركات ──
        ['🏢 شركة قيد التأسيس', 'companies', 'status', 'قيد التأسيس', null,
            [['type' => 'task', 'text' => 'متابعة إجراءات تأسيس الشركة وتراخيصها: {_display}']]],
    ];

    public function handle(): int
    {
        $core = $this->seed(self::FLOWS, true);
        $more = $this->seed(self::MORE, false);

        $msg = [];
        if ($core) $msg[] = "{$core} مسار أساسي مفعّل";
        if ($more) $msg[] = "{$more} مسار موسّع **معطّل** بانتظار مراجعتك";
        $this->info($msg
            ? 'أُنشئ: ' . implode(' · ', $msg) . ' — راجعها وفعّل ما يناسبك من شاشة مسارات العمل'
            : 'كل مسارات الانطلاق موجودة أصلاً — لم يُكرَّر شيء');

        return self::SUCCESS;
    }

    /** إنشاء مجموعة مسارات — المطابقة بالاسم فلا تكرار، والوحدة المعدومة تُتخطى بأمان */
    protected function seed(array $flows, bool $enabled): int
    {
        $made = 0;
        foreach ($flows as [$name, $module, $event, $statusTo, $cond, $actions]) {
            if (! config("hub.modules.{$module}")) continue;   // وحدة أُزيلت مستقبلاً؟ نتخطى بأمان
            if (Flow::where('name', $name)->exists()) continue;

            Flow::create([
                'name' => $name, 'module' => $module, 'event' => $event,
                'status_to' => $statusTo,
                'cond_field' => $cond[0] ?? null, 'cond_op' => $cond[1] ?? null, 'cond_value' => $cond[2] ?? null,
                'actions' => $actions, 'enabled' => $enabled,
            ]);
            $made++;
        }

        return $made;
    }
}
