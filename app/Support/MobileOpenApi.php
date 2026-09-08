<?php

namespace App\Support;

use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * **مولِّدُ مواصفة OpenAPI 3.1 + بيانِ القدرات لسطحِ الجوال `/api/mobile/v1`** —
 * Mobile Readiness · الطور H · H.2/H.3.
 *
 * **مُشتقٌّ من المساراتِ الحيّة لا مكتوبٌ باليد:** المصدرُ الوحيدُ للمساراتِ هو سجلُّ
 * التوجيه نفسُه (`RouteFacade::getRoutes()` مُرشَّحاً على البادئة `api/mobile/v1`) —
 * فلا يُوثَّق مسارٌ لا وجودَ له، ولا يُغفَل مسارٌ مُسجَّل. لكلِّ مسارٍ تُلحَق **وصفةُ
 * تشغيلٍ** بمفتاح اسمِه (`route()->getName()`): ملخّصٌ وحمولةٌ وردودٌ وأكواد — والطريقةُ
 * والمسارُ ومعاملاتُه ووسمُ المصادقة (خلف `mobile.session`؟) تُستخرَج من المسار حيّاً.
 * مسارٌ بلا وصفةٍ يظهر بعمليّةٍ عامّةٍ صادقة (لا يُطمَس مسارٌ حقيقيّ أبداً).
 *
 * **مستقلٌّ تماماً عن `/api/v1`:** لا يمسّ `App\Support\OpenApi` ولا
 * `V1Controller::openapi` ولا `docs/openapi.json` — وثيقةٌ منفصلةٌ بعنوانٍ ومساراتٍ
 * مختلفة، تُقدَّم حيّةً على `GET /api/mobile/v1/openapi.json` (عامّةٌ — لا رمزَ وصول).
 *
 * **يعيد استعمالَ العقدِ الموحّد:** أكوادُ `Api::CODES` كاملةً (مع إبرازِ الأربعةِ
 * الجوّالة)، وغلافُ `{data|error, code, request_id}`، ورأسُ `X-API-Version`، وسكّةُ
 * If-Match/Idempotency-Key/ETag — فما يقوله العقدُ هو ما يفعله الخادم.
 *
 * **بيانُ القدرات (`capabilities()` · H · mobile-capabilities.json):** بيانٌ آليٌّ
 * لكلِّ مجالٍ (auth/context/schema/crud/actions/approvals/home/search/prefs/
 * notifications/comments/dm/push/files/scanner/tracking/sync/health): مساراتُه
 * (مُشتقّةً من السجلّ)، وتغطيةُ تصنيفِ المزامنة، والأكواد، والإعداداتُ الخارجيّةُ
 * الغائبة (NOT_CONFIGURED) — مُشتقٌّ من الواقع فلا ينحرف إلى خيال.
 */
class MobileOpenApi
{
    /** بادئةُ سطحِ الجوال — كلُّ ما يبدأ بها يدخل الوثيقة، ولا شيءَ سواه */
    public const PREFIX = 'api/mobile/v1';

    /** الوسمُ الذي تحمله المساراتُ خلف بوّابة رمز الوصول — يميّز المُصادَق من العامّ */
    private const AUTH_MIDDLEWARE = 'mobile.session';

    // ══════════════════════════ H.2 · مواصفةُ OpenAPI ══════════════════════════

    /**
     * وثيقةُ OpenAPI 3.1 كاملةً لسطحِ `/api/mobile/v1` — مبنيّةً من المساراتِ الحيّة.
     */
    public static function spec(): array
    {
        $paths = [];
        foreach (self::mobileRoutes() as $route) {
            $tmpl = '/' . ltrim($route->uri(), '/');           // Laravel يُبقي {param}/{param?}
            $item = self::pathItem($route);
            if ($item === []) continue;
            $paths[$tmpl] = array_merge($paths[$tmpl] ?? [], $item);
        }
        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => (string) setting('app.name', config('app.name')) . ' — Mobile API',
                'version' => (string) config('hub.mobile.api_version', Api::VERSION),
                'x-hub-version' => (string) config('hub.version'),
                'description' => self::intro(),
            ],
            'servers' => [['url' => rtrim((string) config('app.url'), '/')]],
            // الأمنُ الافتراضيّ رمزُ الوصول؛ والمساراتُ العامّةُ تُلغيه صراحةً (`security: []`)
            'security' => [['mobileBearerAuth' => []]],
            'tags' => self::tags(),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'mobileBearerAuth' => [
                        'type' => 'http', 'scheme' => 'bearer',
                        'bearerFormat' => 'mobile access token (قصيرُ الأجل · من POST auth/login|refresh)',
                        'description' => 'رمزُ وصولٍ قصيرُ الأجل يُصدره سطحُ الجوال — **مفهومٌ مستقلٌّ عن مفتاح تكامل `/api/v1`**. '
                            . 'يُخزَّن مُجزّأً (sha256) خادميّاً، مربوطٌ بالجهاز والجلسة، يُدوَّر برمزِ التحديث.',
                    ],
                ],
                'schemas' => self::schemas(),
                'parameters' => self::parameters(),
                'headers' => [
                    'X-Request-Id' => ['description' => 'معرّف الطلب — أرفقه عند طلب الدعم', 'schema' => ['type' => 'string']],
                    'X-API-Version' => ['description' => 'إصدار عقد API', 'schema' => ['type' => 'string']],
                    'ETag' => ['description' => 'بصمةُ الحمولة/نسخةُ السجل بين علامتي اقتباس — للقفل التفاؤليّ (If-Match) والتخبئة (If-None-Match)', 'schema' => ['type' => 'string']],
                    'Retry-After' => ['schema' => ['type' => 'integer']],
                    'X-Idempotent-Replay' => ['description' => 'true حين يُعاد ردٌّ محفوظٌ لمفتاح Idempotency', 'schema' => ['type' => 'boolean']],
                ],
                'responses' => self::errorResponses(),
            ],
            'x-error-codes' => Api::CODES,
            'x-mobile-error-codes' => self::mobileCodes(),
            'x-auth-flow' => self::authFlowDoc(),
            'x-deep-link' => self::deepLinkDoc(),
            'x-not-configured' => self::notConfiguredDoc(),
            'x-context-headers' => self::contextHeadersDoc(),
        ];
    }

    /** مقدّمةُ الوثيقة — العقدُ العامُّ الذي يسري على كلِّ نقطة */
    private static function intro(): string
    {
        return "سطحُ الجوال الأصيل (first-party) لتطبيق iOS/Android — **مستقلٌّ عن `/api/v1`** (مفتاح التكامل) ولا يمسّه.\n"
            . "المصادقةُ جلسةٌ من زوجِ رمزَين: وصولٌ قصيرُ الأجل (Bearer) + تحديثٌ متجدّدٌ لمرّة، كلاهما مُجزّأٌ خادميّاً ومربوطٌ بالجهاز.\n"
            . "كلُّ ردٍّ يحمل `X-Request-Id` و`X-API-Version`، وكلُّ خطأٍ يحمل `code` آليّاً (لا يُطالَب العميلُ بقراءة العربية) و`request_id`.\n"
            . "القفلُ التفاؤليّ عبر `If-Match` (نسخةُ السجل) ⇒ `VERSION_CONFLICT`، وعدمُ الأثرِ عبر `Idempotency-Key` على كلِّ جانبٍ قابلٍ للإعادة.\n"
            . "ترويساتُ السياق/التليمتري (X-Lynomia-*) **تضييقُ عرضٍ ووسمٌ لا تخويل** — راجع `x-context-headers`. "
            . "المعرّفاتُ الخارجيّة (FCM/APNs/App-Attest/Play-Integrity/الروابط العالميّة/روابط المتجر) **NOT_CONFIGURED** — راجع `x-not-configured`.";
    }

    /**
     * عنصرُ مسارٍ واحد (كلُّ طرائقه) — الطريقةُ/المعاملاتُ/المصادقةُ من المسار حيّاً،
     * والوصفةُ (ملخّص/حمولة/ردود) من خريطةِ الأسماء.
     */
    private static function pathItem(IlluminateRoute $route): array
    {
        $authed = in_array(self::AUTH_MIDDLEWARE, $route->gatherMiddleware(), true);
        $name = (string) $route->getName();
        $meta = self::opMeta()[$name] ?? null;
        $pathParams = self::pathParams($route);

        $out = [];
        foreach ($route->methods() as $verb) {
            $verb = strtolower($verb);
            if (in_array($verb, ['head', 'options'], true)) continue;

            $op = [
                'tags' => [$meta['tag'] ?? 'mobile'],
                'summary' => $meta['summary'] ?? ($verb . ' ' . $route->uri()),
                'operationId' => $name !== '' ? str_replace('.', '_', $name) : ($verb . '_' . md5($route->uri())),
            ];
            if ($name !== '') $op['x-route-name'] = $name;

            // المصادقة: العامّةُ تُلغي الأمنَ الافتراضيّ صراحةً
            $op['security'] = $authed ? [['mobileBearerAuth' => []]] : [];

            // المعاملات: مسارُها (حيّاً) + استعلامُها/ترويساتُها (من الوصفة)
            $params = $pathParams;
            foreach (($meta['params'][$verb] ?? $meta['params'] ?? []) as $p) {
                $params[] = is_string($p) ? ['$ref' => '#/components/parameters/' . $p] : $p;
            }
            if ($params) $op['parameters'] = array_values($params);

            // الحمولة
            $body = $meta['body'][$verb] ?? ($meta['bodyOnly'] ?? null) ?? (isset($meta['body']) && ! isset($meta['body'][$verb]) ? $meta['body'] : null);
            if (in_array($verb, ['post', 'put', 'patch'], true) && $body !== null) {
                $ct = ($meta['bodyType'][$verb] ?? $meta['bodyType'] ?? 'application/json');
                $op['requestBody'] = [
                    'required' => (bool) ($meta['bodyRequired'] ?? true),
                    'content' => [$ct => ['schema' => $body]],
                ];
            }

            // الردود: النجاحُ من الوصفة (أو عامّ) + الأخطاء
            $op['responses'] = self::responsesFor($verb, $meta, $authed);

            $out[$verb] = $op;
        }

        return $out;
    }

    /** معاملاتُ المسار مُشتقّةً من قالبِ الـURI حيّاً — بوصفٍ معروفٍ حيث أمكن */
    private static function pathParams(IlluminateRoute $route): array
    {
        $desc = [
            'module' => 'مفتاحُ الوحدة (من GET schema/modules)',
            'id' => 'معرّفُ السجل (UUID)',
            'action' => 'اسمُ الإجراء من allowlist الانتقالات (status|restore|restore-version|ack)',
            'user' => 'معرّفُ الطرفِ الآخر في المحادثة — المفتاحُ يُبنى خادميّاً من هويّتك+هذا (F8)',
            'session' => 'معرّفُ جلسةِ التتبّع الميدانيّ',
            'q' => 'نصُّ البحث/المسح (كود/باركود/سيريال)',
        ];
        $out = [];
        foreach ($route->parameterNames() as $pn) {
            $out[] = [
                'name' => $pn, 'in' => 'path', 'required' => true,
                'schema' => ['type' => 'string'],
                'description' => $desc[$pn] ?? '',
            ];
        }

        return $out;
    }

    /** ردودُ عمليّةٍ: النجاحُ المُوصَّف (أو عامّ) + مجموعةُ الأخطاء المناسبة للمصادقة */
    private static function responsesFor(string $verb, ?array $meta, bool $authed): array
    {
        $ok = $meta['ok'][$verb] ?? $meta['ok'] ?? null;
        $status = (string) ($meta['okStatus'][$verb] ?? $meta['okStatus'] ?? ($verb === 'post' && ($meta['created'] ?? false) ? '201' : '200'));

        if ($ok === null) {
            $ok = self::envelope(['type' => 'object', 'additionalProperties' => true]);
        }
        $okType = $meta['okType'][$verb] ?? $meta['okType'] ?? 'application/json';
        $resp = [$status => self::okResponse($meta['okDesc'] ?? 'نجاح', $ok, $meta['okHeaders'] ?? [], (string) $okType)];

        // 304 لنقاط التخبئة
        if (! empty($meta['etag'])) {
            $resp['304'] = ['description' => 'لم يتغيّر (If-None-Match طابق) — بلا جسم',
                'headers' => ['ETag' => ['$ref' => '#/components/headers/ETag']]];
        }

        $codes = $meta['errors'] ?? self::defaultErrors($verb);
        if ($authed) $codes = array_values(array_unique(array_merge(['401'], $codes)));
        $codes = array_values(array_unique(array_merge($codes, ['429'])));
        sort($codes);
        foreach ($codes as $c) {
            if (! isset($resp[(string) $c])) $resp[(string) $c] = ['$ref' => '#/components/responses/' . $c];
        }

        return $resp;
    }

    private static function defaultErrors(string $verb): array
    {
        return in_array($verb, ['post', 'put', 'patch', 'delete'], true)
            ? ['403', '404', '409', '422']
            : ['403', '404'];
    }

    private static function okResponse(string $desc, array $schema, array $headers, string $contentType = 'application/json'): array
    {
        $h = ['X-Request-Id' => ['$ref' => '#/components/headers/X-Request-Id']];
        foreach ($headers as $hn) $h[$hn] = ['$ref' => '#/components/headers/' . $hn];

        return ['description' => $desc, 'headers' => $h,
                'content' => [$contentType => ['schema' => $schema]]];
    }

    /** غلافُ نجاحٍ موحَّد `{data, request_id}` حول مخطّطِ الحمولة */
    private static function envelope(array $dataSchema): array
    {
        return ['type' => 'object', 'properties' => [
            'data' => $dataSchema,
            'request_id' => ['type' => 'string', 'nullable' => true],
        ]];
    }

    /** غلافُ قائمةٍ مرقَّمة (نمطُ `Api::list`) */
    private static function listEnvelope(array $itemSchema): array
    {
        return ['type' => 'object', 'properties' => [
            'data' => ['type' => 'array', 'items' => $itemSchema],
            'total' => ['type' => 'integer'], 'page' => ['type' => 'integer'], 'last_page' => ['type' => 'integer'],
            'meta' => ['$ref' => '#/components/schemas/ListMeta'],
            'request_id' => ['type' => 'string', 'nullable' => true],
        ]];
    }

    // ══════════════════════════ خريطةُ وصفاتِ التشغيل (بمفتاح اسمِ المسار) ══════════════════════════

    /**
     * وصفةُ كلِّ مسارٍ بمفتاح اسمِه — الطريقةُ/المسارُ/المصادقةُ تأتي من السجلّ، وهذه
     * تضيف الدلالةَ (ملخّص/حمولة/ردّ/أكواد). مسارٌ بلا مدخلٍ هنا يظهر بعمليّةٍ عامّة.
     */
    private static function opMeta(): array
    {
        $ref = fn (string $n) => ['$ref' => '#/components/schemas/' . $n];
        $env = fn (array $s) => self::envelope($s);
        $obj = fn (array $p, array $req = []) => $req
            ? ['type' => 'object', 'required' => $req, 'properties' => $p]
            : ['type' => 'object', 'properties' => $p];
        $str = ['type' => 'string'];
        $strN = ['type' => 'string', 'nullable' => true];
        $bool = ['type' => 'boolean'];
        $int = ['type' => 'integer'];

        return [
            // ── B · المصادقة (عامّة) ──
            'mobile.auth.login' => [
                'tag' => 'auth', 'bodyRequired' => true,
                'summary' => 'تسجيلُ الدخول (بريد+كلمة) — يُصدر جلسةً أو يطلب MFA (throttle 10/دقيقة)',
                'body' => $obj([
                    'email' => $str, 'password' => ['type' => 'string', 'format' => 'password'],
                    'installation_uuid' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 36, 'description' => 'مُعرّفُ تنصيبٍ يولّده التطبيق'],
                    'platform' => ['type' => 'string', 'enum' => ['ios', 'android']],
                    'device_model' => $strN, 'os_version' => $strN, 'app_version' => $strN,
                    'app_build' => $strN, 'locale' => $strN, 'tz' => $strN, 'push_capable' => ['type' => 'boolean', 'nullable' => true],
                ], ['email', 'password', 'installation_uuid', 'platform']),
                'ok' => $env($ref('SessionIssued')),
                'okDesc' => 'جلسةٌ صدرت (لا MFA)',
                'errors' => ['401', '403', '422'],
            ],
            'mobile.auth.mfa_verify' => [
                'tag' => 'auth', 'bodyRequired' => true,
                'summary' => 'التحقق بخطوتين (TOTP) لتحدٍّ قائم — يُصدر جلسةً (throttle 6/دقيقة)',
                'body' => $obj(['challenge_id' => $str, 'code' => $str, 'app_version' => $strN], ['challenge_id', 'code']),
                'ok' => $env($ref('SessionIssued')),
                'errors' => ['401', '403', '422'],
            ],
            'mobile.auth.refresh' => [
                'tag' => 'auth', 'bodyRequired' => false,
                'summary' => 'تدويرُ الجلسة برمز التحديث (لمرّة — إعادةُ استعمالِ رمزٍ مُدوَّرٍ تُبطِل العائلةَ)',
                'body' => $obj(['refresh_token' => ['type' => 'string', 'description' => 'أو في ترويسة Authorization: Bearer'], 'app_version' => $strN, 'platform' => $strN]),
                'ok' => $env($ref('SessionTokens')),
                'errors' => ['401', '403'],
            ],
            'mobile.app_config' => [
                'tag' => 'auth',
                'summary' => 'إعداداتُ ما قبل الدخول (وقت الخادم/بوّابة الإصدار/الصيانة/روابط المتجر) — بلا أسرار',
                'ok' => $env($ref('AppConfig')), 'errors' => [],
            ],
            'mobile.health' => [
                'tag' => 'health',
                'summary' => 'حالةُ الخدمة (ok|maintenance|lockdown|update_required) — عامّة بلا تليمتري بنية',
                'ok' => $env($obj(['status' => ['type' => 'string', 'enum' => ['ok', 'maintenance', 'lockdown', 'update_required']],
                    'maintenance' => $bool, 'lockdown' => $bool, 'update_required' => $bool, 'server_time' => $str])),
                'errors' => [],
            ],
            'mobile.openapi' => [
                'tag' => 'meta',
                'summary' => 'هذه المواصفة (OpenAPI 3.1) — مولَّدةٌ من المسارات الحيّة، عامّة',
                'ok' => ['type' => 'object', 'additionalProperties' => true],
                'okDesc' => 'OpenAPI 3.1', 'errors' => [],
            ],

            // ── B · المصادقة (مُصادَقة) ──
            'mobile.auth.logout' => ['tag' => 'auth', 'summary' => 'إبطالُ الجلسة الحالية + تعطيلُ رموز الدفع لتنصيبها',
                'ok' => $env($obj(['revoked' => $bool])), 'errors' => []],
            'mobile.auth.logout_all' => ['tag' => 'auth', 'summary' => 'إبطالُ كلِّ جلسات المستخدم + تعطيلُ رموز دفعه',
                'ok' => $env($obj(['revoked' => $int])), 'errors' => []],
            'mobile.auth.sessions.index' => ['tag' => 'auth', 'summary' => 'جلساتي أنا (جهاز/آخر استخدام/عنوان) — لا جلسةَ غيري',
                'ok' => $env($obj(['sessions' => ['type' => 'array', 'items' => $ref('SessionRow')]])), 'errors' => []],
            'mobile.auth.sessions.destroy' => ['tag' => 'auth', 'summary' => 'إبطالُ جلسةٍ أملكها (404 إن ليست لي — لا IDOR)',
                'ok' => $env($obj(['revoked' => $bool, 'id' => $str])), 'errors' => ['404']],
            'mobile.auth.step_up' => ['tag' => 'auth', 'bodyRequired' => true,
                'summary' => 'تصعيدُ الهوية (كلمة/TOTP) ⇒ منحةٌ مربوطةٌ بالجلسة+الغرض+الانتهاء',
                'body' => $obj(['purpose' => $str, 'credential' => ['type' => 'string', 'format' => 'password']], ['purpose', 'credential']),
                'ok' => $env($obj(['granted' => $bool, 'purpose' => $str, 'method' => ['type' => 'string', 'enum' => ['totp', 'password']],
                    'granted_at' => $str, 'expires_at' => $str, 'stepup_minutes' => $int])),
                'errors' => ['422', '428']],

            // ── C · السياق/الإقلاع/المخطّط ──
            'mobile.context' => ['tag' => 'context', 'summary' => 'شركاتي وعملائي المسموحون + التضييقُ النشط (لا يُذكَر ما لا يُرى)',
                'ok' => $env($obj(['user' => $obj(['id' => $str, 'is_owner' => $bool]),
                    'companies' => $ref('ContextDimension'), 'clients' => $ref('ContextDimension')])), 'errors' => []],
            'mobile.bootstrap' => ['tag' => 'context', 'etag' => true, 'okHeaders' => ['ETag'],
                'params' => ['If-None-Match'],
                'summary' => 'لقطةُ إقلاعٍ باردة (مستخدم/سياق/أعلام/إصدارات/غير مقروء/تنقّل) — ETag/304',
                'ok' => $env($ref('Bootstrap')), 'errors' => []],
            'mobile.schema.modules' => ['tag' => 'schema', 'etag' => true, 'okHeaders' => ['ETag'], 'params' => ['If-None-Match'],
                'summary' => 'الوحداتُ وحقولُها المرئيّة (بلا اسم جدول/عمود فيزيائيّ) + تصنيفُ المزامنة — ETag/304',
                'ok' => $env($obj(['schema_version' => $str, 'modules' => ['type' => 'array', 'items' => $ref('SchemaModule')]])), 'errors' => []],
            'mobile.schema' => ['tag' => 'schema', 'etag' => true, 'okHeaders' => ['ETag'], 'params' => ['If-None-Match'],
                'summary' => 'المخطّطُ الكامل (نسخة + عقد الجوال + الوحدات بحقولها) — ETag/304',
                'ok' => $env($obj(['schema_version' => $str, 'mobile_api_version' => $str, 'modules' => ['type' => 'array', 'items' => $ref('SchemaModule')]])), 'errors' => []],

            // ── D · الأعمال ──
            'mobile.approvals.index' => ['tag' => 'approvals', 'summary' => 'طابورُ اعتماداتي المعلّقة (المعتمِدون فقط) — كلٌّ بوجهةِ رابطٍ عميق',
                'params' => ['per'], 'ok' => self::listEnvelope($ref('ApprovalCard')), 'errors' => []],
            'mobile.approvals.show' => ['tag' => 'approvals', 'summary' => 'تفصيلُ طلبٍ أراه (معتمِدٌ في نطاقه أو طالبُه)',
                'ok' => $env($obj(['approval' => $ref('ApprovalCard')])), 'errors' => ['403', '404']],
            'mobile.approvals.approve' => ['tag' => 'approvals', 'summary' => 'اعتمادُ طلبٍ (يزيل «نفّذها من الواجهة») — Idempotency',
                'params' => ['Idempotency-Key'], 'body' => $obj([]), 'bodyRequired' => false,
                'ok' => $env($ref('ApprovalDecision')), 'errors' => ['403', '409', '422']],
            'mobile.approvals.reject' => ['tag' => 'approvals', 'summary' => 'رفضُ طلبٍ (بسببٍ اختياريّ) — Idempotency',
                'params' => ['Idempotency-Key'], 'body' => $obj(['note' => $strN]), 'bodyRequired' => false,
                'ok' => $env($ref('ApprovalDecision')), 'errors' => ['403', '409', '422']],
            'mobile.home' => ['tag' => 'home', 'summary' => 'مساحةُ العمل (عملي/تقترب/اعتمادات/انتباه/أخيرة/مشاريع/إشعارات)',
                'ok' => $env($ref('Home')), 'errors' => []],
            'mobile.search' => ['tag' => 'search', 'summary' => 'بحثٌ مُنطَّق — كلُّ نتيجةٍ {module,id} وجهةُ رابطٍ عميق',
                'params' => ['q', 'per_module', 'limit'],
                'ok' => $env($obj(['q' => $str, 'results' => ['type' => 'array', 'items' => $ref('SearchHit')], 'count' => $int])), 'errors' => []],
            'mobile.prefs.index' => ['tag' => 'prefs', 'summary' => 'تفضيلاتُ الإشعار (خريطةُ الكتم) + المثبّتات — هويّتي وحدي',
                'ok' => $env($ref('Prefs')), 'errors' => []],
            'mobile.prefs.update' => ['tag' => 'prefs', 'summary' => 'حفظُ خريطةِ الكتم (استبدالٌ كامل)',
                'body' => $obj(['mute' => ['type' => 'array', 'items' => $str]]), 'bodyRequired' => false,
                'ok' => $env($obj(['notify' => $obj(['mute' => ['type' => 'array', 'items' => $str]])])), 'errors' => []],
            'mobile.prefs.pin' => ['tag' => 'prefs', 'summary' => 'تثبيت/فكُّ وجهةٍ في «مثبّتاتي» — Idempotency اختياريّ',
                'params' => ['Idempotency-Key'], 'body' => $obj(['token' => $str], ['token']),
                'ok' => $env($obj(['token' => $str, 'pinned' => $bool, 'pins' => ['type' => 'array', 'items' => $str], 'message' => $str])),
                'errors' => ['403', '422']],

            // ── E · الاتصال ──
            'mobile.notifications.index' => ['tag' => 'notifications', 'summary' => 'إشعاراتي (مؤشّرٌ على created_at,id) — هويّةٌ خاصّة، كلٌّ بوجهةٍ قانونيّة',
                'params' => ['per', 'unread', 'cursor'],
                'ok' => $env($obj(['notifications' => ['type' => 'array', 'items' => $ref('Notification')],
                    'unread' => $int, 'cursor' => $obj(['next' => $strN, 'has_more' => $bool, 'per' => $int])])), 'errors' => []],
            'mobile.notifications.unread' => ['tag' => 'notifications', 'summary' => 'عدُّ غير المقروء لي',
                'ok' => $env($obj(['unread' => $int])), 'errors' => []],
            'mobile.notifications.read_all' => ['tag' => 'notifications', 'summary' => 'تعليمُ كلِّ إشعاراتي مقروءة',
                'body' => $obj([]), 'bodyRequired' => false,
                'ok' => $env($obj(['marked' => $int, 'unread' => $int])), 'errors' => []],
            'mobile.notifications.target' => ['tag' => 'notifications', 'summary' => 'وجهةُ إشعاري القانونيّة {module,id,action} — قراءةٌ لا تختم القراءة',
                'ok' => $env($obj(['id' => $str, 'target' => $ref('DeepLinkTarget'), 'module' => $strN, 'record_id' => $strN])),
                'errors' => ['404']],
            'mobile.notifications.read' => ['tag' => 'notifications', 'summary' => 'تعليمُ إشعاري مقروءاً (يعيد الوجهةَ القانونيّة)',
                'body' => $obj([]), 'bodyRequired' => false,
                'ok' => $env($obj(['id' => $str, 'read' => $bool, 'unread' => $int, 'target' => $ref('DeepLinkTarget')])),
                'errors' => ['404']],
            'mobile.comments.index' => ['tag' => 'comments', 'summary' => 'خيطُ تعليقاتِ سجلٍّ أراه (guardTarget) — قراءةٌ خالصة',
                'params' => ['module_q', 'record_q'],
                'ok' => $env($obj(['module' => $str, 'record' => $strN, 'comments' => ['type' => 'array', 'items' => $ref('Comment')]])),
                'errors' => ['403', '404', '422']],
            'mobile.comments.store' => ['tag' => 'comments', 'bodyRequired' => true, 'bodyType' => 'multipart/form-data',
                'summary' => 'نشرُ تعليقٍ (guardTarget/guardConversation + سلامةُ الرد) — Idempotency، مرفقٌ اختياريّ',
                'params' => ['Idempotency-Key'],
                'body' => $obj(['module' => $str, 'record' => $strN, 'record_id' => $strN, 'parent_id' => $strN,
                    'body' => ['type' => 'string', 'maxLength' => 4000], 'att' => ['type' => 'string', 'format' => 'binary'],
                    'mention' => ['type' => 'array', 'items' => $str], 'internal' => ['type' => 'boolean', 'nullable' => true]], ['module', 'body']),
                'ok' => $env($obj(['comment' => $ref('Comment')])), 'errors' => ['403', '404', '422']],
            'mobile.dm.threads' => ['tag' => 'dm', 'summary' => 'خيوطي أنا طرفاً فيها (لا خيطَ ثالثَين · F8)',
                'ok' => $env($obj(['threads' => ['type' => 'array', 'items' => $ref('DmThread')], 'unread_total' => $int])), 'errors' => []],
            'mobile.dm.messages' => ['tag' => 'dm', 'summary' => 'خيطي مع {user} — المفتاحُ من هويّتي+{user} خادميّاً (F8)',
                'ok' => $env($obj(['user' => $ref('UserRef'), 'messages' => ['type' => 'array', 'items' => $ref('DmMessage')]])),
                'errors' => ['404']],
            'mobile.dm.send' => ['tag' => 'dm', 'bodyRequired' => true, 'bodyType' => 'multipart/form-data',
                'summary' => 'إرسالٌ إلى {user} (reachable + ليس النفس) — Idempotency، مرفقٌ اختياريّ',
                'params' => ['Idempotency-Key'],
                'body' => $obj(['body' => ['type' => 'string', 'maxLength' => 4000], 'att' => ['type' => 'string', 'format' => 'binary']], ['body']),
                'ok' => $env($obj(['message' => $ref('DmMessage')])), 'errors' => ['404', '422']],
            'mobile.dm.read' => ['tag' => 'dm', 'summary' => 'ختمُ قراءةِ خيطي مع {user}',
                'body' => $obj([]), 'bodyRequired' => false,
                'ok' => $env($obj(['user' => $ref('UserRef'), 'marked' => $int, 'unread_total' => $int])), 'errors' => ['404']],
            'mobile.push.register' => ['tag' => 'push', 'bodyRequired' => true,
                'summary' => 'تسجيلُ رمزِ دفعٍ (dedupe عابرُ المستخدمين · F7) — التنصيبُ من الجلسة لا العميل',
                'params' => ['Idempotency-Key'],
                'body' => $obj(['platform' => ['type' => 'string', 'enum' => ['ios', 'android']],
                    'provider' => ['type' => 'string', 'nullable' => true, 'enum' => ['fcm', 'apns', null]],
                    'token' => ['type' => 'string', 'maxLength' => 512]], ['platform', 'token']),
                'ok' => $env($obj(['registered' => $bool, 'token' => $ref('PushTokenCard'), 'delivery' => $ref('DeliveryHint')])),
                'errors' => ['422']],
            'mobile.push.unregister' => ['tag' => 'push', 'bodyRequired' => true,
                'summary' => 'إبطالُ رمزِ دفعٍ لي وحدي (لا IDOR)',
                'body' => $obj(['provider' => ['type' => 'string', 'nullable' => true], 'token' => ['type' => 'string', 'maxLength' => 512]], ['token']),
                'ok' => $env($obj(['revoked' => $int])), 'errors' => ['422']],
            'mobile.push.admin.status' => ['tag' => 'push', 'summary' => 'حالةُ الدفعِ (للمالك) — صادقةٌ بلا سرّ (NOT_CONFIGURED)',
                'ok' => $env($obj(['push' => $ref('PushStatus')])), 'errors' => ['403']],
            'mobile.push.admin.test' => ['tag' => 'push', 'summary' => 'اختبارُ الدفعِ (للمالك) — يقول not_configured بلا اعتماد (لا نجاحٌ مُزيَّف)',
                'body' => $obj([]), 'bodyRequired' => false,
                'ok' => $env($obj(['overall' => ['type' => 'string', 'enum' => ['not_configured', 'no_tokens', 'attempted']],
                    'configured' => $bool, 'driver' => $strN, 'tokens_tested' => $int,
                    'results' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]]])),
                'errors' => ['403']],

            // ── F · ملفّات/ماسح/موقع ──
            'mobile.files.upload_session' => ['tag' => 'files', 'bodyRequired' => true,
                'summary' => 'بدءُ جلسةِ رفعٍ مقطَّع (guardRecord + حاجزُ الامتداد + سقفُ الحجم)',
                'body' => $obj(['module' => $str, 'record_id' => $str, 'filename' => $str, 'mime' => $strN, 'size' => ['type' => 'integer', 'nullable' => true]],
                    ['module', 'record_id', 'filename']),
                'ok' => $env($obj(['session' => $str, 'chunk_size' => $int, 'max_parts' => $int, 'max_bytes' => $int, 'module' => $str, 'record_id' => $str])),
                'errors' => ['403', '404', '413', '422']],
            'mobile.files.upload_chunk' => ['tag' => 'files', 'bodyRequired' => true, 'bodyType' => 'multipart/form-data',
                'summary' => 'إلحاقُ قطعةٍ بالترتيب (i صفريّ) — من مجلّد صاحبها',
                'body' => $obj(['i' => $int, 'chunk' => ['type' => 'string', 'format' => 'binary']], ['i', 'chunk']),
                'ok' => $env($obj(['session' => $str, 'received' => $int, 'next' => $int])), 'errors' => ['413', '422']],
            'mobile.files.upload_complete' => ['tag' => 'files', 'bodyRequired' => false,
                'summary' => 'تجميعُ القطع وإرفاقُها (Idempotency) — الجوهرُ المشترك: validateUpload+guardRecord+attach',
                'params' => ['Idempotency-Key'],
                'body' => $obj(['parts' => ['type' => 'integer', 'nullable' => true], 'filename' => $strN, 'kind' => $strN]),
                'ok' => $env($ref('AttachmentsResult')), 'errors' => ['403', '404', '422']],
            'mobile.files.attach' => ['tag' => 'files', 'bodyRequired' => true, 'bodyType' => 'multipart/form-data',
                'summary' => 'رفعٌ مفردٌ (multipart) — لا base64 ولا رابطٌ عامّ، Idempotency',
                'params' => ['Idempotency-Key'],
                'body' => $obj(['module' => $str, 'record_id' => $str, 'kind' => $strN,
                    'file' => ['type' => 'string', 'format' => 'binary'],
                    'files[]' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'binary']]], ['module', 'record_id']),
                'ok' => $env($ref('AttachmentsResult')), 'errors' => ['403', '404', '413', '422']],
            'mobile.files.download' => ['tag' => 'files', 'summary' => 'تنزيلٌ مُصادَق (guardRecord + حاجزُ الإصابة) — Content-Disposition: attachment، لا رابطٌ عامّ',
                'ok' => ['type' => 'string', 'format' => 'binary'], 'okDesc' => 'بايتاتُ الملف',
                'okType' => 'application/octet-stream', 'errors' => ['403', '404', '423']],
            'mobile.files.stream' => ['tag' => 'files', 'summary' => 'بثٌّ مُصادَق للعرض داخل التطبيق (صور/PDF) — Content-Disposition: inline + nosniff',
                'ok' => ['type' => 'string', 'format' => 'binary'], 'okDesc' => 'بايتاتُ الملف (inline)',
                'okType' => 'application/octet-stream', 'errors' => ['403', '404', '415', '423']],
            'mobile.identity.resolve' => ['tag' => 'scanner', 'summary' => 'المحلّلُ الموحّد (عهدة/باركود/سيريال) — بصلاحية المستخدم ونطاقه',
                'ok' => $obj(['type' => ['type' => 'string', 'enum' => ['asset', 'product', 'stock', 'none']],
                    'module' => $strN, 'id' => $strN, 'code' => $strN, 'name' => $strN, 'gtin' => ['type' => 'boolean', 'nullable' => true]]),
                'okDesc' => 'نتيجةُ الحلّ', 'errors' => ['403']],
            'mobile.tracking.start' => ['tag' => 'tracking', 'bodyRequired' => true,
                'summary' => 'بدءُ تتبّعٍ ميدانيٍّ بموافقةٍ صريحة (consent=true) — لأصحاب الدور الميدانيّ',
                'body' => $obj(['consent' => $bool, 'field_day' => $strN], ['consent']),
                'ok' => $env($obj(['session' => $str, 'status' => $str, 'started_at' => $str])),
                'okStatus' => '201', 'errors' => ['403', '422']],
            'mobile.tracking.points' => ['tag' => 'tracking', 'bodyRequired' => true,
                'summary' => 'استيعابُ دفعةِ نقاطٍ (Idempotency + منعُ تكرارٍ بنيويّ) — لجلستي حصراً',
                'params' => ['Idempotency-Key'],
                'body' => $obj(['points' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]]], ['points']),
                'ok' => $env(['type' => 'object', 'additionalProperties' => true]), 'errors' => ['403', '404', '422']],
            'mobile.tracking.end' => ['tag' => 'tracking', 'summary' => 'إنهاءُ الجلسةِ صراحةً (يمنع التتبّعَ الدائمَ الخفيّ)',
                'body' => $obj([]), 'bodyRequired' => false,
                'ok' => $env(['type' => 'object', 'additionalProperties' => true]), 'errors' => ['403', '404']],

            // ── G · المزامنة ──
            'mobile.sync' => ['tag' => 'sync', 'summary' => 'مزامنةٌ تزايُديّة يقودها تصنيفُ الوحدة (سجلّات+شواهدُ حذفٍ+مؤشّر، أو سياسةٌ صادقةٌ بلا سجلّ)',
                'params' => ['updated_since', 'cursor', 'limit'],
                'ok' => $env($ref('SyncPage')), 'errors' => ['403', '404', '422']],

            // ── D · CRUD + الإجراءات (catch-all {module}) ──
            'mobile.resource.actions' => ['tag' => 'actions', 'summary' => 'allowlist الانتقالات الصالحة لحالة السجل (لا «نفّذ أيَّ شيء»)',
                'ok' => $env($ref('ActionList')), 'errors' => ['403', '404']],
            'mobile.resource.run_action' => ['tag' => 'actions', 'bodyRequired' => false,
                'summary' => 'تنفيذُ إجراءٍ من الـallowlist (status/restore/restore-version/ack) — Idempotency + step-up إن لزم',
                'params' => ['Idempotency-Key'],
                'body' => $obj(['to' => $strN, 'status' => $strN, 'version' => ['type' => 'integer', 'nullable' => true]]),
                'ok' => $env(['type' => 'object', 'additionalProperties' => true]),
                'errors' => ['403', '404', '409', '422', '428']],
            'mobile.resource.index' => ['tag' => 'crud', 'summary' => 'قائمةُ سجلّاتِ وحدةٍ مُنطَّقة (hub_scope + تضييقُ السياق)',
                'params' => ['q', 'status', 'page', 'per', 'sort', 'dir', 'fields', 'updated_since', 'trash'],
                'ok' => self::listEnvelope($ref('Record')), 'errors' => ['403', '404']],
            'mobile.resource.store' => ['tag' => 'crud', 'bodyRequired' => true, 'created' => true,
                'summary' => 'إنشاءُ سجلٍّ (Idempotency-Key · مالكُ الجوال)',
                'params' => ['Idempotency-Key'], 'body' => $ref('RecordWrite'),
                'ok' => $env($ref('Record')), 'okStatus' => '201', 'errors' => ['403', '404', '409', '422']],
            'mobile.resource.show' => ['tag' => 'crud', 'summary' => 'سجلٌّ واحدٌ بحقول الدور + ETag (نسخةُ السجل)',
                'params' => ['fields'], 'okHeaders' => ['ETag'],
                'ok' => $env($ref('Record')), 'errors' => ['403', '404']],
            'mobile.resource.update' => ['tag' => 'crud', 'bodyRequired' => true,
                'summary' => 'استبدالٌ كامل؛ محميٌّ بالموافقة ⇒ يُصفُّ طلباً (202 APPROVAL_REQUIRED) لا يُسدّ',
                'params' => ['If-Match'], 'body' => $ref('RecordWrite'),
                'ok' => $env($ref('Record')), 'errors' => ['403', '404', '409', '422', '428']],
            'mobile.resource.patch' => ['tag' => 'crud', 'bodyRequired' => true,
                'summary' => 'تعديلٌ جزئيّ (الحقول المُرسَلة)؛ محميٌّ ⇒ يُصفُّ طلباً (202)',
                'params' => ['If-Match'], 'body' => $ref('RecordWrite'),
                'ok' => $env($ref('Record')), 'errors' => ['403', '404', '409', '422', '428']],
            'mobile.resource.destroy' => ['tag' => 'crud', 'summary' => 'نقلٌ للسلة؛ محميٌّ ⇒ يُصفُّ طلباً (202)',
                'ok' => $env($obj(['deleted' => $bool])), 'errors' => ['403', '404', '409', '428']],
        ];
    }

    // ══════════════════════════ مخطّطاتٌ ومعاملاتٌ وردودُ أخطاء ══════════════════════════

    private static function schemas(): array
    {
        $s = ['type' => 'string'];
        $sN = ['type' => 'string', 'nullable' => true];
        $b = ['type' => 'boolean'];
        $i = ['type' => 'integer'];
        $dt = ['type' => 'string', 'format' => 'date-time', 'nullable' => true];

        return [
            'Error' => ['type' => 'object', 'required' => ['error', 'code', 'message'], 'properties' => [
                'error' => ['type' => 'string', 'description' => 'الرسالة (مفتاح التوافق القديم)'],
                'code' => ['type' => 'string', 'enum' => array_keys(Api::CODES)],
                'message' => $s,
                'details' => ['type' => 'object', 'additionalProperties' => true],
                'errors' => ['type' => 'object', 'description' => 'أخطاء التحقق بالحقل (422)', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                'request_id' => $sN,
            ]],
            'ListMeta' => ['type' => 'object', 'properties' => [
                'page' => $i, 'per' => $i, 'total' => $i, 'last_page' => $i, 'has_more' => $b,
                'sort' => $s, 'dir' => ['type' => 'string', 'enum' => ['asc', 'desc']], 'status' => $sN,
            ]],
            'MobileUser' => ['type' => 'object', 'properties' => [
                'id' => $s, 'name' => $s, 'email' => $s, 'role' => $sN, 'is_owner' => $b,
            ]],
            'SessionTokens' => ['type' => 'object', 'properties' => [
                'access_token' => ['type' => 'string', 'description' => 'رمزُ الوصول (Bearer) — قصيرُ الأجل، يُعاد مرّةً'],
                'refresh_token' => ['type' => 'string', 'description' => 'رمزُ التحديث — لمرّة، يُدوَّر'],
                'token_type' => ['type' => 'string', 'enum' => ['Bearer']],
                'session_id' => $s, 'installation_id' => $s,
                'access_expires_at' => $dt, 'refresh_expires_at' => $dt, 'access_expires_in' => ['type' => 'integer', 'nullable' => true],
            ]],
            'SessionIssued' => ['allOf' => [
                ['$ref' => '#/components/schemas/SessionTokens'],
                ['type' => 'object', 'properties' => ['user' => ['$ref' => '#/components/schemas/MobileUser']]],
            ]],
            'SessionRow' => ['type' => 'object', 'properties' => [
                'id' => $s, 'platform' => $sN, 'app_version' => $sN, 'device_model' => $sN, 'os_version' => $sN,
                'last_used_at' => $dt, 'last_ip' => $sN, 'created_at' => $dt, 'revoked_at' => $dt,
                'active' => $b, 'current' => $b,
            ]],
            'DeepLinkTarget' => ['type' => ['object', 'null'], 'description' => 'الوجهةُ القانونيّة — أو null (يعود التطبيقُ لقائمة الإشعارات)',
                'properties' => [
                    'module' => $s, 'id' => $s,
                    'action' => ['type' => 'string', 'enum' => ['show'], 'description' => 'الفعلُ القانونيّ — لا اسمَ شاشةِ جوالٍ صلب'],
                ]],
            'AppConfig' => ['type' => 'object', 'properties' => [
                'mobile_api_version' => $s, 'server_time' => $s, 'timezone' => $s,
                'maintenance' => $b, 'maintenance_message' => $sN, 'lockdown' => $b, 'login_available' => $b,
                'version_gate' => ['$ref' => '#/components/schemas/VersionGate'],
                'update_required' => $b, 'support_url' => $sN,
                'store_urls' => ['type' => 'object', 'properties' => ['ios' => $sN, 'android' => $sN]],
            ]],
            'VersionGate' => ['type' => 'object', 'description' => 'فارغٌ = لا حجب (نسخُ التطوير تمرّ)', 'properties' => [
                'ios' => ['type' => 'object', 'properties' => ['min' => $sN, 'latest' => $sN]],
                'android' => ['type' => 'object', 'properties' => ['min' => $sN, 'latest' => $sN]],
                'force_update' => $b,
            ]],
            'ContextDimension' => ['type' => 'object', 'properties' => [
                'restricted' => $b, 'active' => $sN, 'count' => $i, 'has_more' => $b,
                'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => $s, 'name' => $s]]],
            ]],
            'Bootstrap' => ['type' => 'object', 'properties' => [
                'user' => ['$ref' => '#/components/schemas/MobileUser'],
                'context' => ['type' => 'object', 'properties' => [
                    'companies' => ['$ref' => '#/components/schemas/ContextDimension'],
                    'clients' => ['$ref' => '#/components/schemas/ContextDimension'],
                ]],
                'feature_flags' => ['type' => 'object', 'additionalProperties' => $b,
                    'description' => 'أعلامٌ آمنةٌ لهويّة المُنادي — تقود العرضَ لا التخويل (الخادمُ يعيد الفحص)'],
                'timezone' => $s,
                'versions' => ['type' => 'object', 'properties' => ['app' => $s, 'mobile_api' => $s, 'schema' => $s]],
                'unread_notifications' => $i,
                'nav' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
                'schema_version' => $s, 'server_time' => $s,
            ]],
            'SchemaModule' => ['type' => 'object', 'properties' => [
                'key' => $s, 'label' => $s,
                'sync_class' => ['type' => 'string', 'enum' => (array) config('hub.mobile_sync.classes', [])],
                'can' => ['type' => 'object', 'properties' => ['v' => $b, 'a' => $b, 'e' => $b, 'd' => $b]],
                'fields' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'key' => $s, 'label' => $s, 'type' => $s, 'required' => $b, 'ref' => $sN,
                    'multi' => $b, 'options' => ['type' => 'array', 'items' => $s, 'nullable' => true],
                    'hint' => $sN, 'readonly' => $b,
                ]]],
            ]],
            'ApprovalCard' => ['type' => 'object', 'properties' => [
                'id' => $s, 'title' => $s, 'type' => $sN, 'status' => $s, 'op' => $sN, 'amount' => $sN, 'currency' => $sN,
                'due' => $sN, 'reason' => $sN, 'requested_by' => $sN,
                'target' => ['type' => ['object', 'null'], 'properties' => ['module' => $s, 'id' => $s]],
                'record_version' => ['nullable' => true], 'created_at' => $dt,
            ]],
            'ApprovalDecision' => ['type' => 'object', 'properties' => [
                'code' => $s, 'message' => $s, 'did' => $sN,
                'approval' => ['type' => ['object', 'null'], 'properties' => ['id' => $s, 'status' => $s]],
            ]],
            'Home' => ['type' => 'object', 'properties' => [
                'my_work' => ['type' => 'object', 'additionalProperties' => true],
                'due' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
                'approvals' => ['type' => 'object', 'properties' => ['count' => $i]],
                'attention' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
                'recent' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
                'projects' => ['type' => 'object', 'additionalProperties' => true],
                'notifications' => ['type' => 'object', 'properties' => ['unread' => $i]],
                'server_time' => $s,
            ]],
            'SearchHit' => ['type' => 'object', 'properties' => [
                'module' => $s, 'id' => $s, 'name' => $s, 'label' => $s,
            ]],
            'Prefs' => ['type' => 'object', 'properties' => [
                'notify' => ['type' => 'object', 'properties' => [
                    'mute' => ['type' => 'array', 'items' => $s],
                    'muteable' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['key' => $s, 'label' => $s, 'muted' => $b]]],
                ]],
                'pins' => ['type' => 'object', 'additionalProperties' => true],
            ]],
            'Notification' => ['type' => 'object', 'properties' => [
                'id' => $s, 'kind' => $s, 'text' => $sN, 'read' => $b, 'module' => $sN, 'record_id' => $sN,
                'target' => ['$ref' => '#/components/schemas/DeepLinkTarget'], 'created_at' => $dt,
            ]],
            'Comment' => ['type' => 'object', 'properties' => [
                'id' => $s, 'parent_id' => $sN, 'user' => ['$ref' => '#/components/schemas/UserRef'],
                'body' => $s, 'mentions' => ['type' => 'array', 'items' => $s], 'internal' => $b, 'pinned' => $b,
                'resolved' => $b, 'has_attachment' => $b,
                'reactions' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['emoji' => $s, 'count' => $i, 'mine' => $b]]],
                'created_at' => $dt, 'replies' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
            ]],
            'UserRef' => ['type' => 'object', 'properties' => ['id' => $s, 'name' => $s]],
            'DmThread' => ['type' => 'object', 'properties' => [
                'user' => ['$ref' => '#/components/schemas/UserRef'], 'unread' => $i,
                'last' => ['type' => 'object', 'properties' => ['id' => $s, 'mine' => $b, 'excerpt' => $s, 'read' => $b, 'created_at' => $dt]],
            ]],
            'DmMessage' => ['type' => 'object', 'properties' => [
                'id' => $s, 'from_id' => $s, 'to_id' => $s, 'mine' => $b, 'body' => $sN, 'deleted' => $b,
                'has_attachment' => $b, 'read' => $b, 'created_at' => $dt,
            ]],
            'PushTokenCard' => ['type' => 'object', 'properties' => [
                'id' => $s, 'platform' => $s, 'provider' => $sN, 'installation_id' => $s, 'confirmed_at' => $dt, 'revoked' => $b,
            ]],
            'DeliveryHint' => ['type' => 'object', 'properties' => ['configured' => $b, 'driver' => $sN]],
            'PushStatus' => ['type' => 'object', 'properties' => [
                'driver' => $sN, 'configured' => $b, 'requested' => $sN,
                'has_project_id' => $b, 'has_access_token' => $b,
            ]],
            'AttachmentsResult' => ['type' => 'object', 'properties' => [
                'module' => $s, 'record_id' => $s, 'count' => $i,
                'files' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'id' => $s, 'original_name' => $sN, 'mime' => $sN, 'size' => $i, 'checksum' => $sN, 'kind' => $sN,
                    'created_at' => $dt,
                    'download' => ['type' => 'string', 'description' => 'مسارٌ نسبيٌّ لنقطةٍ مُصادَقة — لا رابطٌ عامّ'],
                    'stream' => ['type' => 'string', 'description' => 'مسارٌ نسبيٌّ لنقطةٍ مُصادَقة — لا رابطٌ عامّ'],
                ]]],
            ]],
            'SyncPage' => ['type' => 'object', 'properties' => [
                'module' => $s,
                'sync_class' => ['type' => 'string', 'enum' => (array) config('hub.mobile_sync.classes', [])],
                'cacheable' => $b,
                'conflict_token' => ['type' => 'boolean', 'description' => 'INCREMENTAL: السجلّ يحمل version، والكتابةُ If-Match'],
                'records' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Record']],
                'tombstones' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => $s, 'deleted_at' => $dt]]],
                'has_tombstones' => $b, 'next_cursor' => $sN, 'has_more' => $b, 'sync_version' => $s, 'server_time' => $s,
            ]],
            'ActionList' => ['type' => 'object', 'properties' => [
                'module' => $s, 'id' => $s, 'status' => $sN, 'trashed' => $b, 'version' => ['type' => 'integer', 'nullable' => true],
                'actions' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'action' => $s, 'to' => $sN, 'label' => $s,
                    'requires_approval' => ['type' => 'boolean', 'nullable' => true],
                    'needs' => ['type' => 'array', 'items' => $s, 'nullable' => true],
                ]]],
            ]],
            'Record' => ['type' => 'object', 'description' => 'سجلٌّ ديناميّ — حقولُه من مخطّطِ الوحدة (GET schema/modules) بحقول دور المستخدم',
                'properties' => ['id' => $s, 'version' => ['type' => 'integer', 'readOnly' => true]], 'additionalProperties' => true],
            'RecordWrite' => ['type' => 'object', 'description' => 'حمولةُ كتابةٍ — مفاتيحُ الحقول القابلة للكتابة من مخطّطِ الوحدة',
                'additionalProperties' => true],
        ];
    }

    private static function parameters(): array
    {
        return [
            'page' => ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
            'per' => ['name' => 'per', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25]],
            'per_module' => ['name' => 'per_module', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5, 'default' => 3]],
            'limit' => ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1], 'description' => 'حجمُ الصفحة (سقفٌ صلبٌ حسب النقطة)'],
            'q' => ['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'نصُّ البحث (حرفان فأكثر)'],
            'status' => ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'ترشيحٌ بحالة الوحدة'],
            'sort' => ['name' => 'sort', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'مفتاحُ حقلٍ ظاهر أو created_at/updated_at؛ بادئة «-» للتنازلي'],
            'dir' => ['name' => 'dir', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'desc']],
            'fields' => ['name' => 'fields', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'مفاتيحُ حقولٍ مفصولةٌ بفاصلة — يُعاد id + المطلوب'],
            'trash' => ['name' => 'trash', 'in' => 'query', 'schema' => ['type' => 'boolean'], 'description' => 'السلة — يتطلب صلاحية الحذف'],
            'unread' => ['name' => 'unread', 'in' => 'query', 'schema' => ['type' => 'boolean'], 'description' => 'قصرٌ على غير المقروء'],
            'cursor' => ['name' => 'cursor', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'مؤشّرٌ معتِمٌ للصفحة التالية (لا يوسّع النطاق)'],
            'updated_since' => ['name' => 'updated_since', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date-time'], 'description' => 'للمزامنة التزايُدية'],
            'module_q' => ['name' => 'module', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'مفتاحُ الوحدة (channel/feed/وحدةٌ مسجَّلة)'],
            'record_q' => ['name' => 'record', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'معرّفُ السجل (أو record_id) — يُترك للقنوات العامّة'],
            'Idempotency-Key' => ['name' => 'Idempotency-Key', 'in' => 'header', 'schema' => ['type' => 'string', 'maxLength' => 120],
                'description' => 'إعادةُ الطلب نفسِه بالمفتاح نفسِه تعيد الردَّ المخزَّن (X-Idempotent-Replay: true) — مالكُه جلسةُ الجوال (لا يُعاد ردُّ مستخدمٍ لآخر)'],
            'If-Match' => ['name' => 'If-Match', 'in' => 'header', 'schema' => ['type' => 'string'],
                'description' => 'نسخةُ السجل من ETag — تخالفٌ يردّ 409 VERSION_CONFLICT'],
            'If-None-Match' => ['name' => 'If-None-Match', 'in' => 'header', 'schema' => ['type' => 'string'],
                'description' => 'بصمةٌ سابقة — تطابقٌ يردّ 304 بلا جسم'],
        ];
    }

    private static function errorResponses(): array
    {
        $err = fn (string $d) => ['description' => $d, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];

        return [
            '202' => $err('APPROVAL_REQUIRED — العمليةُ صُفَّت للمعتمدين (details.approval = {module, id})'),
            '401' => $err('UNAUTHENTICATED | MFA_REQUIRED | REFRESH_TOKEN_INVALID | SESSION_REVOKED'),
            '403' => $err('FORBIDDEN | ACCOUNT_RESTRICTED | LOCKDOWN'),
            '404' => $err('RESOURCE_NOT_FOUND'),
            '409' => $err('VERSION_CONFLICT | APPROVAL_REQUIRED | IDEMPOTENCY_IN_PROGRESS | CONFLICT'),
            '413' => $err('PAYLOAD_TOO_LARGE'),
            '415' => $err('نوعُ المحتوى غير مدعومٍ للبثّ (يُنزَّل بدلاً منه)'),
            '422' => $err('VALIDATION_FAILED | IDEMPOTENCY_KEY_REUSED | BUSINESS_RULE_VIOLATION'),
            '423' => $err('LOCKED — الملفُّ مُصابٌ أو الفعلُ مُجمَّد'),
            '426' => $err('APP_UPDATE_REQUIRED'),
            '428' => $err('STEP_UP_REQUIRED (details.purpose/method)'),
            '429' => $err('RATE_LIMITED (راجع Retry-After)'),
            '500' => $err('INTERNAL_ERROR'),
            '503' => $err('MAINTENANCE | LOCKDOWN | SERVICE_UNAVAILABLE'),
        ];
    }

    private static function tags(): array
    {
        $t = [
            'meta' => 'المواصفة والصحّة',
            'auth' => 'المصادقة والجلسات والتصعيد',
            'context' => 'السياق والإقلاع',
            'schema' => 'المخطّط المُنطَّق',
            'crud' => 'سجلّاتُ الوحدات (قراءة/كتابة)',
            'actions' => 'إجراءاتُ السجل (allowlist)',
            'approvals' => 'الاعتمادات',
            'home' => 'مساحةُ العمل',
            'search' => 'البحث',
            'prefs' => 'التفضيلات والمثبّتات',
            'notifications' => 'الإشعارات',
            'comments' => 'التعليقات',
            'dm' => 'الرسائل المباشرة',
            'push' => 'دفعُ الإشعارات',
            'files' => 'الملفّات (رفع/تنزيل مُصادَق)',
            'scanner' => 'الماسح (QR/سيريال)',
            'tracking' => 'التتبّع الميدانيّ (بموافقة)',
            'sync' => 'المزامنة التزايُدية',
            'health' => 'الصحّة',
        ];
        $out = [];
        foreach ($t as $name => $desc) $out[] = ['name' => $name, 'description' => $desc];

        return $out;
    }

    // ══════════════════════════ توثيقُ العقود العرضيّة (x-*) ══════════════════════════

    /** أكوادُ الجوال الأربعة المُضافة فوق العقد الموحّد (لا يُعاد تسميةُ كودٍ قائم) */
    private static function mobileCodes(): array
    {
        $out = [];
        foreach ([Api::MFA_REQUIRED, Api::REFRESH_TOKEN_INVALID, Api::SESSION_REVOKED, Api::APP_UPDATE_REQUIRED] as $c) {
            $out[$c] = Api::CODES[$c] ?? '';
        }

        return $out;
    }

    private static function authFlowDoc(): array
    {
        return [
            'type' => 'first_party_session',
            'independent_of' => '/api/v1 (ApiToken — مفتاحُ التكامل، غيرُ مَمسوس)',
            'access_token' => ['transport' => 'Authorization: Bearer', 'storage' => 'sha256 خادميّاً', 'lifetime' => 'قصير (setting mobile.access_ttl_min)', 'device_bound' => true, 'never_logged' => true],
            'refresh_token' => ['single_use' => true, 'rotation' => true, 'reuse_defense' => 'إعادةُ استعمالِ رمزٍ مُدوَّرٍ ⇒ إبطالُ العائلة + SecurityRadar + تنبيهٌ أمنيّ', 'error' => Api::REFRESH_TOKEN_INVALID],
            'account_gates' => ['نظيرُ ApiAuth/الويب: موقوف/منتهٍ/مقفول/حصرُ عناوين/قفلُ طوارئ — بعد إثبات الاعتماد وحدَه (لا أوراكل تعداد)'],
            'mfa' => ['methods' => ['totp'], 'note' => 'webauthn غيرُ مُعلَنٍ حتى يُبنى دورانُه للجوال (لا قدرةٌ مُختلَقة · F10)', 'code' => Api::MFA_REQUIRED],
            'step_up' => ['bound_to' => 'user+mobile_session+purpose+expiry', 'methods' => ['totp', 'password'], 'code' => Api::STEP_UP_REQUIRED],
            'biometrics' => 'جانبُ التطبيق فقط — الخادمُ لا يستقبل قوالبَ حيويّة قط',
        ];
    }

    /**
     * **عقدُ الرابطِ العميق القانونيّ** — H.3. الوجهةُ `{module,id,action}` (مصدرُها
     * الواحدُ `App\Support\NotificationLink::target`)، وترميزُها في رابطٍ عالميّ.
     */
    private static function deepLinkDoc(): array
    {
        return [
            'canonical' => ['module' => 'string (مفتاحُ وحدةٍ مسجَّلة)', 'id' => 'string (UUID)', 'action' => 'show'],
            'source_of_truth' => 'App\\Support\\NotificationLink::target() — نفسُها التي تصدرها notifications/{id}/target والبحثُ واللوحة',
            'null_semantics' => 'target=null ⇒ لا وجهةَ سجلٍّ (يعود التطبيقُ لقائمة الإشعارات) — نظيرُ الويب',
            'no_hardcoded_screens' => true,
            'universal_link_encoding' => [
                'path_template' => '/m/{module}/{id}',
                'with_action' => '/m/{module}/{id}/{action} (action=show هو الافتراض فيُحذَف)',
                'host' => 'راجع x-not-configured.universal_links (NOT_CONFIGURED حتى يُصدَر النطاق)',
                'well_known' => ['/.well-known/apple-app-site-association', '/.well-known/assetlinks.json'],
            ],
            'emitted_by' => [
                'GET notifications/{id}/target',
                'POST notifications/{id}/read',
                'GET notifications (كلُّ عنصر)',
                'GET search (كلُّ نتيجة {module,id})',
                'GET home (عناصرُ my_work/due/attention/recent/projects)',
                'GET approvals (target للسجل الهدف)',
            ],
        ];
    }

    /**
     * **الإعداداتُ الخارجيّةُ الغائبة — NOT_CONFIGURED صدقاً (لا تُختلَق، لا تُخوّل على
     * حالةِ عميلٍ مُدَّعاة).** H.3 · spec §Version gate/§Push. المصدرُ إعداداتٌ حيّة —
     * ما ليس مضبوطاً يُعلَن غائباً، ولا سرَّ هنا.
     */
    private static function notConfiguredDoc(): array
    {
        $dl = (array) config('hub.mobile.deep_links', []);
        $aasaConfigured = trim((string) data_get($dl, 'apple.team_id')) !== '' && trim((string) data_get($dl, 'apple.bundle_id')) !== '';
        $alConfigured = trim((string) data_get($dl, 'android.package_name')) !== '' && ! empty((array) data_get($dl, 'android.sha256_cert_fingerprints'));

        return [
            'push_fcm' => [
                'status' => \App\Support\PushService::status()['configured'] ? 'CONFIGURED' : 'NOT_CONFIGURED',
                'configured_via' => ['setting: mobile.push_driver=fcm', 'setting: mobile.push_fcm_project_id', 'setting: mobile.push_fcm_access_token'],
                'behavior_when_absent' => 'NullPushProvider — التسليمُ not_configured، لا نجاحٌ مُزيَّف، والإشعارُ الداخليُّ لا يُفقَد',
                'no_secret_exposed' => true,
            ],
            'push_apns' => ['status' => 'NOT_CONFIGURED', 'note' => 'مزوّدُ APNs المباشرُ غيرُ مبنيٍّ — iOS عبر FCM حالياً؛ واجهةٌ محجوزة'],
            'app_attest_ios' => ['status' => 'NOT_CONFIGURED', 'note' => 'App Attest — واجهةٌ فقط؛ لا يُخوَّل على حالةِ عميلٍ مُدَّعاة'],
            'device_check_ios' => ['status' => 'NOT_CONFIGURED', 'note' => 'DeviceCheck — واجهةٌ فقط'],
            'play_integrity_android' => ['status' => 'NOT_CONFIGURED', 'note' => 'Play Integrity — واجهةٌ فقط'],
            'root_jailbreak_posture' => ['status' => 'NOT_CONFIGURED', 'note' => 'إشارةُ العميلِ غيرُ موثوقة — لا تُخوَّل عليها؛ للعرض/الرصدِ لا للتحكّم'],
            'version_gate' => [
                'status' => self::versionGateConfigured() ? 'CONFIGURED' : 'NOT_CONFIGURED',
                'configured_via' => ['setting: mobile.min_version_ios|latest_version_ios', 'setting: mobile.min_version_android|latest_version_android', 'setting: mobile.force_update'],
                'behavior_when_absent' => 'فارغٌ = لا حجب (نسخُ التطوير تمرّ)',
            ],
            'store_urls' => [
                'status' => (self::settingStr('mobile.store_url_ios') || self::settingStr('mobile.store_url_android')) ? 'CONFIGURED' : 'NOT_CONFIGURED',
                'configured_via' => ['setting: mobile.store_url_ios', 'setting: mobile.store_url_android'],
            ],
            'support_url' => ['status' => self::settingStr('mobile.support_url') ? 'CONFIGURED' : 'NOT_CONFIGURED', 'configured_via' => ['setting: mobile.support_url']],
            'universal_links' => [
                'apple_app_site_association' => [
                    'status' => $aasaConfigured ? 'CONFIGURED' : 'NOT_CONFIGURED',
                    'needs' => ['apple.team_id (Apple Team ID)', 'apple.bundle_id (bundle identifier)'],
                    'served_at' => '/.well-known/apple-app-site-association',
                    'behavior_when_absent' => 'يُخدَم AASA صادقٌ يربط 0 تطبيقاً (لا رابطَ عالميٌّ يعمل) + X-Deep-Links-Status: NOT_CONFIGURED',
                ],
                'assetlinks' => [
                    'status' => $alConfigured ? 'CONFIGURED' : 'NOT_CONFIGURED',
                    'needs' => ['android.package_name', 'android.sha256_cert_fingerprints[]'],
                    'served_at' => '/.well-known/assetlinks.json',
                    'behavior_when_absent' => 'يُخدَم [] (لا تطبيقَ مربوط) + X-Deep-Links-Status: NOT_CONFIGURED',
                ],
                'configured_via' => ['config: hub.mobile.deep_links (أو setting: mobile.dl_*)'],
            ],
        ];
    }

    private static function contextHeadersDoc(): array
    {
        return [
            'note' => 'ترويساتُ سياقٍ/تليمتري — **تضييقُ عرضٍ ووسمٌ لا تخويل**؛ لا توسّع صلاحيةً أبداً (تُقاطَع مع المسموح)',
            'X-Lynomia-App-Platform' => 'ios|android — بوّابةُ الإصدار/التليمتري',
            'X-Lynomia-App-Version' => 'إصدارُ التطبيق (semver) — بوّابةُ الإصدار',
            'X-Lynomia-App-Build' => 'رقمُ البناء — تليمتري',
            'X-Lynomia-Installation-Id' => 'مُعرّفُ التنصيب — تليمتري (الحقيقةُ من الجلسة)',
            'X-Request-Id' => 'معرّفُ طلبٍ من العميل (خارجيٌّ — لا يُشتقّ منه تخويل)',
            'X-Lynomia-Company' => 'تضييقُ العرضِ على شركةٍ ⊆ المسموح (تُتجاهَل إن وسّعت)',
            'X-Lynomia-Client' => 'تضييقُ العرضِ على عميلٍ ⊆ المسموح (تُتجاهَل إن وسّعت)',
        ];
    }

    // ══════════════════════════ H · بيانُ القدرات (mobile-capabilities.json) ══════════════════════════

    /**
     * بيانٌ آليٌّ لقدرات السطح — مُشتقٌّ من المساراتِ الحيّة + الإعدادات، فلا ينحرف.
     * لكلِّ مجالٍ مساراتُه (method+path+name)، وتغطيةُ تصنيفِ المزامنة، والأكواد،
     * والإعداداتُ الخارجيّةُ الغائبة. **بلا نسخةِ تطبيقٍ متقلّبة** (يبقى ثابتاً عبر رفعِ
     * النسخة) — مصدرُه الطرق والإعدادات لا `VERSION`.
     */
    public static function capabilities(): array
    {
        $areas = [];
        foreach (self::mobileRoutes() as $route) {
            $name = (string) $route->getName();
            $area = self::areaOf($name, $route->uri());
            foreach ($route->methods() as $verb) {
                $verb = strtoupper($verb);
                if (in_array($verb, ['HEAD', 'OPTIONS'], true)) continue;
                $areas[$area]['endpoints'][] = [
                    'method' => $verb,
                    'path' => '/' . ltrim($route->uri(), '/'),
                    'name' => $name,
                    'auth' => in_array(self::AUTH_MIDDLEWARE, $route->gatherMiddleware(), true) ? 'mobile.session' : 'public',
                ];
            }
        }
        // ترتيبٌ حتميّ داخل كلِّ مجال (لا قرعةَ ترتيبِ سجلٍّ)
        foreach ($areas as $k => &$a) {
            usort($a['endpoints'], fn ($x, $y) => [$x['path'], $x['method']] <=> [$y['path'], $y['method']]);
            $a['count'] = count($a['endpoints']);
        }
        unset($a);
        ksort($areas);

        // تغطيةُ تصنيفِ المزامنة (من hub_sync_class على سجلِّ الوحدات)
        $syncModules = [];
        $coverage = array_fill_keys((array) config('hub.mobile_sync.classes', []), 0);
        foreach (array_keys(hub_modules()) as $mkey) {
            $cls = hub_sync_class($mkey);
            $syncModules[$mkey] = $cls;
            $coverage[$cls] = ($coverage[$cls] ?? 0) + 1;
        }
        ksort($syncModules);

        return [
            '_generator' => 'App\\Support\\MobileOpenApi::capabilities() — مُشتقٌّ من المسارات الحيّة (php artisan tinker / اختبار الانحراف)',
            '_note' => 'بيانٌ آليّ — لا يُحرَّر يدويّاً؛ يُعاد توليدُه من المسارات فلا يصف قدرةً غيرَ مبنيّة',
            'namespace' => '/' . self::PREFIX,
            'mobile_api_version' => (string) config('hub.mobile.api_version', Api::VERSION),
            'schema_version' => \App\Http\Controllers\Api\MobileContextController::schemaVersion(),
            'openapi' => ['live' => '/' . self::PREFIX . '/openapi.json', 'generator' => 'App\\Support\\MobileOpenApi::spec()'],
            'auth' => self::authFlowDoc(),
            'error_codes' => [
                'contract' => 'App\\Support\\Api::CODES (موحَّدٌ مع /api/v1 — لا يُعاد تسميةُ كودٍ قائم)',
                'reused' => array_values(array_diff(array_keys(Api::CODES), array_keys(self::mobileCodes()))),
                'mobile_added' => array_keys(self::mobileCodes()),
            ],
            'concurrency' => ['optimistic_lock' => 'If-Match ⇒ VERSION_CONFLICT (409)', 'version_column' => 'version (HasVersions)', 'etag' => 'على السجل المفرد ونقاط الإقلاع/المخطّط'],
            'idempotency' => [
                'header' => 'Idempotency-Key',
                'owner' => 'mobile_session id (Critic F1 — لا يُعاد ردُّ مستخدمٍ لآخر)',
                'applies_to' => ['create', 'approval decide', 'comment post', 'dm send', 'file complete', 'file attach', 'action run', 'tracking points', 'pin toggle', 'protected-write submit'],
            ],
            'context_headers' => self::contextHeadersDoc(),
            'deep_link' => self::deepLinkDoc(),
            'sync' => [
                'endpoint' => '/' . self::PREFIX . '/sync/{module}',
                'default_class' => (string) config('hub.mobile_sync.default', 'ONLINE_ONLY'),
                'classes' => (array) config('hub.mobile_sync.classes', []),
                'coverage' => $coverage,
                'page' => ['default_limit' => (int) config('hub.mobile.sync.default_limit', 100), 'max_limit' => (int) config('hub.mobile.sync.max_limit', 500)],
                'modules' => $syncModules,
            ],
            'not_configured' => self::notConfiguredDoc(),
            'areas' => $areas,
        ];
    }

    /** المجالُ الذي ينتمي إليه مسارٌ باسمِه (تصنيفٌ حتميّ من الاسم/المسار) */
    private static function areaOf(string $name, string $uri): string
    {
        $map = [
            'mobile.auth.login' => 'auth', 'mobile.auth.mfa_verify' => 'auth', 'mobile.auth.refresh' => 'auth',
            'mobile.auth.logout' => 'auth', 'mobile.auth.logout_all' => 'auth', 'mobile.auth.sessions.index' => 'auth',
            'mobile.auth.sessions.destroy' => 'auth', 'mobile.auth.step_up' => 'auth',
            'mobile.app_config' => 'auth', 'mobile.openapi' => 'meta', 'mobile.health' => 'health',
            'mobile.context' => 'context', 'mobile.bootstrap' => 'context',
            'mobile.schema' => 'schema', 'mobile.schema.modules' => 'schema',
            'mobile.approvals.index' => 'approvals', 'mobile.approvals.show' => 'approvals',
            'mobile.approvals.approve' => 'approvals', 'mobile.approvals.reject' => 'approvals',
            'mobile.home' => 'home', 'mobile.search' => 'search',
            'mobile.prefs.index' => 'prefs', 'mobile.prefs.update' => 'prefs', 'mobile.prefs.pin' => 'prefs',
            'mobile.notifications.index' => 'notifications', 'mobile.notifications.unread' => 'notifications',
            'mobile.notifications.read_all' => 'notifications', 'mobile.notifications.read' => 'notifications',
            'mobile.notifications.target' => 'notifications',
            'mobile.comments.index' => 'comments', 'mobile.comments.store' => 'comments',
            'mobile.dm.threads' => 'dm', 'mobile.dm.messages' => 'dm', 'mobile.dm.send' => 'dm', 'mobile.dm.read' => 'dm',
            'mobile.push.register' => 'push', 'mobile.push.unregister' => 'push',
            'mobile.push.admin.status' => 'push', 'mobile.push.admin.test' => 'push',
            'mobile.files.upload_session' => 'files', 'mobile.files.upload_chunk' => 'files',
            'mobile.files.upload_complete' => 'files', 'mobile.files.attach' => 'files',
            'mobile.files.download' => 'files', 'mobile.files.stream' => 'files',
            'mobile.identity.resolve' => 'scanner',
            'mobile.tracking.start' => 'tracking', 'mobile.tracking.points' => 'tracking', 'mobile.tracking.end' => 'tracking',
            'mobile.sync' => 'sync',
            'mobile.resource.actions' => 'actions', 'mobile.resource.run_action' => 'actions',
            'mobile.resource.index' => 'crud', 'mobile.resource.store' => 'crud', 'mobile.resource.show' => 'crud',
            'mobile.resource.update' => 'crud', 'mobile.resource.patch' => 'crud', 'mobile.resource.destroy' => 'crud',
        ];
        if (isset($map[$name])) return $map[$name];
        // مسارٌ جديدٌ بلا تصنيفٍ صريح — يسقط لمجالٍ مُشتقٍّ من أوّلِ مقطعٍ بعد البادئة (صادقٌ لا مُختلَق)
        $rest = trim(substr($uri, strlen(self::PREFIX)), '/');
        $seg = explode('/', $rest)[0] ?? 'other';

        return $seg !== '' && ! str_starts_with($seg, '{') ? $seg : 'other';
    }

    // ══════════════════════════ مساعِداتٌ داخلية ══════════════════════════

    /** كلُّ المسارات المُسجَّلة تحت البادئة، مرتّبةً حتميّاً (لا قرعةَ سجلّ) */
    private static function mobileRoutes(): array
    {
        $routes = [];
        foreach (RouteFacade::getRoutes() as $route) {
            /** @var IlluminateRoute $route */
            if (str_starts_with($route->uri(), self::PREFIX)) $routes[] = $route;
        }
        usort($routes, fn (IlluminateRoute $a, IlluminateRoute $b) => [$a->uri(), $a->methods()[0]] <=> [$b->uri(), $b->methods()[0]]);

        return $routes;
    }

    private static function versionGateConfigured(): bool
    {
        foreach (['mobile.min_version_ios', 'mobile.latest_version_ios', 'mobile.min_version_android', 'mobile.latest_version_android'] as $k) {
            if (self::settingStr($k)) return true;
        }

        return (bool) setting('mobile.force_update', false);
    }

    private static function settingStr(string $key): bool
    {
        return trim((string) setting($key, '')) !== '';
    }
}
