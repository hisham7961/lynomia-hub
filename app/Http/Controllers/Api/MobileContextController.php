<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileContext;
use App\Models\HubNotification;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * **سياقُ الجوال + الإقلاعُ + المخطّط** — Mobile Readiness · الطور C · §109
 * (C.1 السياق · C.2 الإقلاع · C.4 المخطّط).
 *
 * سطحٌ **مقروءٌ فقط** خلف `mobile.session` (+`mobile.context`): يجيب على «مَن
 * أنا، وما نطاقي، وما شكلُ البيانات؟» عند إقلاع التطبيق البارد. يُعيد استعمالَ
 * سكك المنصّة لا يفرّعها — `hub_scope` (محرّكُ العزل)، `hub_company_ids`/
 * `hub_client_ids` (مجموعتا التنطيق)، `hub_nav` (التنقّلُ المُنطَّق)، `hub_can`/
 * `hub_visible_fields`/`hub_field_mode` (قناعُ الحقول)، `hub_sync_class` (تصنيفُ
 * المزامنة · SF-5)، و`HubNotification` لعدّ غير المقروء.
 *
 * **لا IDOR ولا تسريبُ تنطيق:** كلُّ قائمةٍ تمرّ بـ`hub_scope` — للمقيَّد تُقصّ على
 * مجموعته المسموحة بالضبط (`whereIn`)، ولغير المقيَّد تُعيد ما في نطاقه فقط — فبرهانُ
 * «لا يُرى ما لا يُسمح» بنيويّ لا ادّعائيّ. الهويّةُ من الجلسة وحدَها (`auth()->user()`
 * الذي أرسته `MobileSessionAuth`) — **لا يُوثَق بأيّ هويّةٍ/دورٍ/شركةٍ يرسلها العميل**.
 *
 * **لا تسريبَ بنيةٍ فيزيائية (INVENTORY §7c):** مخطّطُ الجوال — بخلاف
 * `/api/v1/modules` (الذي يبقى كما هو للتوافق) — **يُسقط اسمَ الجدول الفيزيائيّ**
 * (`table`) وعمودَ الحقل (`col`)، ويكتفي بالمفاتيح المنطقيّة، ويضيف تحقّقاً مشتقّاً
 * (`required`/`options`/`readonly`) وتصنيفَ المزامنة وبصمةَ نسخةٍ (ETag/304).
 *
 * كلُّ ردٍّ بغلاف `Api::*` (`data` + `request_id`)، وإصدارُ العقد (`X-API-Version`)
 * تضعه `MobileSessionAuth`. لا أسرارَ ولا مجموعاتٍ ضخمة (سقفٌ + `has_more`).
 */
class MobileContextController extends Controller
{
    /** سقفُ عناصرِ قائمة السياق في C.1 — بوّابةٌ ضدّ المجموعات الضخمة (مالكٌ برؤيةٍ شاملة) */
    private const CONTEXT_CAP = 200;

    /** معاينةٌ أصغر داخل الإقلاع (C.2) كي يبقى مضغوطاً — التفصيلُ الكامل في GET context */
    private const BOOTSTRAP_PREVIEW = 25;

    // ══════════════════════════ C.1 · GET context ══════════════════════════

    /**
     * **C.1 · GET context** — شركاتُ المستخدم وعملاؤه المسموحون + السياقُ النشط.
     *
     * المصدرُ `hub_company_ids()`/`hub_client_ids()` (هويّةُ المستخدم وحدَها)
     * محلولاً عبر `hub_scope` إلى `{id,name}` مرتّبةً حتميّاً — فما لا يراه لا
     * يُذكَر (لا IDOR). `active` = التضييقُ النشطُ من ترويسات SF-4 (أو null).
     */
    public function context(Request $r): JsonResponse
    {
        $u = auth()->user();

        return $this->ok([
            'user'      => ['id' => $u->id, 'is_owner' => hub_is_owner($u)],
            'companies' => $this->contextDimension('companies', MobileContext::company($r), self::CONTEXT_CAP),
            'clients'   => $this->contextDimension('clients',   MobileContext::client($r),  self::CONTEXT_CAP),
        ]);
    }

    // ══════════════════════════ C.2 · GET bootstrap ══════════════════════════

    /**
     * **C.2 · GET bootstrap** (ETag/304) — لقطةٌ مضغوطةٌ لإقلاع التطبيق البارد.
     *
     * تجمع: المستخدمَ (id/name/email/role/is_owner)، خياراتِ السياق (معاينةٌ +
     * عدد)، أعلامَ قدرةٍ آمنةً (own-identity)، الوقتَ/المنطقة، الإصدارات (التطبيق/
     * عقد الجوال/المخطّط)، عدَّ غير المقروء، والتنقّلَ المُنطَّق (`hub_nav`). **لا
     * مجموعاتٍ ضخمة ولا أسرار.**
     *
     * البصمةُ تُحوسَب على **الحمولة الثابتة وحدَها** — `server_time` المتقلّب يُحقَن
     * في جسم ٢٠٠ لا في البصمة، وإلّا لَما طابقت `If-None-Match` أبداً فبطل 304.
     * عدُّ غير المقروء والسياقُ والتنقّلُ **داخل** البصمة عمداً: تغيّرُها تغيّرٌ
     * حقيقيٌّ في اللقطة يستحقّ ٢٠٠ جديدة؛ ثباتُها ⇒ 304 فارغة.
     */
    public function bootstrap(Request $r): JsonResponse
    {
        $u  = auth()->user();
        $sv = self::schemaVersion();

        $stable = [
            'user' => [
                'id'       => $u->id,
                'name'     => $u->name,
                'email'    => $u->email,
                'role'     => $u->role?->name,
                'is_owner' => hub_is_owner($u),
            ],
            'context' => [
                'companies' => $this->contextDimension('companies', MobileContext::company($r), self::BOOTSTRAP_PREVIEW),
                'clients'   => $this->contextDimension('clients',   MobileContext::client($r),  self::BOOTSTRAP_PREVIEW),
            ],
            // أعلامُ قدرةٍ آمنة — كلُّها **هويّةُ المُنادي نفسِه** لا أسرار: تقود إظهارَ
            // تبويباتِ التطبيق (اعتماداتٌ/مراقبةٌ/أسرار) دون أن تُخوّل شيئاً (الخادمُ
            // يعيد فحصَ الصلاحية في كل نقطةٍ لاحقة — العميلُ غيرُ موثوق).
            'feature_flags' => [
                'can_approve'        => hub_approver($u),
                'can_monitor'        => hub_monitor($u),
                'can_secrets'        => hub_secrets($u),
                'mfa_enrolled'       => (bool) $u->totp_enabled,
                'restricted_company' => hub_company_ids($u) !== null,
                'restricted_client'  => hub_client_ids($u) !== null,
            ],
            'timezone' => (string) config('app.timezone', 'UTC'),
            'versions' => [
                'app'        => (string) config('hub.version', ''),
                'mobile_api' => (string) config('hub.mobile.api_version', Api::VERSION),
                'schema'     => $sv,
            ],
            'unread_notifications' => (int) HubNotification::where('user_id', $u->id)->where('read', false)->count(),
            'nav'                  => hub_nav($u),   // مجموعاتٌ مُنطَّقةٌ سلفاً — الوحدةُ بلا عرضٍ تسقط
            'schema_version'       => $sv,
        ];

        $etag = Api::etag($stable);
        $rid  = Api::requestId();

        if (Api::etagMatches($r, $etag)) {
            return response()->json(null, 304, [
                'ETag' => $etag, 'X-Request-Id' => (string) $rid, 'X-API-Version' => Api::VERSION,
            ]);
        }

        return response()->json([
            'data'       => $stable + ['server_time' => now()->toIso8601String()],
            'request_id' => $rid,
        ], 200, ['ETag' => $etag, 'X-Request-Id' => (string) $rid, 'X-API-Version' => Api::VERSION]);
    }

    // ══════════════════════════ C.4 · GET schema · schema/modules ══════════════════════════

    /**
     * **C.4 · GET schema** (ETag/304) — الوثيقةُ الكاملة: نسخةُ المخطّط + عقدُ
     * الجوال + الوحداتُ المُنطَّقة بحقولها. بصمةٌ لكلِّ مستخدم (على ما يراه فعلاً).
     */
    public function schema(Request $r): JsonResponse
    {
        return Api::etagJson($r, [
            'schema_version'     => self::schemaVersion(),
            'mobile_api_version' => (string) config('hub.mobile.api_version', Api::VERSION),
            'modules'            => $this->buildSchemaModules(auth()->user()),
        ]);
    }

    /**
     * **C.4 · GET schema/modules** (ETag/304) — نظيرُ `/api/v1/modules` الأسلم:
     * الوحداتُ وحقولُها المرئيّة، **دون** اسمِ الجدول/العمود الفيزيائيّ، مع تحقّقٍ
     * مشتقٍّ وتصنيفِ مزامنة. نسخةُ المخطّط ملاصقةٌ للقائمة.
     */
    public function schemaModules(Request $r): JsonResponse
    {
        return Api::etagJson($r, [
            'schema_version' => self::schemaVersion(),
            'modules'        => $this->buildSchemaModules(auth()->user()),
        ]);
    }

    // ══════════════════════════ مساعِداتٌ داخلية ══════════════════════════

    /** غلافُ نجاحٍ موحَّد: `data` + `request_id` (نمطُ ردود `/api` · X-API-Version من الوسيط) */
    private function ok(array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'request_id' => Api::requestId()], 200);
    }

    /**
     * بُعدٌ من أبعاد السياق (companies|clients): يحلّ المجموعةَ المسموحة إلى
     * `{id,name}` **عبر `hub_scope`** — محرّكُ العزل نفسُه، فالقصُّ على المسموح
     * (للمقيَّد) أو النطاقِ الكامل (لغير المقيَّد) بنيويٌّ لا ادّعائيّ (لا IDOR).
     * مرتَّبٌ حتميّاً (اسمُ العرض ثم id · C13)، ومسقوفٌ بـ`$cap` مع `has_more`.
     */
    private function contextDimension(string $module, ?string $active, int $cap): array
    {
        $def   = hub_mod($module);
        $class = '\\App\\Models\\' . ($def['model'] ?? '');
        $ids   = $module === 'companies' ? hub_company_ids() : hub_client_ids();
        $col   = $this->displayCol($def, $class);

        // hub_scope: للمقيَّد whereIn(المسموح) بالضبط، ولغير المقيَّد كلُّ ما في نطاقه — لا IDOR
        $q     = hub_scope($class::query(), $module);
        $total = (clone $q)->count();
        $rows  = $q->orderBy($col)->orderBy('id')->limit($cap)->get(['id', $col]);

        return [
            'restricted' => $ids !== null,
            'active'     => $active,   // التضييقُ النشطُ من ترويسة SF-4 (محقَّقٌ ⊆ المسموح) أو null
            'count'      => (int) $total,
            'has_more'   => $total > $rows->count(),
            'items'      => $rows->map(fn ($x) => [
                'id'   => (string) $x->id,
                'name' => (string) ($x->{$col} ?? ''),
            ])->values()->all(),
        ];
    }

    /** عمودُ اسمِ العرض للوحدة: من تعريفها (`display`→`col`)، فثابتِ الموديل، فـ`id` */
    private function displayCol(?array $def, string $class): string
    {
        $displayKey = $def['display'] ?? null;
        foreach (($def['fields'] ?? []) as $f) {
            if (($f['key'] ?? null) === $displayKey && ! empty($f['col'])) return (string) $f['col'];
        }
        if (defined("$class::DISPLAY")) return (string) constant("$class::DISPLAY");

        return 'id';
    }

    /**
     * الوحداتُ التي يراها المستخدمُ (‏`hub_can(...,'v')` — نظيرُ `V1Controller::modules`
     * دون فحصِ `tokenAllows`: لا مفتاحَ API في الجوال، فالصلاحيةُ الكاملةُ للمستخدم
     * تحت `hub_can` وحدَه). لكلٍّ: المفتاحُ/التسمية، تصنيفُ المزامنة، مصفوفةُ
     * القدرة (v/a/e/d)، والحقولُ **المرئيّة** (`hub_visible_fields` يُسقط `hide`)
     * مع تحقّقٍ مشتقٍّ — **بلا `table`/`col`** (تسريبُ V1Controller.php:33 لا يُكرَّر).
     */
    private function buildSchemaModules($user): array
    {
        $out = [];
        foreach (hub_modules() as $key => $def) {
            if (! hub_can($user, $key, 'v')) continue;

            $fields = [];
            foreach (hub_visible_fields($user, $key, $def) as $f) {
                $fields[] = [
                    'key'      => $f['key'],
                    'label'    => $f['label'] ?? $f['key'],
                    'type'     => $f['type'] ?? 'text',
                    'required' => (bool) ($f['required'] ?? false),   // تحقّقٌ مشتقّ
                    'ref'      => $f['ref'] ?? null,                  // الوحدةُ المرجعُ (مفتاحٌ منطقيّ لا جدول)
                    'multi'    => (bool) ($f['multi'] ?? false),
                    'options'  => $f['options'] ?? null,             // خياراتُ sel — تحقّقٌ مشتقّ
                    'hint'     => (isset($f['hint']) && $f['hint'] !== '') ? $f['hint'] : null,
                    // locked (كلُّ الأدوار) ∪ field_rules[ro] لدور المستخدم — قراءةٌ فقط
                    'readonly' => hub_field_mode($user, $key, (string) $f['key']) === 'ro',
                ];
            }

            $out[] = [
                'key'        => $key,
                'label'      => $def['label'] ?? $key,
                'sync_class' => hub_sync_class($key),   // SF-5 — يقود سلوكَ المزامنة (الطور G)
                'can'        => [
                    'v' => hub_can($user, $key, 'v'),
                    'a' => hub_can($user, $key, 'a'),
                    'e' => hub_can($user, $key, 'e'),
                    'd' => hub_can($user, $key, 'd'),
                ],
                'fields' => $fields,
            ];
        }

        return $out;
    }

    /**
     * **نسخةُ المخطّط** (SF · C.4) — بصمةٌ قصيرةٌ حتميّةٌ على **شكل العقد العامّ**
     * (لا مُنطَّقةٌ بمستخدم): مفاتيحُ الوحدات + تسمياتُها + تصنيفُ مزامنتها + شكلُ
     * كلِّ حقلٍ (key/type/required/ref/multi/options/locked) + إصدارُ عقد الجوال.
     * تتغيّر متى تغيّر السجلُّ أو التصنيفُ أو العقد — فيعرف التطبيقُ أن يعيد الجلب.
     *
     * **الفصلُ عن ETag مقصود:** هذه نسخةُ العقد العامّة (واحدةٌ للجميع)، أما البصمةُ
     * (ETag) فمُنطَّقةٌ لكلِّ مستخدمٍ على ما يراه فعلاً — فتغيّرُ صلاحيةِ حقلٍ لدور
     * ما يبدّل بصمتَه (٢٠٠) دون أن يمسّ نسخةَ العقد العامّة. تُحوسَب مرّةً وتُخبَّأ.
     *
     * **`public static` (الطور G · G.1):** مصدرٌ واحدٌ لنسخةِ العقد — يعيد استعمالها
     * محرّكُ المزامنة كـ`sync_version` في كلِّ ردّ (`MobileSyncController`)، فيحمل
     * العميلُ مفهومَ نسخةٍ واحداً عبر الإقلاعِ والمخطّطِ والمزامنة (لا نظامَ نسخٍ ثانٍ).
     */
    public static function schemaVersion(): string
    {
        static $v = null;
        if ($v !== null) return $v;

        $desc = ['api' => (string) config('hub.mobile.api_version', Api::VERSION), 'modules' => []];
        foreach (hub_modules() as $key => $def) {
            $fields = [];
            foreach (($def['fields'] ?? []) as $f) {
                $fields[] = [
                    'key'      => $f['key'] ?? null,
                    'type'     => $f['type'] ?? null,
                    'required' => (bool) ($f['required'] ?? false),
                    'ref'      => $f['ref'] ?? null,
                    'multi'    => (bool) ($f['multi'] ?? false),
                    'options'  => $f['options'] ?? null,
                    'locked'   => (bool) ($f['locked'] ?? false),
                ];
            }
            $desc['modules'][$key] = [
                'label'  => $def['label'] ?? null,
                'sync'   => hub_sync_class($key),
                'fields' => $fields,
            ];
        }
        ksort($desc['modules']);   // حتميّةٌ لا تتبع ترتيبَ الملف

        return $v = substr(hash('sha256', (string) json_encode($desc, JSON_UNESCAPED_UNICODE)), 0, 12);
    }
}
