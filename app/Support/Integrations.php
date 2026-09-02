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

    /**
     * صحّةُ التكامل — خمسُ حالاتٍ لها معنى (لا «جاهز/غير جاهز»):
     * CONNECTED يعمل وآخرُ نداءٍ نجح · DEGRADED يعمل بإخفاقاتٍ حديثة · FAILED آخرُ نداءٍ فشل
     * · DISABLED مُطفأٌ عمداً · CONFIGURATION_REQUIRED لم يُضبط بعد · UNKNOWN لا قياس بعد
     */
    public const CONNECTED = 'CONNECTED';
    public const DEGRADED = 'DEGRADED';
    public const FAILED = 'FAILED';
    public const DISABLED = 'DISABLED';
    public const CONFIGURATION_REQUIRED = 'CONFIGURATION_REQUIRED';
    public const UNKNOWN = 'UNKNOWN';

    public const HEALTH_LABELS = [
        self::CONNECTED => 'متصل', self::DEGRADED => 'متدهور', self::FAILED => 'فاشل',
        self::DISABLED => 'معطّل', self::CONFIGURATION_REQUIRED => 'يحتاج إعداداً', self::UNKNOWN => 'لم يُقَس بعد',
    ];

    public const HEALTH_TONE = [
        self::CONNECTED => 'ok', self::DEGRADED => 'wn', self::FAILED => 'bad',
        self::DISABLED => 'g', self::CONFIGURATION_REQUIRED => 'wn', self::UNKNOWN => 'g',
    ];

    /** حالة التكاملات المثبّتة في النظام — بأرقامها الحية */
    public static function installed(): array
    {
        return [
            'webhooks' => self::webhooks(),
            'hooks'    => self::hooks(),
            'odoo'     => self::odoo(),
            'api'      => self::api(),
            'outbox'   => self::outbox(),
        ];
    }

    /**
     * أثرُ آخر نداءٍ لتكاملٍ لا يترك صفوفاً (أودو): يُكتب في الإعدادات مرّةً كلَّ
     * خمس دقائق للنجاح، وفوراً للفشل — فيصير للتكامل «آخرُ نجاح/آخرُ فشل/السبب».
     */
    public static function pulse(string $key, bool $ok, ?string $error = null): void
    {
        try {
            $now = now()->toIso8601String();
            if ($ok) {
                $last = (string) setting('integration.' . $key . '.last_ok', '');
                if ($last !== '' && \Illuminate\Support\Carbon::parse($last)->gt(now()->subMinutes(5))) return;
                \App\Models\Setting::updateOrCreate(['key' => 'integration.' . $key . '.last_ok'], ['value' => $now]);
            } else {
                \App\Models\Setting::updateOrCreate(['key' => 'integration.' . $key . '.last_fail'], ['value' => $now]);
                \App\Models\Setting::updateOrCreate(['key' => 'integration.' . $key . '.last_error'],
                    ['value' => mb_substr((string) preg_replace('/(password|pwd|key)=\S+/i', '$1=***', (string) $error), 0, 180)]);
            }
            \Illuminate\Support\Facades\Cache::forget('settings:all');
        } catch (\Throwable $e) {
            // الأثرُ إثراءٌ لا شرط
        }
    }

    /** آخرُ نجاحٍ/فشلٍ مسجَّلين لتكامل */
    protected static function pulseOf(string $key): array
    {
        try {
            return [
                'last_ok_at' => setting('integration.' . $key . '.last_ok') ?: null,
                'last_fail_at' => setting('integration.' . $key . '.last_fail') ?: null,
                'last_error' => setting('integration.' . $key . '.last_error') ?: null,
            ];
        } catch (\Throwable $e) {
            return ['last_ok_at' => null, 'last_fail_at' => null, 'last_error' => null];
        }
    }

    /** حكمُ الصحّة من آخر نجاحٍ وآخر فشل: الفشلُ الأحدث = FAILED، فشلٌ خلال يومٍ بعده نجاح = DEGRADED */
    protected static function judge(?string $ok, ?string $fail): string
    {
        if (! $ok && ! $fail) return self::UNKNOWN;
        if ($fail && (! $ok || \Illuminate\Support\Carbon::parse($fail)->gte(\Illuminate\Support\Carbon::parse($ok)))) return self::FAILED;
        if ($fail && \Illuminate\Support\Carbon::parse($fail)->gt(now()->subDay())) return self::DEGRADED;

        return self::CONNECTED;
    }

    /** الويبهوك الوارد — نقاطُ الاستقبال */
    protected static function hooks(): array
    {
        $n = 0; $on = 0; $hits7 = 0; $last = null;
        try {
            if (Schema::hasTable('inbound_hooks')) {
                $n = DB::table('inbound_hooks')->count();
                $on = DB::table('inbound_hooks')->where('enabled', true)->count();
                $last = DB::table('inbound_hooks')->max('last_hit_at');
                if (Schema::hasTable('inbound_hook_events')) {
                    $hits7 = DB::table('inbound_hook_events')->where('created_at', '>=', now()->subDays(7))->count();
                }
            }
        } catch (\Throwable $e) {}
        $health = $n === 0 ? self::CONFIGURATION_REQUIRED : ($on === 0 ? self::DISABLED : self::CONNECTED);

        return [
            'key' => 'hooks', 'icon' => '📥', 'name' => 'الويبهوك الوارد — نقاط الاستقبال',
            'dir' => self::IN,
            'desc' => 'نظامٌ خارجيّ (n8n، نموذج، خدمة) يستدعي رابطاً موقّعاً (HMAC + منع تكرار) فيُخزَّن ما أرسله حدثاً.',
            'ready' => $on > 0,
            'health' => $health, 'last_ok_at' => $last, 'last_fail_at' => null, 'last_error' => null,
            'state' => $n === 0 ? 'لا نقاط بعد' : "{$on} مفعّلة من {$n}",
            'stats' => ['نقاط' => $n, 'مفعّلة' => $on, 'أحداث ٧ أيام' => $hits7],
            'route' => 'hooks.index',
        ];
    }

    protected static function webhooks(): array
    {
        $n = 0; $on = 0; $fail = 0; $sent = 0; $paused = 0; $lastOk = null; $lastFail = null; $lastErr = null;
        try {
            $n = DB::table('webhooks')->count();
            $on = DB::table('webhooks')->where('active', true)->count();
            $paused = DB::table('webhooks')->where('active', true)->where('paused_until', '>', now())->count();
            $lastOk = DB::table('webhooks')->where('last_ok', true)->max('last_at');
            $lastFail = DB::table('webhooks')->where('last_ok', false)->max('last_at');
            if (Schema::hasTable('webhook_deliveries')) {
                $recent = DB::table('webhook_deliveries')->where('created_at', '>=', now()->subDays(7));
                $sent = (clone $recent)->where('state', 'sent')->count();
                $fail = (clone $recent)->where('state', 'failed')->count();
                $lastErr = DB::table('webhook_deliveries')->whereNotNull('error')->orderByDesc('created_at')->value('error');
            }
        } catch (\Throwable $e) {}
        if ($n === 0) $health = self::CONFIGURATION_REQUIRED;
        elseif ($on === 0) $health = self::DISABLED;
        elseif ($paused === $on) $health = self::FAILED;
        elseif ($paused || $fail) $health = self::DEGRADED;
        else { $j = self::judge($lastOk, $lastFail); $health = $j === self::UNKNOWN ? self::CONNECTED : $j; }

        return [
            'key' => 'webhooks', 'icon' => '🪝', 'name' => 'Webhooks — بثّ الأحداث',
            'dir' => self::OUT,
            'desc' => 'يرسل أحداث النظام لأي منصة خارجية (n8n، Zapier، Make، خادمك) بطلب POST موقّع HMAC.',
            'ready' => $on > 0,
            'health' => $health, 'last_ok_at' => $lastOk, 'last_fail_at' => $lastFail, 'last_error' => $lastErr ? mb_substr((string) $lastErr, 0, 180) : null,
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
        $p = self::pulseOf('odoo');
        $health = ! $ok ? self::CONFIGURATION_REQUIRED : self::judge($p['last_ok_at'], $p['last_fail_at']);

        return [
            'key' => 'odoo', 'icon' => '🧩', 'name' => 'أودو (Odoo) — قراءة محاسبية',
            'dir' => self::IN,
            'desc' => 'يقرأ مبيعات العميل وفواتيره وغير المحصّل من أودو ويعرضها داخل سجله — قراءةٌ فقط، لا كتابة محاسبية إطلاقاً. ويدعم خوادمَ متعددة: بعض المشاريع لها أودو خاص.',
            'ready' => $ok,
            'health' => $health, 'last_ok_at' => $p['last_ok_at'], 'last_fail_at' => $p['last_fail_at'], 'last_error' => $p['last_error'],
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

        $lastUse = null; $lock = false; $frozen = false;
        try {
            $lastUse = DB::table('api_tokens')->max('last_used_at');
            $lock = (bool) setting('security.lockdown', false);
            $frozen = (string) setting('security.freeze_tokens', '0') === '1';
        } catch (\Throwable $e) {}
        $health = $lock ? self::DISABLED : ($live === 0 ? self::CONFIGURATION_REQUIRED : self::CONNECTED);

        return [
            'key' => 'api', 'icon' => '🔑', 'name' => 'REST API — الاستقبال والقراءة',
            'dir' => self::BOTH,
            'desc' => 'كل وحدات النظام عبر /api/v1 بنفس الصلاحيات والنطاق — هنا **تنزل** بيانات n8n إلى القسم الذي تريده.',
            'ready' => $live > 0,
            'health' => $health, 'last_ok_at' => $lastUse, 'last_fail_at' => null,
            'last_error' => $lock ? 'قفل طوارئ — سطح API معلَّق' : ($frozen ? 'سكّ الرموز مجمَّد' : null),
            'state' => $n === 0 ? 'لا مفاتيح بعد' : "{$live} مفتاح سارٍ من {$n}",
            'stats' => ['مفاتيح' => $n, 'سارية' => $live],
            'route' => 'settings.edit',
        ];
    }

    protected static function outbox(): array
    {
        $q = 0; $f = 0; $lastOk = null; $lastFail = null; $lastErr = null; $tg = false; $mail = false;
        try {
            $q = DB::table('outbox')->where('state', 'queued')->count();
            $f = DB::table('outbox')->where('state', 'failed')->count();
            $lastOk = DB::table('outbox')->where('state', 'sent')->max('delivered_at');
            $lastFail = DB::table('outbox')->where('state', 'failed')->max('created_at');
            $lastErr = DB::table('outbox')->where('state', 'failed')->orderByDesc('created_at')->value('error');
            $tg = (string) setting('notify.tg_token', '') !== '';
            $mail = ! in_array((string) config('mail.default'), ['log', 'array'], true);
        } catch (\Throwable $e) {}
        if (! $tg && ! $mail) $health = self::CONFIGURATION_REQUIRED;
        elseif ($f > 0) $health = ($lastOk && $lastFail && \Illuminate\Support\Carbon::parse($lastOk)->gt(\Illuminate\Support\Carbon::parse($lastFail))) ? self::DEGRADED : self::FAILED;
        else $health = $lastOk ? self::CONNECTED : self::UNKNOWN;

        return [
            'key' => 'outbox', 'icon' => '📨', 'name' => 'المراسلة — تلجرام وبريد وداخل التطبيق',
            'dir' => self::OUT,
            'desc' => 'كل طرق التواصل الخارجة بحالتها الحية واختبارها وإعادة فاشلها ودليل إعدادها — في مركز المراسلة.',
            'ready' => true,
            'health' => $health, 'last_ok_at' => $lastOk, 'last_fail_at' => $lastFail, 'last_error' => $lastErr ? mb_substr((string) $lastErr, 0, 180) : null,
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
                ['ترنديول (Trendyol)', 'أصيل عبر أودو', 'مبيعاتها تُسجَّل وتتجمع في أودو؛ الهَب يعرضها لكل مشروع قناةً قناة — لا حاجة لمفاتيح API من المنصة'],
                ['أمازون (Amazon)', 'أصيل عبر أودو', 'نفس الطريق: موصل المنصة في أودو يجمع، والهَب يعرض من أودو'],
                ['نون (Noon)', 'أصيل عبر أودو', 'نفس الطريق — عرّفها قناةً في «تخصيص أودو» لمشروعها'],
                ['المتجر الإلكتروني', 'أصيل عبر أودو', 'متجرك (سلة/زد/Shopify/موقعك) يصبّ في أودو، والهَب يعرض قناتَه'],
            ]],
            ['🔗 منصات الأتمتة', [
                ['n8n', 'ويبهوك + API', 'الأشهر عندك: يستقبل أحداث الهَب ويعيد إليه البيانات عبر /api/v1'],
                ['Zapier', 'ويبهوك + API', 'يستقبل الأحداث الموقّعة ويكتب عبر مفتاح API'],
                ['Make (Integromat)', 'ويبهوك + API', 'مثل n8n — سيناريوهات مرئية'],
                ['خادمك الخاص', 'ويبهوك + API', 'أي نقطة استقبال HTTPS تتحقق من توقيع HMAC'],
            ]],
            ['💬 التنبيه والمراسلة', [
                ['تلجرام', 'الصندوق الصادر', 'مبنيٌّ أصلاً — يسلّمه hub:outbox أو n8n'],
                ['البريد الإلكتروني', 'الصندوق الصادر', 'مبنيٌّ أصلاً'],
                ['Slack / Discord', 'ويبهوك', 'وجّه اشتراك ويبهوك إلى Incoming Webhook عندهم'],
                ['WhatsApp Business', 'ويبهوك + API', 'عبر n8n أو مزوّد رسائل — الهَب يبثّ الحدث'],
            ]],
            ['📊 السوشال والتحليلات', [
                ['Meta (فيسبوك/إنستغرام)', 'API داخل', 'n8n يجلب المتابعين والتفاعل ويدفعها لمقاييس الهَب'],
                ['X (تويتر)', 'API داخل', 'نفس الطريق: جلبٌ خارجي ودفعٌ إلى الهَب'],
                ['LinkedIn', 'API داخل', 'نفس الطريق'],
                ['TikTok / YouTube', 'API داخل', 'نفس الطريق'],
                ['Google Analytics / Search Console', 'API داخل', 'مؤشرات المواقع تُدفع كمقاييس'],
            ]],
            ['📱 متاجر التطبيقات', [
                ['App Store Connect', 'API داخل', 'التحميلات والتقييمات تُجلب وتُدفع لمقاييس التطبيق'],
                ['Google Play Console', 'API داخل', 'نفس الطريق'],
                ['Firebase', 'API داخل', 'الأعطال والنشاط'],
            ]],
            ['💳 المالية والدفع', [
                ['أودو (Odoo)', 'أصيل', 'مبنيٌّ في الهَب — قراءة مبيعات وفواتير العميل'],
                ['بوابات الدفع (MyFatoorah/Stripe/تاب)', 'ويبهوك + API', 'حدث السداد يدخل كدفعة على المستند المالي'],
                ['البنوك (كشوفات)', 'API داخل', 'استيراد الحركات كمقاييس أو مستندات'],
            ]],
            ['🛠️ التقنية', [
                ['GitHub / GitLab', 'ويبهوك داخل', 'الدفعات والإصدارات تُنشئ سجلات نشر عبر API'],
                ['مراقبة الجاهزية (Uptime)', 'ويبهوك داخل', 'العطل يفتح حادثة عبر /api/v1/incidents'],
                ['Google Sheets', 'API', 'تصدير/استيراد عبر n8n'],
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
