<?php

namespace App\Support;

use App\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * **عقدُ API الواحد** — أكوادٌ آلية ثابتة وغلافُ ردٍّ موحَّد لكل `/api/*`.
 *
 * كان الخطأ يصل التكاملَ بثلاثة أشكال: `{error}` من وسيط المصادقة، و`{message}`
 * من `abort()`، و`{message, errors}` من التحقق — ولا كودَ آليّاً في أيٍّ منها،
 * فيُضطرّ العميلُ (n8n مثلاً) إلى مطابقة نصٍّ عربيّ ليقرّر أيُعيد المحاولة أم
 * يتوقّف. و٤٠٤ السجل الغائب كان يطبع اسمَ صنف النموذج الداخليّ حرفيّاً.
 *
 * هنا يمرّ كلُّ ردِّ خطأٍ على `/api/*` من نقطةٍ واحدة تُخرج **الشكلَ القديم
 * كاملاً** (فلا يُكسر مستهلكٌ قائم) **وتضيف** فوقه: `code` آليّاً، و`details`
 * مهيكلة عند الحاجة، و`request_id` للمتابعة — والرسالةُ الداخلية لا تُسرَّب.
 *
 * والغلافُ الناجح للقوائم يُبقي `data/total/page/last_page` ويضيف `meta`
 * (الصفحة والحجم والفرز) و`request_id`.
 */
final class Api
{
    public const UNAUTHENTICATED = 'UNAUTHENTICATED';
    public const ACCOUNT_RESTRICTED = 'ACCOUNT_RESTRICTED';
    public const FORBIDDEN = 'FORBIDDEN';
    public const INSUFFICIENT_SCOPE = 'INSUFFICIENT_SCOPE';
    public const RESOURCE_NOT_FOUND = 'RESOURCE_NOT_FOUND';
    public const METHOD_NOT_ALLOWED = 'METHOD_NOT_ALLOWED';
    public const VALIDATION_FAILED = 'VALIDATION_FAILED';
    public const CONFLICT = 'CONFLICT';
    public const VERSION_CONFLICT = 'VERSION_CONFLICT';
    public const APPROVAL_REQUIRED = 'APPROVAL_REQUIRED';
    public const IDEMPOTENCY_IN_PROGRESS = 'IDEMPOTENCY_IN_PROGRESS';
    public const IDEMPOTENCY_KEY_REUSED = 'IDEMPOTENCY_KEY_REUSED';
    public const PAYLOAD_TOO_LARGE = 'PAYLOAD_TOO_LARGE';
    public const LOCKED = 'LOCKED';
    public const STEP_UP_REQUIRED = 'STEP_UP_REQUIRED';
    public const RATE_LIMITED = 'RATE_LIMITED';
    public const BUSINESS_RULE_VIOLATION = 'BUSINESS_RULE_VIOLATION';
    public const INTEGRATION_UNAVAILABLE = 'INTEGRATION_UNAVAILABLE';
    public const MAINTENANCE = 'MAINTENANCE';
    public const LOCKDOWN = 'LOCKDOWN';
    public const SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';
    public const INTERNAL_ERROR = 'INTERNAL_ERROR';

    /** إصدارُ العقد الحاليّ — يُبثّ ترويسةً على كل ردّ */
    public const VERSION = '1';

    /** الأكوادُ المُعلنة ووصفُها — تغذّي التوثيق (OpenAPI) فلا يتقادم */
    public const CODES = [
        self::UNAUTHENTICATED => 'مفتاحٌ مفقود أو غير صالح أو منتهٍ (401)',
        self::ACCOUNT_RESTRICTED => 'الحساب موقوف/مقفل/منتهٍ أو محصورٌ بعناوين شبكة (403)',
        self::FORBIDDEN => 'لا صلاحية على الوحدة أو الفعل (403)',
        self::INSUFFICIENT_SCOPE => 'نطاقُ المفتاح لا يشمل هذه الوحدة/العملية (403)',
        self::RESOURCE_NOT_FOUND => 'وحدةٌ أو سجلٌّ غير موجود أو خارج نطاقك (404)',
        self::METHOD_NOT_ALLOWED => 'الطريقة غير مدعومة على هذا المسار (405)',
        self::VALIDATION_FAILED => 'فشل التحقق — التفاصيل في details/errors (422)',
        self::CONFLICT => 'تعارضٌ عام (409)',
        self::VERSION_CONFLICT => 'نسخةُ السجل التي تحملها قديمة — عدّله آخر (409)',
        self::APPROVAL_REQUIRED => 'العملية محمية بالموافقات — نفّذها من الواجهة (409)',
        self::IDEMPOTENCY_IN_PROGRESS => 'الطلبُ نفسُه قيد المعالجة الآن (409)',
        self::IDEMPOTENCY_KEY_REUSED => 'مفتاح Idempotency أُعيد بطلبٍ مختلف (422)',
        self::PAYLOAD_TOO_LARGE => 'حمولةٌ أكبر من المسموح (413)',
        self::LOCKED => 'الفعل مجمَّدٌ بمفتاح طوارئٍ أمنيّ (423)',
        self::STEP_UP_REQUIRED => 'يتطلب تأكيدَ الهوية (Step-Up) (428)',
        self::RATE_LIMITED => 'تجاوز حدّ المعدّل — راجع Retry-After (429)',
        self::BUSINESS_RULE_VIOLATION => 'قاعدةُ عملٍ تمنع الفعل (422)',
        self::INTEGRATION_UNAVAILABLE => 'خدمةٌ خارجية غير متاحة الآن (503)',
        self::MAINTENANCE => 'وضع الصيانة — الكتابة متوقفة مؤقتاً (503)',
        self::LOCKDOWN => 'قفلُ طوارئ — سطحُ API معلَّق (503)',
        self::SERVICE_UNAVAILABLE => 'الخدمة غير متاحة مؤقتاً (503)',
        self::INTERNAL_ERROR => 'عطلٌ داخليّ سُجّل تلقائياً — أرفق request_id (500)',
    ];

    /** معرّفُ الطلب الحاليّ (يضعه وسيط Observability) — أو null خارج الطلب */
    public static function requestId(): ?string
    {
        try {
            $req = request();
            $rid = $req->attributes->get('request_id');
            if (! is_string($rid) || $rid === '') {
                // ردٌّ مبكّر قبل وسيط Observability (صيانة/قفل): يُولَّد هنا ويُحترَم لاحقاً
                $rid = (string) \Illuminate\Support\Str::uuid();
                $req->attributes->set('request_id', $rid);
            }

            return $rid;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * ردُّ خطأٍ بالغلاف الموحَّد.
     *
     * @param array $legacy مفاتيحُ إضافية للتوافق (مثل `errors` للتحقق، أو `stepup`/`url`)
     */
    public static function error(string $code, int $status, string $message,
                                 ?array $details = null, array $legacy = [], array $headers = []): JsonResponse
    {
        $body = ['error' => $message, 'code' => $code, 'message' => $message];
        if ($details !== null && $details !== []) $body['details'] = $details;
        $body += $legacy;
        $body['request_id'] = self::requestId();

        // الترويسةُ هنا أيضاً: ردٌّ يقع قبل وسيط Observability (٤٠٥ عند التوجيه، صيانة) لا يمرّ به
        return response()->json($body, $status, $headers + ['X-Error-Code' => $code, 'X-Request-Id' => (string) $body['request_id']]);
    }

    /** إلقاءُ خطأ API بكودٍ صريح — نظيرُ `abort()` لكن بكودٍ آليّ */
    public static function abort(string $code, int $status, string $message, array $details = []): never
    {
        throw new ApiException($code, $status, $message, $details);
    }

    /** الكودُ الافتراضيّ لحالة HTTP حين لا يصرّح المُلقي بكود */
    public static function codeFor(int $status): string
    {
        return match ($status) {
            401 => self::UNAUTHENTICATED,
            403 => self::FORBIDDEN,
            404 => self::RESOURCE_NOT_FOUND,
            405 => self::METHOD_NOT_ALLOWED,
            409 => self::CONFLICT,
            413 => self::PAYLOAD_TOO_LARGE,
            422 => self::VALIDATION_FAILED,
            423 => self::LOCKED,
            428 => self::STEP_UP_REQUIRED,
            429 => self::RATE_LIMITED,
            502, 504 => self::INTEGRATION_UNAVAILABLE,
            503 => self::SERVICE_UNAVAILABLE,
            default => $status >= 500 ? self::INTERNAL_ERROR : self::BUSINESS_RULE_VIOLATION,
        };
    }

    /**
     * تصييرُ أيّ استثناءٍ على `/api/*` إلى الغلاف الموحَّد. يُنادى من معالج
     * الاستثناءات في `bootstrap/app.php` — ويُعيد null لغير API فيمضي الافتراضيّ.
     */
    public static function render(\Throwable $e, Request $r): ?JsonResponse
    {
        if (! $r->is('api/*')) return null;

        // ١) التحقق: الشكلُ القديم `{message, errors}` يبقى حرفيّاً + الكود والتفاصيل
        if ($e instanceof \Illuminate\Validation\ValidationException) {
            $errors = $e->errors();
            $first = (string) (collect($errors)->flatten()->first() ?? $e->getMessage());

            return self::error(self::VALIDATION_FAILED, $e->status, $first, $errors, ['errors' => $errors]);
        }

        if ($e instanceof \Illuminate\Auth\AuthenticationException) {
            return self::error(self::UNAUTHENTICATED, 401, 'أرسل المفتاح في ترويسة Authorization: Bearer <token>');
        }

        if ($e instanceof \Illuminate\Http\Exceptions\ThrottleRequestsException) {
            return self::error(self::RATE_LIMITED, 429,
                'تجاوزتَ حدّ المعدّل — أعد المحاولة بعد المدة في ترويسة Retry-After',
                null, [], $e->getHeaders());
        }

        if ($e instanceof \Illuminate\Http\Exceptions\PostTooLargeException) {
            return self::error(self::PAYLOAD_TOO_LARGE, 413, 'الحمولةُ أكبر من المسموح به على هذا الخادم');
        }

        if ($e instanceof ApiException) {
            return self::error($e->errorCode, $e->getStatusCode(), $e->getMessage() ?: self::CODES[$e->errorCode] ?? '',
                $e->details ?: null, [], $e->getHeaders());
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $msg = (string) $e->getMessage();
            $code = self::codeFor($status);
            $details = null;

            // ٤٠٤ عن `findOrFail`: الرسالةُ الافتراضية تطبع اسمَ صنف النموذج والمعرّف —
            // بنيةٌ داخلية لا شأنَ للعميل بها. تُوحَّد على دلالة الويب: غائبٌ أو خارج نطاقك.
            if ($status === 404 && ($e->getPrevious() instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                || str_starts_with($msg, 'No query results'))) {
                $msg = 'السجل غير موجود أو خارج نطاقك';
                $details = ['kind' => 'record'];
            }
            if ($status === 404 && $msg === '') $msg = 'المسار غير موجود';
            if ($status === 405) $msg = 'الطريقة غير مدعومة على هذا المسار';
            if ($status === 403 && str_contains($msg, 'نطاق هذا المفتاح')) $code = self::INSUFFICIENT_SCOPE;
            if ($status === 409 && str_contains($msg, 'محمية بالموافقات')) $code = self::APPROVAL_REQUIRED;
            if ($status >= 500 && $msg === '') $msg = self::CODES[$code];
            if ($msg === '') $msg = self::CODES[$code] ?? ('HTTP ' . $status);

            return self::error($code, $status, $msg, $details, [], $e->getHeaders());
        }

        // ٥) كلُّ ما عدا ذلك عطلٌ داخليّ: رُصد في مركز الأخطاء عبر report()؛ لا تفاصيلَ
        // داخلية للعميل — إلا في وضع التطوير (الصنف والموضع بلا أثر التنفيذ).
        $legacy = [];
        if ((bool) config('app.debug')) {
            $legacy['debug'] = ['exception' => get_class($e), 'message' => $e->getMessage(),
                                'file' => str_replace(base_path() . '/', '', $e->getFile()), 'line' => $e->getLine()];
        }

        return self::error(self::INTERNAL_ERROR, 500,
            'عطلٌ داخليّ سُجّل تلقائياً — أعد المحاولة، وأرفق معرّف الطلب (request_id) عند طلب الدعم',
            null, $legacy);
    }

    /**
     * غلافُ قائمةٍ مرقَّمة: المفاتيحُ القديمة كما هي + `meta` + `request_id`.
     *
     * @param \Illuminate\Contracts\Pagination\LengthAwarePaginator $page
     */
    public static function list($page, $data, array $meta = []): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'total' => $page->total(),
            'page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'meta' => $meta + [
                'page' => $page->currentPage(), 'per' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage(),
                'has_more' => $page->hasMorePages(),
            ],
            'request_id' => self::requestId(),
        ]);
    }

    /**
     * فرزٌ مضبوط: `sort=field` أو `sort=-field` (تنازلي) أو `dir=asc|desc`.
     * يُقبل من حقول الوحدة **الظاهرة** لدور صاحب المفتاح وحدها، ومن الأعمدة
     * الزمنية القياسية — فالفرزُ بعمودٍ محجوب إفشاءٌ بلا عرض. ما خرج عنها يُتجاهَل.
     *
     * @return array{0:string,1:string,2:?string} [عمود, اتجاه, مفتاح الحقل المطبَّق أو null]
     */
    public static function sort(Request $r, array $def, string $module): array
    {
        $raw = trim(hub_str($r->query('sort')));
        $dir = strtolower(trim(hub_str($r->query('dir')))) === 'asc' ? 'asc' : 'desc';
        if ($raw !== '' && $raw[0] === '-') { $dir = 'desc'; $raw = substr($raw, 1); }
        elseif ($raw !== '' && $raw[0] === '+') { $dir = 'asc'; $raw = substr($raw, 1); }

        if ($raw === '') return ['created_at', 'desc', null];
        if (in_array($raw, ['created_at', 'updated_at'], true)) return [$raw, $dir, $raw];

        $f = collect($def['fields'] ?? [])->firstWhere('key', $raw);
        if (! $f || in_array($f['type'] ?? '', ['sec', 'file', 'img', 'tags'], true)) return ['created_at', 'desc', null];
        if (! empty($f['multi'])) return ['created_at', 'desc', null];
        if (hub_field_mode(auth()->user(), $module, (string) $f['key']) === 'hide') return ['created_at', 'desc', null];

        return [(string) $f['col'], $dir, (string) $f['key']];
    }

    /** مرشِّحاتٌ زمنية موحَّدة: created_from/created_to (تاريخ) وupdated_since (لحظة) */
    public static function timeFilters(Request $r, $q): array
    {
        $applied = [];
        foreach (['created_from' => ['created_at', '>=', true], 'created_to' => ['created_at', '<=', true],
                  'updated_since' => ['updated_at', '>=', false]] as $param => [$col, $op, $dateOnly]) {
            $v = trim(hub_str($r->query($param)));
            if ($v === '') continue;
            try {
                $t = \Illuminate\Support\Carbon::parse($v);
            } catch (\Throwable $e) {
                throw \Illuminate\Validation\ValidationException::withMessages([$param => 'تاريخٌ غير صالح']);
            }
            $dateOnly ? $q->whereDate($col, $op, $t->toDateString()) : $q->where($col, $op, $t);
            $applied[$param] = $v;
        }

        return $applied;
    }

    /** وحدةُ المقاييس التي تحمل عدّادات الـAPI (السجلُّ = معرّف المفتاح) */
    public const USAGE_MODULE = 'api_tokens';

    /**
     * عدّاداتُ اليوم لمفتاح: `requests` و`errors` (≥400) و`ms` (مجموع الزمن) — زيادةٌ ذرّية على
     * نقطة اليوم، وإدراجٌ عند الغياب. لا يرمي أبداً: التحليلُ إثراءٌ لا شرطٌ للردّ.
     */
    public static function countUsage(string $tokenId, int $status, int $ms): void
    {
        try {
            if (! hub_has_col('metric_points', 'value')) return;
            $day = now()->startOfDay();
            $inc = ['requests' => 1, 'ms' => max(0, $ms)];
            if ($status >= 400) $inc['errors'] = 1;
            foreach ($inc as $metric => $by) {
                $hit = \Illuminate\Support\Facades\DB::table('metric_points')
                    ->where('module', self::USAGE_MODULE)->where('record_id', $tokenId)->where('metric', $metric)->where('at', $day)
                    ->increment('value', $by, ['updated_at' => now()]);
                if (! $hit) {
                    \Illuminate\Support\Facades\DB::table('metric_points')->insertOrIgnore([
                        'id' => (string) \Illuminate\Support\Str::uuid(), 'module' => self::USAGE_MODULE, 'record_id' => $tokenId,
                        'metric' => $metric, 'value' => $by, 'at' => $day, 'source' => 'api', 'meta' => null,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // صمت: التحليلُ لا يكسر الطلب
        }
    }

    /**
     * استخدامُ الـAPI خلال أيام: لكل مفتاح (طلبات/أخطاء/متوسط زمن) + المجاميع.
     *
     * @return array{days:int,total:array{requests:int,errors:int,avg_ms:?int,error_rate:?float},tokens:array<int,array>}
     */
    public static function usage(int $days = 7): array
    {
        $out = ['days' => $days, 'total' => ['requests' => 0, 'errors' => 0, 'avg_ms' => null, 'error_rate' => null], 'tokens' => []];
        try {
            if (! hub_has_col('metric_points', 'value')) return $out;
            $rows = \Illuminate\Support\Facades\DB::table('metric_points')
                ->where('module', self::USAGE_MODULE)->where('at', '>=', now()->subDays($days)->startOfDay())
                ->selectRaw('record_id, metric, SUM(value) v')->groupBy('record_id', 'metric')->get();
            $by = [];
            foreach ($rows as $r) $by[$r->record_id][$r->metric] = (float) $r->v;
            $names = \Illuminate\Support\Facades\DB::table('api_tokens')->leftJoin('users', 'users.id', '=', 'api_tokens.user_id')
                ->whereIn('api_tokens.id', array_keys($by))->get(['api_tokens.id', 'api_tokens.name', 'users.name as uname'])->keyBy('id');
            foreach ($by as $tid => $m) {
                $req = (int) ($m['requests'] ?? 0); $err = (int) ($m['errors'] ?? 0); $ms = (float) ($m['ms'] ?? 0);
                $out['tokens'][] = ['token_id' => $tid, 'name' => $names[$tid]->name ?? 'مفتاح محذوف', 'user' => $names[$tid]->uname ?? null,
                    'requests' => $req, 'errors' => $err, 'avg_ms' => $req ? (int) round($ms / $req) : null,
                    'error_rate' => $req ? round($err * 100 / $req, 1) : null];
                $out['total']['requests'] += $req; $out['total']['errors'] += $err; $out['total']['_ms'] = ($out['total']['_ms'] ?? 0) + $ms;
            }
            usort($out['tokens'], fn ($a, $b) => $b['requests'] <=> $a['requests']);
            $tr = $out['total']['requests'];
            $out['total']['avg_ms'] = $tr ? (int) round(($out['total']['_ms'] ?? 0) / $tr) : null;
            $out['total']['error_rate'] = $tr ? round($out['total']['errors'] * 100 / $tr, 1) : null;
            unset($out['total']['_ms']);
        } catch (\Throwable $e) {
        }

        return $out;
    }

    /**
     * شرطُ النسخة قبل التعديل: `If-Match: "3"` أو `_version` في الجسم. غيابُهما
     * لا يمنع (عميلٌ قديم)، وتخالفُهما مع نسخة السجل = 409 VERSION_CONFLICT.
     */
    public static function assertVersion(Request $r, $m): void
    {
        $seen = trim((string) $r->header('If-Match', ''));
        $seen = trim($seen, '"');
        if ($seen === '') $seen = trim((string) $r->input('_version', ''));
        if ($seen === '' || $seen === '*') return;
        if (! ctype_digit($seen)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['_version' => 'النسخة يجب أن تكون رقماً']);
        }
        $cur = (int) ($m->version ?? 0);
        if ((int) $seen !== $cur) {
            self::abort(self::VERSION_CONFLICT, 409,
                'عدّل شخصٌ آخر هذا السجل بعد النسخة التي تحملها — اقرأه من جديد وراجع تغييرك',
                ['current_version' => $cur, 'your_version' => (int) $seen]);
        }
    }
}
