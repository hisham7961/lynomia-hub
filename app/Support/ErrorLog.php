<?php

namespace App\Support;

use App\Models\ErrorEvent;

/** مسجل الأخطاء المجمّع — لا يرمي أبداً (فشل التسجيل لا يفاقم الخطأ الأصلي) */
class ErrorLog
{
    /** أقصى إشعارٍ لكل مسؤولٍ في نافذة الانفجار — ونشرةٌ سيئة لا تُغرق صندوقاً */
    public const NOTIFY_BURST_CAP = 8;
    public const NOTIFY_BURST_MIN = 15;

    /**
     * (WP-3.2) فرضُ سقف العيّنات كلَّ N إدراج لا مع كلِّ إدراج: باقي قسمةِ
     * عدّادِ الحدث — رخيصٌ بلا عدِّ صفوف، وما بين فرضَين يفيض الجدولُ
     * بـ N−1 صفّاً على الأكثر فوق `errors.occurrences_keep`.
     */
    public const OCCURRENCES_TRIM_EVERY = 10;

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
        // (v2.399) ورموزُ المشاركة العامّة /s/{token} ومساحاتُ العمل /w/{key} — كانت تصل الإشعاراتِ والسجلَّ بنصّها
        // (WP-1.3) تفويضٌ للمُطهِّر الواحد: قاعدةُ المسارات بحرفها + Bearer/JWT/PEM/lyn_/رمز البوت/مفتاح=قيمة
        return Redactor::text($s);
    }

    /**
     * @param array{category?:string,severity?:string,duration_ms?:int,status_code?:int} $ctx
     *        تصنيفٌ صريح (وإلا يُشتقّ من نوع الالتقاط) + إثراءُ العيّنة: مدّةُ
     *        الطلب الحقيقية يمرّرها التقاطُ البطء في Observability (WP-3.2)
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
            if ($hit = self::bump($hash, $req)) {
                self::occurrence($hash, $req, $ctx, $hit);   // (WP-3.2) عيّنةُ هذا الوقوع بعينه — بالصفّ المقروء سلفاً
                return;
            }

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
                    // العرضُ عند الكاتب (٤٠): معرّفٌ فائضٌ كان يُسقط الإدراجَ كلَّه على MySQL الصارمة
                    'request_id' => $req && $req->attributes->get('request_id') !== null
                        ? mb_substr((string) $req->attributes->get('request_id'), 0, 40) : null,
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
                self::occurrence($hash, $req, $ctx);   // (WP-3.2) أولُ وقوعٍ عيّنةٌ أيضاً

                // بصمةٌ جديدة = خبرٌ جديد. والتكرارُ يُزاد عدّادُه في bump بلا تنبيه.
                // أخطاءُ المتصفّح (jslog) نصٌّ يكتبه أيُّ مستخدمٍ مسجَّل: تُجمَّع في المركز ولا
                // تُدفع إشعاراً للمالكين — وإلا صار البلاغُ قناةَ تصيّدٍ بنصٍّ حرّ (v2.399).
                // (WP-3.5) الشدّةُ تُمرَّر: CRITICAL يتجاوز سقفَ الانفجار — كان الحرجُ
                // يسقط صامتاً بعد الإشعار الثامن في النافذة (§4.12). ولا hash هنا عمداً:
                // بصمةٌ تُرى أولَ مرةٍ لا كتمَ عليها ولا تبريد — الخبرُ الأول يمرّ دائماً.
                if ($kind !== 'js') self::tell($message, $req, (string) $severity);
            } catch (\Illuminate\Database\QueryException $e) {
                self::bump($hash, $req);    // خسرنا سباق الإدراج — الصف موجود الآن فزده
                self::occurrence($hash, $req, $ctx);
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

    /**
     * زيادة العدّاد ذرّياً إن وُجد الصف — يعيد الصفَّ المقروء (أو null إن لم يوجد).
     * قراءةٌ **واحدة** بعد التحديث تخدم فحصَ العودة وإثراءَ المستخدمين وعيّنةَ
     * الوقوع معاً — كانت ثلاثَ قراءاتٍ للصفّ نفسِه في كل تكرار (ميزانية §41: ≤ ٤ عبارات).
     */
    protected static function bump(string $hash, $req): ?ErrorEvent
    {
        $hit = ErrorEvent::where('hash', $hash)->update([
            'count' => \Illuminate\Support\Facades\DB::raw('count + 1'),
            'last_seen' => now(),
            'url' => $req ? mb_substr(self::redact($req->fullUrl()), 0, 390) : \Illuminate\Support\Facades\DB::raw('url'),
            'user_id' => auth()->id() ?? \Illuminate\Support\Facades\DB::raw('user_id'),
        ]);
        if (! $hit) return null;

        // أعمدةُ الإثراء المتأخرةُ الهجرةِ تُطلب بحارسها المخبّأ — «الهجرةُ
        // المتأخرة تُنقص ميزةً ولا تُطفئ نظاماً»: طلبُها العمياءُ يرمي على
        // قاعدةٍ لم تُرحَّل فيسقط العدُّ والإشعارُ معاً حتى تجري الهجرة
        $cols = ['id', 'count', 'status', 'message'];
        if (hub_has_col('error_events', 'meta')) $cols[] = 'meta';
        if (hub_has_col('error_events', 'users')) array_push($cols, 'users', 'severity');
        $row = ErrorEvent::where('hash', $hash)->first($cols);
        if ($row) {
            // خطأ محلول عاد للظهور → يعود «جديد» ليلفت النظر، **ويُنبَّه به**:
            // عودةُ عطلٍ حُسب مُغلقاً أهمُّ من ظهوره الأول، وكانت تمرّ صامتة.
            // (WP-3.3) والعودةُ بعد حلٍّ **انحدارٌ مختوم**: متى عاد وبأيّ نسخةٍ —
            // فيُجاب «هل أُصلح من قبل؟» من العمود لا من الذاكرة. إشعارٌ واحدٌ
            // للعودة (التكرار بعدها عدٌّ صامت لأن الحالة لم تعد «محلول»).
            if ($row->status === 'محلول') {
                $back = $row;
                $patch = ['status' => 'جديد'];
                if (hub_has_col('error_events', 'regressed_at')) {
                    $patch += ['regressed_at' => now(),
                               'regression_release' => mb_substr((string) config('hub.version'), 0, 20)];
                }
                ErrorEvent::whereKey($back->id)->update($patch);
                // (WP-3.5) العودةُ هي قناةُ التكرار الوحيدة لكل بصمة — فالكتمُ
                // (muted_until) والتبريدُ (errnotify:fp) يُفحصان في tell عبر البصمة
                self::tell('عاد بعد أن حُسب محلولاً — ' . $back->message, $req,
                    (string) ($back->severity ?? ''), $hash);
            }
            self::touchUsers($row);
        }

        // اختفى الصفُّ بين التحديث والقراءة (حذفٌ متزامن — نادرٌ جداً):
        // null يُعيد capture إلى مسار الإنشاء فيُسجَّل الوقوعُ صفاً جديداً
        return $row;
    }

    /**
     * **من تأثّر؟** مستخدمٌ جديد يُضاف لقائمةٍ محدودة في meta ويُزاد العدّاد —
     * تقريبٌ صادق (حتى ٥٠ هوية) لا عدُّ صفوفٍ لكل وقوع. قراءةٌ واحدة خفيفة.
     */
    protected static function touchUsers(ErrorEvent $row): void
    {
        $uid = auth()->id();
        if (! $uid || ! hub_has_col('error_events', 'users')) return;
        try {
            $meta = (array) ($row->meta ?? []);
            $seen = (array) ($meta['users'] ?? []);
            if (in_array($uid, $seen, true)) return;
            if (count($seen) < 50) $meta['users'] = array_merge($seen, [$uid]);
            ErrorEvent::whereKey($row->id)->update(['meta' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'users' => (int) $row->users + 1]);
        } catch (\Throwable $e) {
            // إثراءٌ لا شرط — لا يكسر الالتقاط
        }
    }

    /**
     * (WP-3.2) **عيّنةُ وقوعٍ محدودة** — يجيب بها الحدثُ المجمَّع «أيُّ طلبٍ
     * سبّبه؟ من أصابه؟ بأيّ نسخة؟». إدراجٌ واحدٌ رخيص، والرابطُ مطموسٌ
     * (Redactor) والعروضُ تُفرض هنا عند الكاتب — MySQL الصارمة ترمي ما يفيض
     * حيث تبتره SQLite صامتةً. إثراءٌ لا شرط: لا يرمي أبداً ولا يمسّ الالتقاط.
     */
    protected static function occurrence(string $hash, $req, array $ctx, ?ErrorEvent $ev = null): void
    {
        try {
            // حارسُ الجدول المخبّأ (hub_has_col — ٥ دقائق): قاعدةٌ لم تُرحَّل بعدُ
            // تُسكِت العيّناتِ بلا كسر، وجدولٌ أُسقط تحت كاشٍ دافئ يرمي
            // QueryException فيبتلعها الغلاف أدناه — الالتقاطُ نفسُه لا يتأثّر.
            if (! hub_has_col('error_occurrences', 'error_event_id')) return;

            // مسارُ التكرار يمرّر الصفَّ المقروء في bump — لا قراءةَ ثالثة له؛
            // مسارا الإنشاء والسباق (نادران) يقرآنه هنا
            $ev = $ev ?: ErrorEvent::where('hash', $hash)->first(['id', 'count']);
            if (! $ev) return;

            $rid = $req ? mb_substr((string) $req->attributes->get('request_id'), 0, 40) : '';
            // سياقٌ آمنٌ بالبناء: مصدرُ الطلب وعنوانُه ووكيلُه (مطموساً) — لا حمولةَ ولا ترويسات
            $safe = $req ? array_filter([
                'source' => $req->attributes->get('request_source'),
                'ip' => $req->ip(),
                'agent' => mb_substr(Redactor::text((string) $req->userAgent()), 0, 160),
            ]) : [];

            \Illuminate\Support\Facades\DB::table('error_occurrences')->insert([
                'error_event_id' => $ev->id,
                'occurred_at' => now(),
                'request_id' => $rid !== '' ? $rid : null,
                'user_id' => auth()->id(),
                'route' => $req ? mb_substr((string) ($req->route()?->getName() ?: self::routePattern($req)), 0, 160) : null,
                'url' => $req ? mb_substr(self::redact($req->fullUrl()), 0, 400) : null,
                'method' => $req ? mb_substr($req->method(), 0, 10) : null,
                'release' => mb_substr((string) config('hub.version'), 0, 20),
                'status_code' => isset($ctx['status_code']) ? (int) $ctx['status_code'] : null,
                'duration_ms' => isset($ctx['duration_ms']) ? max(0, (int) $ctx['duration_ms']) : null,
                'safe_context' => $safe ? json_encode($safe, JSON_UNESCAPED_UNICODE) : null,
            ]);

            // فرضُ السقف كلَّ N إدراج (باقي قسمةِ عدّاد الحدث — لا عدَّ صفوف):
            // يُبقي أحدثَ `errors.occurrences_keep` عيّنةً ويحذف ما دونها دفعةً
            // واحدة بشرطِ `id` التزايديّ — ترتيبٌ حتميّ لا قرعةَ فيه.
            $keep = max(5, (int) setting('errors.occurrences_keep', 50));
            if (((int) $ev->count) % self::OCCURRENCES_TRIM_EVERY === 0) {
                $cut = \Illuminate\Support\Facades\DB::table('error_occurrences')
                    ->where('error_event_id', $ev->id)->orderByDesc('id')->skip($keep)->value('id');
                if ($cut !== null) {
                    \Illuminate\Support\Facades\DB::table('error_occurrences')
                        ->where('error_event_id', $ev->id)->where('id', '<=', $cut)->delete();
                }
            }
        } catch (\Throwable $e) {
            // عيّنةٌ لا شرط — فشلُها لا يفاقم الخطأ الأصلي
        }
    }

    /**
     * نمطُ المسار للتجميع حين لا اسمَ له: المعرّفات تُعمَّم (UUID ⇒ {id}،
     * رقمٌ ⇒ {n}). **المطبِّعُ الواحد** (WP-2.2): يستهلكه التقاطُ البطء ودلاءُ
     * RED في Observability أيضاً — نسخةٌ محليةٌ هناك حُذفت لصالحه كي لا
     * ينحرف تجميعُ الأخطاء عن تجميع القياس.
     */
    public static function routePattern($req): string
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
     *
     * (WP-3.5) وثلاثُ سككٍ فوق ذلك:
     *   · `$severity` = CRITICAL **يتجاوز** سقفَ الانفجار — كان الحرجُ يسقط
     *     صامتاً بعد الثامن في النافذة، مخالفةً صريحةً لـ§4.12.
     *   · `$hash` (يمرّره مسارُ العودة وحده — قناةُ التكرار الوحيدة لكل بصمة):
     *     بصمةٌ كتمها المالك (`muted_until`) لا تُصوِّت حتى ينقضي الأمد،
     *     وبصمةٌ نُبِّه بعودتها للتوّ تُبرَّد (`errnotify:fp:<hash>`) مدةَ
     *     `errors.notify_cooldown_min` — فخطأٌ يرتدّ بين حلٍّ وعودةٍ كلَّ دقيقة
     *     لا يكتب ستين إشعاراً. الكتمُ يسبق العدَّ فلا يستهلك المكبوتُ سقفَ غيره.
     */
    protected static function tell(string $message, $req, string $severity = '', string $hash = ''): void
    {
        if (self::$inNotify) return;      // إشعارٌ يفشل فيُلتقط خطؤه فيُشعر… لا حلقة
        self::$inNotify = true;

        try {
            if ($hash !== '') {
                // كتمُ البصمة (يختمه المالك من شاشة الخطأ — WP-3.3): قرارٌ صريح
                // يُحترم حتى للحرج، والحالةُ تتغيّر قبل هذا فالحقيقة لا تُكتم
                if (hub_has_col('error_events', 'muted_until')) {
                    $mu = ErrorEvent::where('hash', $hash)->value('muted_until');
                    if ($mu && now()->lt(\Illuminate\Support\Carbon::parse($mu))) return;
                }
                // تبريدُ البصمة: إشعارُ عودةٍ واحد في النافذة مهما تكرّر الارتداد
                if (\Illuminate\Support\Facades\Cache::has('errnotify:fp:' . $hash)) return;
            }

            // نافذةٌ حقيقية (١٥ دقيقة) لا دقيقةٌ تقويمية: كان المفتاحُ يتجدّد كل دقيقة فيصير
            // السقفُ ٨ في الدقيقة (٤٨٠ في الساعة) لا ٨ في النافذة (v2.399)
            $key = 'errnotify:burst:' . intdiv(now()->timestamp, self::NOTIFY_BURST_MIN * 60);
            $n = (int) \Illuminate\Support\Facades\Cache::get($key, 0);
            \Illuminate\Support\Facades\Cache::put($key, $n + 1, now()->addMinutes(self::NOTIFY_BURST_MIN));
            // انفجار: البقيةُ في المركز — إلا الحرجَ فلا يختفي صامتاً أبداً (§4.12)
            if ($n >= self::NOTIFY_BURST_CAP && $severity !== 'CRITICAL') return;

            if ($hash !== '') {
                \Illuminate\Support\Facades\Cache::put('errnotify:fp:' . $hash, 1,
                    now()->addMinutes(max(1, (int) setting('errors.notify_cooldown_min', 15))));
            }

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
    public static function safeMessage(\Throwable $e): string
    {
        $msg = $e->getMessage();
        // (WP-1.3) تفويضٌ للمُطهِّر الواحد: قاعدةُ SQL بحرفها لرسائل QueryException وحدها
        if ($e instanceof \Illuminate\Database\QueryException) $msg = Redactor::sql($msg);

        return (string) $msg;
    }
}
