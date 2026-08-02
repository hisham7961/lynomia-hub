<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * سجل التكاملات: حالة كل قناةٍ رابطة بين الهَب والعالم الخارجي، وكتالوج ما
 * يمكن ربطه. مصدر واحد للحقيقة يخدم مركز التكاملات ودليل الربط — فلا تتناثر
 * المعرفة بين شاشة الويبهوك وإعدادات أودو وصفحة المفاتيح كما كانت.
 */
class Integrations
{
    /** الاتجاه: out = الهَب يرسل · in = الهَب يستقبل · both = الاتجاهان */
    public const OUT = 'out';
    public const IN = 'in';
    public const BOTH = 'both';

    /** حالة التكاملات المثبّتة في النظام — بأرقامها الحية */
    public static function installed(): array
    {
        return [
            'webhooks' => self::webhooks(),
            'odoo'     => self::odoo(),
            'api'      => self::api(),
            'outbox'   => self::outbox(),
        ];
    }

    protected static function webhooks(): array
    {
        $n = 0; $on = 0; $fail = 0; $sent = 0;
        try {
            $n = DB::table('webhooks')->count();
            $on = DB::table('webhooks')->where('active', true)->count();
            if (Schema::hasTable('webhook_deliveries')) {
                $recent = DB::table('webhook_deliveries')->where('created_at', '>=', now()->subDays(7));
                $sent = (clone $recent)->where('state', 'sent')->count();
                $fail = (clone $recent)->where('state', 'failed')->count();
            }
        } catch (\Throwable $e) {}

        return [
            'key' => 'webhooks', 'icon' => '🪝', 'name' => 'Webhooks — بثّ الأحداث',
            'dir' => self::OUT,
            'desc' => 'يرسل أحداث النظام لأي منصة خارجية (n8n، Zapier، Make، خادمك) بطلب POST موقّع HMAC.',
            'ready' => $on > 0,
            'state' => $n === 0 ? 'لا اشتراكات بعد' : "{$on} مفعّل من {$n}",
            'stats' => ['اشتراكات' => $n, 'مفعّلة' => $on, 'إرسال ٧ أيام' => $sent, 'إخفاقات ٧ أيام' => $fail],
            'route' => 'webhooks.index',
        ];
    }

    protected static function odoo(): array
    {
        $linked = 0;
        try {
            foreach (self::odooModules() as $mk => $_) {
                $md = hub_mod($mk);
                if (! $md || ! Schema::hasTable($md['table'])) continue;
                $linked += DB::table($md['table'])->whereNull('deleted_at')
                    ->where('meta', 'LIKE', '%odoo_partner_id%')->count();
            }
        } catch (\Throwable $e) {}

        $extra = 0;
        try {
            $extra = \App\Models\OdooConnection::where('active', true)->count();
        } catch (\Throwable $e) {}
        $ok = Odoo::configured() || $extra > 0;

        return [
            'key' => 'odoo', 'icon' => '🧩', 'name' => 'أودو (Odoo) — قراءة محاسبية',
            'dir' => self::IN,
            'desc' => 'يقرأ مبيعات العميل وفواتيره وغير المحصّل من أودو ويعرضها داخل سجله — قراءةٌ فقط، لا كتابة محاسبية إطلاقاً. ويدعم خوادمَ متعددة: بعض المشاريع لها أودو خاص.',
            'ready' => $ok,
            'state' => $ok
                ? 'مربوط · ' . ($extra ? "{$extra} خادم إضافي · " : '') . "{$linked} سجل مقرون"
                : 'غير مربوط — أضف خادماً أو أكمل الافتراضي',
            'stats' => ['سجلات مقرونة' => $linked, 'خوادم إضافية' => $extra],
            'route' => 'integrations.odoo',
        ];
    }

    protected static function api(): array
    {
        $n = 0; $live = 0;
        try {
            $n = DB::table('api_tokens')->count();
            $live = DB::table('api_tokens')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count();
        } catch (\Throwable $e) {}

        return [
            'key' => 'api', 'icon' => '🔑', 'name' => 'REST API — الاستقبال والقراءة',
            'dir' => self::BOTH,
            'desc' => 'كل وحدات النظام عبر /api/v1 بنفس الصلاحيات والنطاق — هنا **تنزل** بيانات n8n إلى القسم الذي تريده.',
            'ready' => $live > 0,
            'state' => $n === 0 ? 'لا مفاتيح بعد' : "{$live} مفتاح سارٍ من {$n}",
            'stats' => ['مفاتيح' => $n, 'سارية' => $live],
            'route' => 'settings.edit',
        ];
    }

    protected static function outbox(): array
    {
        $q = 0; $f = 0;
        try {
            $q = DB::table('outbox')->where('state', 'queued')->count();
            $f = DB::table('outbox')->where('state', 'failed')->count();
        } catch (\Throwable $e) {}

        return [
            'key' => 'outbox', 'icon' => '📨', 'name' => 'المراسلة — تلجرام وبريد وداخل التطبيق',
            'dir' => self::OUT,
            'desc' => 'كل طرق التواصل الخارجة بحالتها الحية واختبارها وإعادة فاشلها ودليل إعدادها — في مركز المراسلة.',
            'ready' => true,
            'state' => $f ? "{$q} في الطابور · {$f} فاشلة" : "{$q} في الطابور",
            'stats' => ['في الطابور' => $q, 'فاشلة' => $f],
            'route' => 'integrations.messaging',
        ];
    }

    /** الوحدات التي تقبل الاقتران بأودو — بطاقة أودو تظهر عليها */
    public static function odooModules(): array
    {
        return [
            'clients'   => 'العميل يُقرن بشريكٍ في أودو، فتظهر مبيعاته وفواتيره وغير المحصّل',
            'companies' => 'الشركة تُقرن بشريك، فتظهر أرقامها المجمّعة',
            'projects'  => 'المشروع يُقرن بشريك العميل، فتظهر فواتيره',
        ];
    }

    /**
     * كتالوج ما يمكن ربطه: كل تكاملٍ وطريقه إلى النظام. الطريق ثلاثة لا رابع:
     * ويبهوك خارج، REST API داخل، أو تكاملٌ أصيل مبنيٌّ في الهَب.
     */
    public static function catalog(): array
    {
        return [
            ['🛍 قنوات البيع (عبر أودو)', [
                ['ترنديول (Trendyol)', 'أصيل عبر أودو', 'مبيعاتها تُسجَّل وتتجمع في أودو؛ الهَب يعرضها لكل مشروع قناةً قناة — لا حاجة لمفاتيح API من المنصة', true],
                ['أمازون (Amazon)', 'أصيل عبر أودو', 'نفس الطريق: موصل المنصة في أودو يجمع، والهَب يعرض من أودو', true],
                ['نون (Noon)', 'أصيل عبر أودو', 'نفس الطريق — عرّفها قناةً في «تخصيص أودو» لمشروعها', true],
                ['المتجر الإلكتروني', 'أصيل عبر أودو', 'متجرك (سلة/زد/Shopify/موقعك) يصبّ في أودو، والهَب يعرض قناتَه', true],
            ]],
            ['🔗 منصات الأتمتة', [
                ['n8n', 'ويبهوك + API', 'الأشهر عندك: يستقبل أحداث الهَب ويعيد إليه البيانات عبر /api/v1', true],
                ['Zapier', 'ويبهوك + API', 'يستقبل الأحداث الموقّعة ويكتب عبر مفتاح API', true],
                ['Make (Integromat)', 'ويبهوك + API', 'مثل n8n — سيناريوهات مرئية', true],
                ['خادمك الخاص', 'ويبهوك + API', 'أي نقطة استقبال HTTPS تتحقق من توقيع HMAC', true],
            ]],
            ['💬 التنبيه والمراسلة', [
                ['تلجرام', 'الصندوق الصادر', 'مبنيٌّ أصلاً — يسلّمه hub:outbox أو n8n', true],
                ['البريد الإلكتروني', 'الصندوق الصادر', 'مبنيٌّ أصلاً', true],
                ['Slack / Discord', 'ويبهوك', 'وجّه اشتراك ويبهوك إلى Incoming Webhook عندهم', true],
                ['WhatsApp Business', 'ويبهوك + API', 'عبر n8n أو مزوّد رسائل — الهَب يبثّ الحدث', true],
            ]],
            ['📊 السوشال والتحليلات', [
                ['Meta (فيسبوك/إنستغرام)', 'API داخل', 'n8n يجلب المتابعين والتفاعل ويدفعها لمقاييس الهَب', true],
                ['X (تويتر)', 'API داخل', 'نفس الطريق: جلبٌ خارجي ودفعٌ إلى الهَب', true],
                ['LinkedIn', 'API داخل', 'نفس الطريق', true],
                ['TikTok / YouTube', 'API داخل', 'نفس الطريق', true],
                ['Google Analytics / Search Console', 'API داخل', 'مؤشرات المواقع تُدفع كمقاييس', true],
            ]],
            ['📱 متاجر التطبيقات', [
                ['App Store Connect', 'API داخل', 'التحميلات والتقييمات تُجلب وتُدفع لمقاييس التطبيق', true],
                ['Google Play Console', 'API داخل', 'نفس الطريق', true],
                ['Firebase', 'API داخل', 'الأعطال والنشاط', true],
            ]],
            ['💳 المالية والدفع', [
                ['أودو (Odoo)', 'أصيل', 'مبنيٌّ في الهَب — قراءة مبيعات وفواتير العميل', true],
                ['بوابات الدفع (MyFatoorah/Stripe/تاب)', 'ويبهوك + API', 'حدث السداد يدخل كدفعة على المستند المالي', true],
                ['البنوك (كشوفات)', 'API داخل', 'استيراد الحركات كمقاييس أو مستندات', true],
            ]],
            ['🛠️ التقنية', [
                ['GitHub / GitLab', 'ويبهوك داخل', 'الدفعات والإصدارات تُنشئ سجلات نشر عبر API', true],
                ['مراقبة الجاهزية (Uptime)', 'ويبهوك داخل', 'العطل يفتح حادثة عبر /api/v1/incidents', true],
                ['Google Sheets', 'API', 'تصدير/استيراد عبر n8n', true],
            ]],
        ];
    }

    /**
     * كتالوج الأحداث الصادرة — مولَّدٌ من السجل لا مكتوبٌ باليد، فلا يتقادم:
     * لكل وحدة أحداثها البدائية، ومعها الأحداث الدلالية التي تبثّها.
     */
    public static function eventCatalog(): array
    {
        $out = [];
        foreach (hub_modules() as $mk => $md) {
            $sem = array_column((array) config("hub.events.{$mk}", []), 'emit');
            $out[$mk] = [
                'label' => $md['label'],
                'basic' => ["{$mk}.created", "{$mk}.updated", "{$mk}.status"],
                'semantic' => $sem,
            ];
        }

        return $out;
    }
}
