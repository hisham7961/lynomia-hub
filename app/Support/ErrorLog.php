<?php

namespace App\Support;

use App\Models\ErrorEvent;

/** مسجل الأخطاء المجمّع — لا يرمي أبداً (فشل التسجيل لا يفاقم الخطأ الأصلي) */
class ErrorLog
{
    /** أقصى إشعارٍ لكل مسؤولٍ في نافذة الانفجار — ونشرةٌ سيئة لا تُغرق صندوقاً */
    public const NOTIFY_BURST_CAP = 8;
    public const NOTIFY_BURST_MIN = 15;

    /** حارسُ التكرار: إشعارٌ يفشل فيُلتقط خطؤه فيُشعر… لا حلقة بعد اليوم */
    protected static bool $inNotify = false;

    /**
     * طمسُ بيانات الاعتماد في المسارات العامة (v2.319): `/hook/<48 محرفاً>`
     * و`/sign/<رمز>` و`/verify/<رمز>` — كلٌّ منها بيانُ الاعتماد **الوحيد**
     * لنقطةٍ غير موقّعة. وكانت تُخزَّن في `error_events.url` و`message` وتُرسَل
     * في نصّ الإشعار لكل المراقبين، ومنهم من يُردّ ٤٠٣ عن شاشة النقاط نفسها.
     */
    public static function redact(string $s): string
    {
        return (string) preg_replace('~/(hook|sign|verify)/[^/?\s&]{8,}~i', '/$1/{رمز}', $s);
    }

    /**
     * @param array{category?:string,severity?:string} $ctx تصنيفٌ صريح؛ وإلا يُشتقّ من نوع الالتقاط
     */
    public static function capture(string $kind, string $message, ?string $file = null, ?int $line = null,
                                   ?string $trace = null, array $ctx = []): void
    {
        try {
            $message = mb_substr(self::redact($message), 0, 490);
            // البصمةُ على الرسالة **المعمَّمة** (بلا معرّفات متبدّلة) فيتجمّع الخطأُ الواحد
            // بمئة معرّف في صفٍّ واحد — والرسالةُ المخزَّنة تبقى كما وقعت أوّلَ مرة
            $hash = hash('sha256', $kind . '|' . ErrorTaxonomy::fingerprintOf($message) . '|' . $file . '|' . $line);

            $req = self::httpRequest();

            // زيادة ذرّية أولاً: فحص-ثم-إدراج كان يسابق القيد الفريد على hash
            // فيضيع عدّ التكرارات المتزامنة — التحديث المشروط لا يسابق أحداً
            if (self::bump($hash, $req)) return;

            [$category, $severity] = ErrorTaxonomy::forKind($kind);
            $category = $ctx['category'] ?? $category;
            $severity = $ctx['severity'] ?? $severity;

            try {
                $row = [
                    'hash' => $hash, 'kind' => $kind, 'message' => $message,
                    'file' => $file ? mb_substr($file, 0, 290) : null, 'line' => $line,
                    'url' => $req ? mb_substr(self::redact($req->fullUrl()), 0, 390) : null,
                    'method' => $req?->method(),
                    'user_id' => auth()->id(),
                    'request_id' => $req?->attributes->get('request_id'),
                    'trace' => $trace ? mb_substr(self::redact($trace), 0, 12000) : null,
                    'first_seen' => now(), 'last_seen' => now(),
                ];
                // أعمدةُ الإثراء (v2.399) — تُكتب حين تكون الهجرةُ قد طُبِّقت فلا يسقط الالتقاطُ قبلها
                if (hub_has_col('error_events', 'category')) {
                    $row += [
                        'category' => $category, 'severity' => $severity,
                        'release' => mb_substr((string) config('hub.version'), 0, 20),
                        'env' => mb_substr((string) config('app.env'), 0, 16),
                        'route' => $req ? mb_substr((string) ($req->route()?->getName() ?: self::routePattern($req)), 0, 160) : null,
                        'users' => auth()->id() ? 1 : 0,
                    ];
                    if (auth()->id()) $row['meta'] = ['users' => [auth()->id()]];
                }
                ErrorEvent::create($row);

                // بصمةٌ جديدة = خبرٌ جديد. والتكرارُ يُزاد عدّادُه في bump بلا تنبيه.
                self::tell($message, $req);
            } catch (\Illuminate\Database\QueryException $e) {
                self::bump($hash, $req);    // خسرنا سباق الإدراج — الصف موجود الآن فزده
            }
        } catch (\Throwable $e) {
            // صمت تام — التسجيل لا يكسر شيئاً
        }
    }

    /**
     * طلبُ HTTP الحاليّ أو null في الطرفية/المجدول. `runningInConsole()` وحده كان
     * الفيصل — وهو صادقٌ أيضاً تحت phpunit فتُلتقط أخطاءُ الطلبات الاختبارية بلا
     * رابطٍ ولا معرّف، وما لا يُختبَر لا يُثبَت. العلامةُ الأصدق: طلبٌ له مسارٌ أو معرّف.
     */
    protected static function httpRequest(): ?\Illuminate\Http\Request
    {
        try {
            $req = request();
            if (! $req instanceof \Illuminate\Http\Request) return null;
            if (app()->runningInConsole() && $req->route() === null && ! $req->attributes->has('request_id')) return null;

            return $req;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** زيادة العدّاد ذرّياً إن وُجد الصف — يعيد false إن لم يوجد */
    protected static function bump(string $hash, $req): bool
    {
        $hit = ErrorEvent::where('hash', $hash)->update([
            'count' => \Illuminate\Support\Facades\DB::raw('count + 1'),
            'last_seen' => now(),
            'url' => $req ? mb_substr(self::redact($req->fullUrl()), 0, 390) : \Illuminate\Support\Facades\DB::raw('url'),
            'user_id' => auth()->id() ?? \Illuminate\Support\Facades\DB::raw('user_id'),
        ]);
        if ($hit) {
            // خطأ محلول عاد للظهور → يعود «جديد» ليلفت النظر، **ويُنبَّه به**:
            // عودةُ عطلٍ حُسب مُغلقاً أهمُّ من ظهوره الأول، وكانت تمرّ صامتة
            $back = ErrorEvent::where('hash', $hash)->where('status', 'محلول')->first();
            if ($back) {
                ErrorEvent::whereKey($back->id)->update(['status' => 'جديد']);
                self::tell('عاد بعد أن حُسب محلولاً — ' . $back->message, $req);
            }
            self::touchUsers($hash);
        }

        return (bool) $hit;
    }

    /**
     * **من تأثّر؟** مستخدمٌ جديد يُضاف لقائمةٍ محدودة في meta ويُزاد العدّاد —
     * تقريبٌ صادق (حتى ٥٠ هوية) لا عدُّ صفوفٍ لكل وقوع. قراءةٌ واحدة خفيفة.
     */
    protected static function touchUsers(string $hash): void
    {
        $uid = auth()->id();
        if (! $uid || ! hub_has_col('error_events', 'users')) return;
        try {
            $row = ErrorEvent::where('hash', $hash)->first(['id', 'meta', 'users']);
            if (! $row) return;
            $meta = (array) ($row->meta ?? []);
            $seen = (array) ($meta['users'] ?? []);
            if (in_array($uid, $seen, true)) return;
            if (count($seen) < 50) $meta['users'] = array_merge($seen, [$uid]);
            ErrorEvent::whereKey($row->id)->update(['meta' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'users' => (int) $row->users + 1]);
        } catch (\Throwable $e) {
            // إثراءٌ لا شرط — لا يكسر الالتقاط
        }
    }

    /** نمطُ المسار للتجميع حين لا اسمَ له: المعرّفات تُعمَّم */
    protected static function routePattern($req): string
    {
        return (string) preg_replace([
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            '/\/\d+(?=\/|$)/',
        ], ['{id}', '/{n}'], (string) $req->path());
    }

    /**
     * **الخطأ يصل صاحبه.** المركزُ يلتقط ويُجمّع ولا يُنبّه — فيجلس العطل في
     * شاشةٍ لا يفتحها أحد حتى يصطدم به صاحبُ النظام بنفسه. وصاحبُ النظام ليس
     * جهاز رصد.
     *
     * التنبيه لأول ظهورٍ وحده (التكرار يُزاد عدّادُه بلا صوت)، وبسقفٍ في نافذةٍ
     * قصيرة كي لا تُغرِق نشرةٌ سيئةٌ الصندوقَ فيُهجَر — والإشعار الذي يُهجَر
     * أسوأ من لا إشعار.
     */
    protected static function tell(string $message, $req): void
    {
        if (self::$inNotify) return;      // إشعارٌ يفشل فيُلتقط خطؤه فيُشعر… لا حلقة
        self::$inNotify = true;

        try {
            $key = 'errnotify:burst:' . now()->format('YmdHi');
            $n = (int) \Illuminate\Support\Facades\Cache::get($key, 0);
            \Illuminate\Support\Facades\Cache::put($key, $n + 1, now()->addMinutes(self::NOTIFY_BURST_MIN));
            if ($n >= self::NOTIFY_BURST_CAP) return;      // انفجار: البقيةُ في المركز

            $last = $n + 1 === self::NOTIFY_BURST_CAP
                ? ' — وثمة أخطاءٌ أخرى في المركز، افتحه'
                : '';
            // **طمسُ رموز المسارات العامة** (v2.319): `/hook/<48 محرفاً>` و
            // `/sign/<رمز>` بيانا اعتمادٍ كاملان لنقطتين غير موقّعتين، وكانا
            // يصلان كلَّ المراقبين في نصّ الإشعار — ومنهم من لا يملك رؤيتهما.
            $where = $req ? ' · ' . mb_substr(self::redact((string) $req->path()), 0, 80) : ' · مهمّةٌ مجدولة';

            foreach (self::watchers() as $uid) {
                hub_notify($uid, 'error',
                    '💥 عطلٌ جديد' . $where . ': ' . mb_substr(self::redact($message), 0, 300) . $last,
                    'errors', null);
            }
        } catch (\Throwable $e) {
            // صمت: التنبيه خدمةٌ للخطأ لا عبءٌ عليه
        } finally {
            self::$inNotify = false;
        }
    }

    /** من يعنيه العطل: المالكون وحاملو راية المراقبة — والموقوفُ لا يُراكَم عليه */
    protected static function watchers(): array
    {
        return \App\Models\User::with('role')->whereNull('deleted_at')->where('status', 'نشط')->get()
            ->filter(fn ($u) => $u->role?->is_owner || hub_flag($u, 'monitor'))
            ->pluck('id')->all();
    }

    public static function exception(\Throwable $e): void
    {
        // ما لا يستحق التجميع: أخطاء تحقق ومصادقة وصفحات مفقودة
        foreach ([\Illuminate\Validation\ValidationException::class,
                  \Illuminate\Auth\AuthenticationException::class,
                  \Symfony\Component\HttpKernel\Exception\HttpException::class,
                  \Illuminate\Database\Eloquent\ModelNotFoundException::class,
                  \Illuminate\Session\TokenMismatchException::class] as $skip) {
            if ($e instanceof $skip) return;
        }

        [$category, $severity] = ErrorTaxonomy::classify($e);
        self::capture(
            app()->runningInConsole() ? 'php' : (request()->is('api/*') ? 'api' : 'php'),
            get_class($e) . ': ' . self::safeMessage($e),
            $e->getFile(), $e->getLine(),
            $e->getTraceAsString(),
            ['category' => $category, 'severity' => $severity]
        );
    }

    /**
     * رسالةٌ آمنةٌ للتخزين والإشعار: رسالةُ `QueryException` تُضمّن SQL بقيمه
     * المربوطة، ورسائلُ المحرّك تُضمّن القيمَ المقتبسة (Duplicate entry '...')،
     * فقد تُسرّب رواتبَ/أسراراً/PII إلى مركز الأخطاء وإشعارِ المراقبة. نُبقي رمزَ
     * الحالة ووصفَ القيد/العمود، ونحذف مقطعَ SQL ونطمس القيمَ المقتبسة.
     */
    protected static function safeMessage(\Throwable $e): string
    {
        $msg = $e->getMessage();
        if ($e instanceof \Illuminate\Database\QueryException) {
            $msg = preg_replace('/\s*\(Connection:.*$/s', '', $msg);          // احذف SQL والقيمَ المربوطة
            $msg = preg_replace("/'(?:[^'\\\\]|\\\\.){0,300}'/", "'…'", (string) $msg);  // اطمس القيمَ المقتبسة
        }

        return (string) $msg;
    }
}
