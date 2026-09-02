<?php

namespace App\Support;

/**
 * مولِّدُ مواصفة OpenAPI 3.1 لـ`/api/v1` — **من سجل الوحدات لا من اليد**.
 *
 * `docs/API.md` كان يُكتب يدوياً فيتقادم مع كل وحدةٍ جديدة أو حقلٍ يُضاف. هنا
 * تُشتقّ المسارات والمخطّطات من `config/hub.php` نفسِه الذي يقود المتحكّم، فما
 * تقوله المواصفة هو ما يفعله النظام. تُقدَّم حيّةً على `GET /api/v1/openapi.json`
 * (مقصورةً على ما يراه صاحبُ المفتاح) وتُكتب بـ`hub:openapi` إلى `docs/openapi.json`.
 */
class OpenApi
{
    /**
     * @param string[] $modules مفاتيحُ الوحدات المشمولة (فارغ = الكل عدا users)
     * @param \App\Models\User|null $user لقناع الحقول بدوره؛ null = كل الحقول
     */
    public static function spec(array $modules = [], $user = null): array
    {
        $all = hub_modules();
        if (! $modules) $modules = array_values(array_filter(array_keys($all), fn ($k) => $k !== 'users'));

        $paths = self::staticPaths();
        $schemas = self::baseSchemas();

        foreach ($modules as $key) {
            $def = $all[$key] ?? null;
            if (! $def) continue;
            $def['key'] = $key;
            $fields = $user ? hub_visible_fields($user, $key, $def) : ($def['fields'] ?? []);
            $name = self::schemaName($key);
            $schemas[$name] = self::recordSchema($def, $fields, false);
            $schemas[$name . 'Write'] = self::recordSchema($def, $fields, true);
            $paths += self::modulePaths($key, $def, $fields, $name);
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => (string) setting('app.name', config('app.name')) . ' — REST API',
                'version' => (string) config('hub.version'),
                'description' => "واجهة `/api/v1`: كل وحدات النظام بنفس صلاحيات صاحب المفتاح ونطاقه.\n"
                    . "مفتاح Bearer من «حسابي ← مفاتيح API». حدّ المعدل ١٢٠/دقيقة لكل مفتاح و٣٠٠/دقيقة لكل عنوان.\n"
                    . "كل ردٍّ يحمل `X-Request-Id` و`X-API-Version`، وكل خطأٍ يحمل `code` آلياً و`request_id`.",
                'x-hub-version' => (string) config('hub.version'),
            ],
            'servers' => [['url' => rtrim((string) config('app.url'), '/')]],
            'security' => [['bearerAuth' => []]],
            'tags' => array_merge(
                [['name' => 'core', 'description' => 'الهوية والوحدات والمواصفة'],
                 ['name' => 'reports', 'description' => 'التقارير'],
                 ['name' => 'metrics', 'description' => 'المقاييس الزمنية'],
                 ['name' => 'field', 'description' => 'التتبّع الميدانيّ (الجوال)']],
                array_map(fn ($k) => ['name' => $k, 'description' => (string) ($all[$k]['label'] ?? $k)], $modules)
            ),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'lyn_…']],
                'schemas' => $schemas,
                'parameters' => self::parameters(),
                'headers' => [
                    'X-Request-Id' => ['description' => 'معرّف الطلب — أرفقه عند طلب الدعم', 'schema' => ['type' => 'string']],
                    'X-API-Version' => ['description' => 'إصدار عقد API', 'schema' => ['type' => 'string']],
                    'ETag' => ['description' => 'نسخة السجل بين علامتي اقتباس — للقفل التفاؤليّ عبر If-Match', 'schema' => ['type' => 'string']],
                    'X-RateLimit-Limit' => ['schema' => ['type' => 'integer']],
                    'X-RateLimit-Remaining' => ['schema' => ['type' => 'integer']],
                    'Retry-After' => ['schema' => ['type' => 'integer']],
                ],
                'responses' => self::errorResponses(),
            ],
            'x-error-codes' => Api::CODES,
            'x-deprecations' => [],
        ];
    }

    protected static function schemaName(string $key): string
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $key)));
    }

    /** مخطّطُ سجل وحدة من حقولها — للقراءة (مع id والنسخة) أو للكتابة (بلا الملفات) */
    protected static function recordSchema(array $def, array $fields, bool $write): array
    {
        $props = $write ? [] : [
            'id' => ['type' => 'string', 'format' => 'uuid', 'readOnly' => true],
            'version' => ['type' => 'integer', 'readOnly' => true, 'description' => 'نسخة السجل — تُرسَل في If-Match'],
        ];
        $required = [];
        foreach ($fields as $f) {
            $t = (string) ($f['type'] ?? 'text');
            if ($write && in_array($t, ['file', 'img'], true)) continue;   // الملفات لا تُرفع عبر JSON
            $p = match ($t) {
                'num', 'big' => ['type' => 'number'],
                'bool' => ['type' => 'boolean'],
                'date' => ['type' => 'string', 'format' => 'date'],
                'dt' => ['type' => 'string', 'format' => 'date-time'],
                'url' => ['type' => 'string', 'format' => 'uri'],
                'sec' => ['type' => 'string', 'writeOnly' => true, 'description' => 'سرّ — لا يُعاد إلا لمن يملك علم الأسرار'],
                'file', 'img' => ['type' => 'string', 'readOnly' => true, 'description' => 'مسار الملف — يُرفع من الواجهة'],
                'ref' => empty($f['multi'])
                    ? ['type' => 'string', 'format' => 'uuid', 'x-ref' => (string) ($f['ref'] ?? '')]
                    : ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid'], 'x-ref' => (string) ($f['ref'] ?? '')],
                'tags' => ['type' => 'string', 'description' => 'وسوم مفصولة بفاصلة'],
                default => ['type' => 'string'],
            };
            if ($t === 'sel' && ! empty($f['options'])) $p['enum'] = array_values((array) $f['options']);
            $p['title'] = (string) ($f['label'] ?? $f['key']);
            // الحقلُ يُخاطَب بمفتاحه لا بعمود القاعدة — وهذا ما يقبله المتحكّم
            $props[(string) $f['key']] = ['nullable' => true] + $p;
            if ($write && ! empty($f['required']) && $t !== 'sec') $required[] = (string) $f['key'];
        }
        if (! $write) {
            $props['created_at'] = ['type' => 'string', 'format' => 'date-time', 'readOnly' => true];
            $props['updated_at'] = ['type' => 'string', 'format' => 'date-time', 'readOnly' => true, 'nullable' => true];
        }
        $out = ['type' => 'object', 'title' => (string) ($def['label'] ?? $def['key']), 'properties' => $props];
        if ($write && $required) $out['required'] = $required;

        return $out;
    }

    protected static function baseSchemas(): array
    {
        return [
            'Error' => [
                'type' => 'object',
                'required' => ['error', 'code', 'message'],
                'properties' => [
                    'error' => ['type' => 'string', 'description' => 'الرسالة (مفتاح التوافق القديم)'],
                    'code' => ['type' => 'string', 'enum' => array_keys(Api::CODES)],
                    'message' => ['type' => 'string'],
                    'details' => ['type' => 'object', 'additionalProperties' => true],
                    'errors' => ['type' => 'object', 'description' => 'أخطاء التحقق بالحقل (422)', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                    'request_id' => ['type' => 'string', 'nullable' => true],
                ],
            ],
            'ListMeta' => [
                'type' => 'object',
                'properties' => [
                    'page' => ['type' => 'integer'], 'per' => ['type' => 'integer'], 'total' => ['type' => 'integer'],
                    'last_page' => ['type' => 'integer'], 'has_more' => ['type' => 'boolean'],
                    'sort' => ['type' => 'string'], 'dir' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                    'trash' => ['type' => 'boolean'], 'filters' => ['type' => 'object', 'additionalProperties' => true],
                ],
            ],
            'Me' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'], 'name' => ['type' => 'string'],
                'email' => ['type' => 'string'], 'role' => ['type' => 'string', 'nullable' => true], 'is_owner' => ['type' => 'boolean'],
            ]],
            'MetricPoint' => ['type' => 'object', 'required' => ['module', 'record_id', 'metric', 'value'], 'properties' => [
                'module' => ['type' => 'string'], 'record_id' => ['type' => 'string', 'format' => 'uuid'],
                'metric' => ['type' => 'string', 'maxLength' => 40], 'value' => ['type' => 'number'],
                'at' => ['type' => 'string', 'format' => 'date-time'], 'source' => ['type' => 'string', 'maxLength' => 24],
                'meta' => ['type' => 'object', 'additionalProperties' => true],
            ]],
        ];
    }

    protected static function parameters(): array
    {
        return [
            'module' => ['name' => 'module', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'مفتاح الوحدة من GET /modules'],
            'id' => ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']],
            'q' => ['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'بحث نصّي'],
            'status' => ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'ترشيح بحالة الوحدة'],
            'page' => ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
            'per' => ['name' => 'per', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25]],
            'sort' => ['name' => 'sort', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'مفتاح حقل ظاهر، أو created_at/updated_at؛ بادئة «-» للتنازلي. ما خرج عن القائمة البيضاء يُتجاهَل'],
            'dir' => ['name' => 'dir', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'desc']],
            'fields' => ['name' => 'fields', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'مفاتيح حقول مفصولة بفاصلة — يُعاد id + المطلوب فقط'],
            'f' => ['name' => 'f', 'in' => 'query', 'style' => 'deepObject', 'explode' => true, 'schema' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']], 'description' => 'ترشيح بمرجع: f[clientId]=<uuid> (وcompany_id/project_id الضمنيان)'],
            'fl' => ['name' => 'fl', 'in' => 'query', 'style' => 'deepObject', 'explode' => true, 'schema' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['f' => ['type' => 'string'], 'o' => ['type' => 'string', 'enum' => ['has', 'eq', 'neq', 'gt', 'lt', 'before', 'after', 'empty', 'nempty']], 'v' => ['type' => 'string']]]], 'description' => 'مرشِّحات متقدّمة (حتى ١٠): fl[0][f]=amount&fl[0][o]=gt&fl[0][v]=1000'],
            'created_from' => ['name' => 'created_from', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date']],
            'created_to' => ['name' => 'created_to', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date']],
            'updated_since' => ['name' => 'updated_since', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date-time'], 'description' => 'للمزامنة التزايدية'],
            'trash' => ['name' => 'trash', 'in' => 'query', 'schema' => ['type' => 'boolean'], 'description' => 'السلة — يتطلب صلاحية الحذف'],
            'Idempotency-Key' => ['name' => 'Idempotency-Key', 'in' => 'header', 'schema' => ['type' => 'string', 'maxLength' => 120], 'description' => 'إعادة الطلب نفسه خلال يومين تعيد الرد المخزَّن (X-Idempotent-Replay: true)'],
            'If-Match' => ['name' => 'If-Match', 'in' => 'header', 'schema' => ['type' => 'string'], 'description' => 'نسخة السجل من ETag — تخالفٌ يردّ 409 VERSION_CONFLICT'],
            'X-Change-Reason' => ['name' => 'X-Change-Reason', 'in' => 'header', 'schema' => ['type' => 'string'], 'description' => 'سبب التعديل — يُكتب في سجل التدقيق'],
        ];
    }

    protected static function errorResponses(): array
    {
        $err = fn (string $d) => ['description' => $d, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];

        return [
            '401' => $err('UNAUTHENTICATED'), '403' => $err('FORBIDDEN | INSUFFICIENT_SCOPE | ACCOUNT_RESTRICTED'),
            '404' => $err('RESOURCE_NOT_FOUND'), '409' => $err('VERSION_CONFLICT | APPROVAL_REQUIRED | IDEMPOTENCY_IN_PROGRESS | CONFLICT'),
            '422' => $err('VALIDATION_FAILED | IDEMPOTENCY_KEY_REUSED | BUSINESS_RULE_VIOLATION'),
            '423' => $err('LOCKED'), '428' => $err('STEP_UP_REQUIRED'), '429' => $err('RATE_LIMITED'),
            '500' => $err('INTERNAL_ERROR'), '503' => $err('MAINTENANCE | LOCKDOWN | SERVICE_UNAVAILABLE | INTEGRATION_UNAVAILABLE'),
        ];
    }

    protected static function refs(array $codes): array
    {
        $out = [];
        foreach ($codes as $c) $out[(string) $c] = ['$ref' => '#/components/responses/' . $c];

        return $out;
    }

    protected static function ok(string $desc, array $schema): array
    {
        return ['description' => $desc, 'headers' => ['X-Request-Id' => ['$ref' => '#/components/headers/X-Request-Id']],
                'content' => ['application/json' => ['schema' => $schema]]];
    }

    protected static function modulePaths(string $key, array $def, array $fields, string $name): array
    {
        $p = fn (string $n) => ['$ref' => '#/components/parameters/' . $n];
        $ref = ['$ref' => '#/components/schemas/' . $name];
        $write = ['$ref' => '#/components/schemas/' . $name . 'Write'];
        $label = (string) ($def['label'] ?? $key);
        $listParams = array_map($p, ['q', 'status', 'page', 'per', 'sort', 'dir', 'fields', 'f', 'fl', 'created_from', 'created_to', 'updated_since', 'trash']);
        $one = fn (string $d) => self::ok($d, ['type' => 'object', 'properties' => ['data' => $ref, 'request_id' => ['type' => 'string', 'nullable' => true]]]);

        return [
            "/api/v1/{$key}" => [
                'get' => ['tags' => [$key], 'summary' => "قائمة {$label}", 'operationId' => "list_{$key}", 'parameters' => $listParams,
                    'responses' => ['200' => self::ok('قائمة مرقَّمة', ['type' => 'object', 'properties' => [
                        'data' => ['type' => 'array', 'items' => $ref], 'total' => ['type' => 'integer'], 'page' => ['type' => 'integer'],
                        'last_page' => ['type' => 'integer'], 'meta' => ['$ref' => '#/components/schemas/ListMeta'], 'request_id' => ['type' => 'string', 'nullable' => true],
                    ]])] + self::refs([401, 403, 404, 422, 429, 503])],
                'post' => ['tags' => [$key], 'summary' => "إنشاء {$label}", 'operationId' => "create_{$key}",
                    'parameters' => [$p('Idempotency-Key'), $p('X-Change-Reason')],
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $write]]],
                    'responses' => ['201' => $one('أُنشئ')] + self::refs([401, 403, 404, 409, 422, 429, 503])],
            ],
            "/api/v1/{$key}/{id}" => [
                'parameters' => [$p('id')],
                'get' => ['tags' => [$key], 'summary' => "سجل {$label}", 'operationId' => "show_{$key}", 'parameters' => [$p('fields')],
                    'responses' => ['200' => $one('السجل') + ['headers' => ['ETag' => ['$ref' => '#/components/headers/ETag']]]] + self::refs([401, 403, 404, 429])],
                'put' => ['tags' => [$key], 'summary' => "استبدال {$label} كاملاً (الحقل الغائب يُفرَّغ)", 'operationId' => "replace_{$key}",
                    'parameters' => [$p('If-Match'), $p('X-Change-Reason')],
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $write]]],
                    'responses' => ['200' => $one('حُفظ')] + self::refs([401, 403, 404, 409, 422, 429])],
                'patch' => ['tags' => [$key], 'summary' => "تعديل {$label} جزئياً (الحقول المُرسَلة فقط)", 'operationId' => "update_{$key}",
                    'parameters' => [$p('If-Match'), $p('X-Change-Reason')],
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $write]]],
                    'responses' => ['200' => $one('حُفظ')] + self::refs([401, 403, 404, 409, 422, 429])],
                'delete' => ['tags' => [$key], 'summary' => "نقل {$label} إلى السلة", 'operationId' => "delete_{$key}",
                    'responses' => ['200' => self::ok('نُقل للسلة', ['type' => 'object', 'properties' => ['deleted' => ['type' => 'boolean']]])] + self::refs([401, 403, 404, 409, 429])],
            ],
        ];
    }

    protected static function staticPaths(): array
    {
        $p = fn (string $n) => ['$ref' => '#/components/parameters/' . $n];
        $obj = fn (array $props) => ['type' => 'object', 'properties' => $props];

        return [
            '/api/v1/me' => ['get' => ['tags' => ['core'], 'summary' => 'هوية صاحب المفتاح', 'operationId' => 'me',
                'responses' => ['200' => self::ok('الهوية', ['$ref' => '#/components/schemas/Me'])] + self::refs([401, 403, 429, 503])]],
            '/api/v1/modules' => ['get' => ['tags' => ['core'], 'summary' => 'الوحدات المتاحة لهذا المفتاح بحقولها وصلاحياتها', 'operationId' => 'modules',
                'responses' => ['200' => self::ok('الوحدات', $obj(['modules' => ['type' => 'array', 'items' => $obj([
                    'key' => ['type' => 'string'], 'label' => ['type' => 'string'], 'table' => ['type' => 'string'],
                    'can' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['v', 'a', 'e', 'd']]],
                    'fields' => ['type' => 'array', 'items' => $obj(['key' => ['type' => 'string'], 'label' => ['type' => 'string'], 'type' => ['type' => 'string'], 'required' => ['type' => 'boolean'], 'ref' => ['type' => 'string', 'nullable' => true]])],
                ])]]))] + self::refs([401, 429])]],
            '/api/v1/openapi.json' => ['get' => ['tags' => ['core'], 'summary' => 'هذه المواصفة — مقصورةً على ما يراه المفتاح', 'operationId' => 'openapi',
                'responses' => ['200' => ['description' => 'OpenAPI 3.1', 'content' => ['application/json' => ['schema' => ['type' => 'object']]]]] + self::refs([401, 429])]],
            '/api/v1/reports/progress/{projectId}' => ['get' => ['tags' => ['reports'], 'summary' => 'نسبة إنجاز مشروع (يتطلب projects:v)', 'operationId' => 'report_progress',
                'parameters' => [['name' => 'projectId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']]],
                'responses' => ['200' => self::ok('التقدّم', ['type' => 'object', 'additionalProperties' => true])] + self::refs([401, 403, 404, 429])]],
            '/api/v1/reports/health' => ['get' => ['tags' => ['reports'], 'summary' => 'صحة الشركة — للمالكين (يتطلب reports:v)', 'operationId' => 'report_health',
                'responses' => ['200' => self::ok('الصحة', ['type' => 'object', 'additionalProperties' => true])] + self::refs([401, 403, 429])]],
            '/api/v1/metrics' => ['post' => ['tags' => ['metrics'], 'summary' => 'استقبال دفعة مقاييس زمنية (حتى ٥٠٠ نقطة) — بصلاحية تعديل الوحدة ونطاق الرؤية', 'operationId' => 'metrics_ingest',
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['oneOf' => [
                    $obj(['points' => ['type' => 'array', 'maxItems' => 500, 'items' => ['$ref' => '#/components/schemas/MetricPoint']]]),
                    ['$ref' => '#/components/schemas/MetricPoint'],
                ]]]]],
                'responses' => ['200' => self::ok('حُفظت', $obj(['saved' => ['type' => 'integer'], 'at' => ['type' => 'string', 'format' => 'date-time']]))] + self::refs([401, 403, 422, 429])]],
            '/api/v1/metrics/{module}/{id}' => ['get' => ['tags' => ['metrics'], 'summary' => 'السلسلة الزمنية لمقياسٍ على سجل (أو أسماء المقاييس إن غاب metric)', 'operationId' => 'metrics_show',
                'parameters' => [$p('module'), $p('id'),
                    ['name' => 'metric', 'in' => 'query', 'schema' => ['type' => 'string']],
                    ['name' => 'days', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 730, 'default' => 90]]],
                'responses' => ['200' => self::ok('السلسلة', ['type' => 'object', 'additionalProperties' => true])] + self::refs([401, 403, 404, 429])]],
            '/api/v1/identity/resolve/{q}' => ['get' => ['tags' => ['core'], 'summary' => 'المحلّل الموحّد: كود عهدة/منتج، باركود عالمي، سيريال', 'operationId' => 'identity_resolve',
                'parameters' => [['name' => 'q', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                'responses' => ['200' => self::ok('النتيجة', $obj(['type' => ['type' => 'string', 'enum' => ['asset', 'product', 'stock', 'none']], 'module' => ['type' => 'string'], 'id' => ['type' => 'string'], 'code' => ['type' => 'string'], 'name' => ['type' => 'string'], 'gtin' => ['type' => 'boolean']]))] + self::refs([401, 403, 429])]],
            '/api/v1/track/start' => ['post' => ['tags' => ['field'], 'summary' => 'بدء جلسة تتبّع ميدانيّ بموافقة صريحة', 'operationId' => 'track_start',
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $obj(['consent' => ['type' => 'boolean'], 'field_day' => ['type' => 'string', 'format' => 'date']])]]],
                'responses' => ['201' => self::ok('بدأت', $obj(['session' => ['type' => 'string'], 'status' => ['type' => 'string'], 'started_at' => ['type' => 'string']]))] + self::refs([401, 403, 422, 429])]],
            '/api/v1/track/{session}/points' => ['post' => ['tags' => ['field'], 'summary' => 'استيعاب دفعة نقاط للجلسة النشطة', 'operationId' => 'track_ingest',
                'parameters' => [['name' => 'session', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $obj(['points' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]]])]]],
                'responses' => ['200' => self::ok('استُوعبت', ['type' => 'object', 'additionalProperties' => true])] + self::refs([401, 403, 404, 422, 429])]],
            '/api/v1/track/{session}/end' => ['post' => ['tags' => ['field'], 'summary' => 'إنهاء الجلسة وحساب المسافة', 'operationId' => 'track_end',
                'parameters' => [['name' => 'session', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                'responses' => ['200' => self::ok('أُنهيت', ['type' => 'object', 'additionalProperties' => true])] + self::refs([401, 403, 404, 429])]],
        ];
    }
}
