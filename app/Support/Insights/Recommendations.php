<?php

namespace App\Support\Insights;

/**
 * محرّكاتٌ نُقلت من `helpers.php` بلا تغيير (docs/REORG_PLAN.md §R5) — والدوالُّ العامّةُ بأسمائها
 * باقيةٌ هناك أغلفةً من سطرٍ واحد، فلا Blade ولا متحكّمَ يتغيّر.
 */
final class Recommendations
{
    /**
     * مركز التوصيات: يجمع إشاراتٍ قابلة للتنفيذ من محرّكات النظام القائمة —
     * خدمات تحت الماء، فريق فوق طاقته، مشاريع متعثرة، تطبيقات كثيرة الأعطال،
     * انتهاءات وشيكة، مستحقات غير محصّلة. كلها من بياناتك المسجَّلة لا من تقدير،
     * وكل توصية تحمل سببها بالأرقام ورابط إجرائها. مرتّبة بالأولوية.
     */
    public static function build(bool $fresh = false, ?string $projectId = null): array
    {
        // المفتاح كان سلسلةً مسطّحة «recs»: بلا مستخدمٍ ولا دورٍ ولا مشروع —
        // فوق استعلاماتٍ مُنطَّقة بـhub_scope. أي أن أول من يفتح اللوحة يُخبّئ
        // نتائجه لكل من بعده خمس دقائق. يحمل المفتاح الثلاثة الآن.
        // المفتاحُ يفرّق **كلَّ أنماط العزل الثلاثة**: مشروعٌ وشركةٌ **وعميل**. كان
        // عزلُ العميل غائباً — فمستخدمان محصوران بعميلين مختلفين يتقاسمان دورَهما
        // كانا يتقاسمان مفتاحَ الدور (`r:`) فتُخبَّأ إشاراتُ عميلٍ وتُقدَّم لمستخدمِ
        // آخر: تسريبُ عزلٍ عبر الخبيئة. من له أيُّ حصرٍ يأخذ مفتاحاً خاصّاً به.
        $u = auth()->user();
        // **مبدّلُ الشركة/العميل جزءٌ من المفتاح** كما في `hub_scope_key`: بعضُ الكُتل
        // (تقاريرُ اليوم عبر `hub_company_scope`) تضيق بالمبدّل النشط، فمستخدمان يريان
        // «كلَّ الشركات» بمبدّلين مختلفين كانا يتقاسمان مفتاحاً فيُقدَّم عدُّ شركةٍ لأخرى.
        // ختمُ roles/users كما في `hub_scope_key` و`hub_expiry`: تغيّرُ صلاحيةٍ أو دورٍ
        // يُبطل الخبيئةَ فوراً لا بعد انقضاء المهلة (نافذةُ صلاحيةٍ متقادمةٍ للمستخدم غيرِ المحصور).
        // **ومفتاحُ المخبأِ عقدٌ عن محتواه** (مجلس الخبراء · F2). كان المفتاحُ
        // **بالدور** لغيرِ المحصور — وكان صحيحاً ما دام المحتوى بالدور. ثمّ صارت
        // `hub_expiry()` تُرجع **صفَّ صاحبِ الشأن** (صفّاً بالمستخدمِ لا بالدور)،
        // فبات وعاءُ الدورِ يحمل شأناً شخصيّاً: أثبت التحقّقُ المستقلُّ أنّ زميلاً
        // بلا `hr:v` قرأ **اسمَ زميلِه وتاريخَ انتهاءِ إقامتِه**، ثمّ إذا أعاد بناءَ
        // المخبأِ من منظورِه **مُحي إنذارُ صاحبِه عنه**. فالمفتاحُ بالمستخدمِ دائماً:
        // مشاركةُ المخبأِ لا تُشترى بإفشاء. (والكلفةُ مدخلٌ لكلِّ مستخدمٍ بدل كلِّ
        // دور — وهو ما تفعله `hub_expiry` نفسُها للمحصورين أصلاً.)
        $key = 'recs:u:' . ($u?->id ?? '0')
            . ':' . (string) session('hub.company', '-') . ':' . (string) session('hub.client', '-')
            . hub_lens_key($projectId) . hub_data_stamp(['roles', 'users']);
        if ($fresh) \Illuminate\Support\Facades\Cache::forget($key);

        return \Illuminate\Support\Facades\Cache::remember($key, 300, function () use ($projectId) {
            $rank = ['حرج' => 3, 'مهم' => 2, 'اطّلاع' => 1];
            $out = [];
            // `key`/`module`/`record_id` إضافةٌ متوافقةٌ خلفياً (القرّاء القدامى يقرؤون
            // الستّةَ الأولى ويتجاهلون الباقي): تجعل كلَّ إشارةٍ **قابلةً للتصرّف**
            // (إقرار/تأجيل/رفض) في مركز الفعل بمفتاحٍ ثابتٍ يُدمَج لا يتكرّر.
            /*
             * **وبطاقةٌ تسمّي سجلَّ وحدةٍ تشترط صلاحيّةَ رؤيتِها** (L5-03 · v2.557).
             *
             * كُتلُ التوصياتِ تُنطَّق بـ`hub_scope` — والنطاقُ يضيّق بالمشروعِ
             * والشركةِ والعميل، **لا بالصلاحيّة**. فبطاقةٌ تحمل `module` تسمّي
             * سجلَّه (رقمَ فاتورةٍ · اسمَ مشروع) وتعطي رابطَه، فيقرؤها من لا
             * يملك `v` على تلك الوحدة ويُصَدُّ ٤٠٣ عند النقر — وهو ما يخالف
             * ثابتَ المنصّةِ المكتوبَ في `helpers.php:524`: «لا رابطٌ يظهر ثم
             * يُصَدُّ ٤٠٣».
             *
             * وبعضُ الكُتلِ كانت تشترطها وبعضُها لا (كتلةُ مؤشّراتِ الماليّة
             * تشترط `fin:v` وكتلةُ المستحقّاتِ لم تكن). **فالحارسُ عند المُصدِر
             * لا عند كلِّ كتلة**: قاعدةٌ واحدةٌ تسري على ما كُتب وما سيُكتَب —
             * وهو درسُ رادارِ الانتهاءات نفسُه، مطبَّقاً هنا في موضعِ الإصدار.
             *
             * وبلا `module` تمرّ البطاقةُ كما هي: إشاراتٌ عامّةٌ لا تسمّي سجلاً.
             * **وبلا مستخدمٍ لا ترشيح** — سياقٌ نظاميّ (طرفيّةٌ أو مهمّةٌ مجدولة)،
             * والمستقبِلون يُرشَّحون في موضعِهم لا هنا؛ وهي قاعدةُ `hub_expiry`
             * نفسُها. (وبدونها سقطت `RecommendationsTest` التي تستدعي الدالّةَ
             * بلا جلسة — فكشفتها البوّابةُ قبل الدفع.)
             *
             * **و«صفُّ صاحبِ الشأن» مستثنىً صراحةً** (PROD-05): إنذارُ انتهاءِ
             * إقامةِ الموظّفِ **نفسِه** يُعرَض له عمداً وإن لم يملك `hr:v` —
             * و`hub_expiry_target()` توجّهه إلى ملفّه (`portal.me`) لا إلى سجلِّ
             * الوحدةِ الذي يردّه ٤٠٣. فالحجبُ هنا كان سيمحو من مركزِ الفعلِ
             * إنذارَ الرجلِ عن نفسِه — وهو ما أسقطته `CouncilExpirySelfReachTest`
             * قبل الدفع. **الصلاحيّةُ تحرس سجلَّ غيرِك لا سجلَّك.**
             */
            $add = function ($sev, $ico, $title, $why, $url, $action, $key = null, $module = null, $recordId = null, bool $selfRow = false) use (&$out) {
                $who = auth()->user();
                if ($module !== null && ! $selfRow && $who !== null
                    && ! hub_can($who, (string) $module, 'v')) return;
                $out[] = compact('sev', 'ico', 'title', 'why', 'url', 'action', 'key', 'module', 'recordId');
            };

            // ١) خدمات تبيع بأقل من كلفتها
            try {
                $svc = hub_service_costs();
                // ترتيبٌ حتميّ قبل القصّ: الأسوأ هامشاً أولاً ثم `id` فاصلاً — كان القصُّ
                // يأخذ أوائلَ الصفوف بترتيب القاعدة (قرعةٌ بين المحرّكين عند تساوي الهامش).
                $losers = array_filter($svc['rows'], fn ($r) => $r['margin'] !== null && $r['margin'] < 0);
                usort($losers, fn ($a, $b) => [$a['margin'], $a['id'] ?? 0] <=> [$b['margin'], $b['id'] ?? 0]);
                foreach (array_slice($losers, 0, 5) as $s) {
                    $add('حرج', '🌊', 'خدمة تبيع بخسارة: ' . $s['name'],
                        'سعرها الشهري ' . number_format((float) $s['priceM'], 1) . ' وكلفتها ' . number_format((float) $s['costM'], 1)
                        . ' — هامش ' . number_format((float) $s['margin'], 1) . ' شهرياً. راجع السعر أو الكلفة.',
                        route('m.show', ['services', $s['id']]), 'راجع الخدمة',
                        'svc.loss:' . $s['id'], 'services', $s['id']);
                }
                if (($svc['totals']['unpriced'] ?? 0) > 0) {
                    $add('اطّلاع', '🏷️', ($svc['totals']['unpriced']) . ' خدمة بلا سعر شهري',
                        'لا يمكن قياس ربحيتها حتى تُسعّرها. سجّل أسعارها لتظهر في تحليل التكلفة.',
                        route('servicecosts'), 'افتح تحليل التكلفة');
                }
            } catch (\Throwable $e) {}

            // ٢) فريق فوق طاقته هذا الأسبوع
            try {
                $cap = hub_capacity(null, null, $projectId);
                $over = array_filter($cap['rows'], fn ($r) => ($r['load'] ?? 0) > 100);
                usort($over, fn ($a, $b) => $b['load'] <=> $a['load']);
                foreach (array_slice($over, 0, 4) as $r) {
                    $add('مهم', '🔥', 'فوق طاقته: ' . $r['name'],
                        'حمله ' . $r['load'] . '٪ — محجوز ' . $r['booked'] . ' ساعة على متاح ' . $r['available']
                        . '. أجّل أو وزّع أو وظّف.',
                        route('capacity'), 'افتح لوحة القدرات',
                        'cap.over:' . ($r['id'] ?? $r['name']), 'employees', $r['id'] ?? null);
                }
            } catch (\Throwable $e) {}

            // ٣) مشاريع متعثرة الصحة — **منطَّقٌ بـhub_scope كأختيه (٩ و١١)**: كان
            // يَسرد كلَّ مشاريع المنشأة (اسماً ودرجةَ صحّة) لمستخدمٍ محصورٍ بمشاريعه،
            // بينما كتلتا الركود والحواجب على الجدول نفسِه تحصرانه — تسريبٌ ثابتُ التناقض.
            try {
                $projects = hub_scope(\Illuminate\Support\Facades\DB::table('projects')->whereNull('deleted_at'), 'projects')
                    ->where(fn ($w) => $w->whereNull('status')->orWhereNotIn('status', hub_closed_states()))
                    ->when($projectId, fn ($q) => $q->where('id', $projectId))
                    ->orderBy('id')->limit(40)->get(['id', 'name']);   // حتميّةُ اختيار الأربعين بين المحرّكين
                $sick = [];
                foreach ($projects as $p) {
                    $h = hub_project_health($p->id);
                    if (($h['score'] ?? 100) < 55) $sick[] = ['p' => $p, 'h' => $h];
                }
                usort($sick, fn ($a, $b) => $a['h']['score'] <=> $b['h']['score']);
                foreach (array_slice($sick, 0, 5) as $s) {
                    $add($s['h']['score'] < 40 ? 'حرج' : 'مهم', '🩺', 'مشروع متعثر: ' . $s['p']->name,
                        'صحته ' . $s['h']['score'] . '/١٠٠ (' . ($s['h']['label'] ?? '') . '). راجع عوامل التعثر في صفحته.',
                        route('m.show', ['projects', $s['p']->id]), 'افتح المشروع',
                        'proj.health:' . $s['p']->id, 'projects', $s['p']->id);
                }
            } catch (\Throwable $e) {}

            // ٤) تطبيقات كثيرة الأخطاء الحرجة أو التراجع عن النشر
            try {
                foreach (hub_app_quality() as $a) {
                    if (($a['critBugs'] ?? 0) >= 1) {
                        $add('حرج', '🐞', 'أخطاء حرجة مفتوحة: ' . $a['name'],
                            $a['critBugs'] . ' خطأ حرج مفتوح' . (($a['openBugs'] ?? 0) ? ' من ' . $a['openBugs'] . ' مفتوح' : '') . '. عالجها قبل النشر القادم.',
                            route('appquality'), 'افتح جودة البرمجيات');
                    } elseif (($a['rollback'] ?? null) !== null && $a['rollback'] > 20) {
                        $add('مهم', '↩️', 'نشر غير مستقر: ' . $a['name'],
                            'معدل التراجع ' . $a['rollback'] . '٪ من ' . ($a['deploys'] ?? 0) . ' نشرة. راجع جودة الإصدارات قبل الدفع.',
                            route('appquality'), 'افتح جودة البرمجيات');
                    }
                }
            } catch (\Throwable $e) {}

            // ٥) مستحقات غير محصّلة قديمة
            try {
                // صلاحيّةُ `fin:v` تُفرَض عند مُصدِرِ البطاقة (`$add`) لا هنا —
                // قاعدةٌ واحدةٌ لكلِّ الكُتل، وتحترم السياقَ النظاميَّ بلا جلسة (L5-03).
                if (\Illuminate\Support\Facades\Schema::hasTable('fin_documents')) {
                    // التعريفُ الموحَّد `hub_fin_outstanding` (أنواعُ الدخل الحقيقية لا
                    // «فاتورة» المجرّدة)، منطَّقٌ بـhub_scope كبقيّة كُتل المركز.
                    $base = hub_scope(\Illuminate\Support\Facades\DB::table('fin_documents')->whereNull('deleted_at'), 'fin')
                        ->when($projectId, fn ($q) => $q->where('project_id', $projectId));
                    $overdue = hub_fin_outstanding($base)
                        ->orderBy('due')->orderBy('id')->limit(6)->get(['id', 'doc_no', 'partner', 'total', 'paid', 'due']);
                    foreach ($overdue as $d) {
                        $rem = (float) ($d->total ?? 0) - (float) ($d->paid ?? 0);
                        $days = (int) \Illuminate\Support\Carbon::parse($d->due)->diffInDays(now());
                        $add($days > 60 ? 'حرج' : 'مهم', '💸', 'مستحق متأخر: ' . ($d->partner ?: ($d->doc_no ?: 'فاتورة')),
                            'باقٍ ' . number_format($rem, 1) . ' متأخر ' . $days . ' يوماً. تابع التحصيل.',
                            route('m.show', ['fin', $d->id]), 'افتح الفاتورة',
                            'fin.overdue:' . $d->id, 'fin', $d->id);
                    }
                }
            } catch (\Throwable $e) {}

            // ٦) انتهاءات وشيكة (٧ أيام)
            try {
                $soon = collect(hub_expiry())->filter(fn ($i) => ($i['days'] ?? 99) <= 7)->take(6);
                foreach ($soon as $i) {
                    $add($i['days'] < 0 ? 'حرج' : 'مهم', '⏳', 'ينتهي قريباً: ' . $i['name'],
                        $i['mlabel'] . ' · ' . $i['flabel'] . ' — ' . ($i['days'] < 0 ? 'متأخر' : ($i['days'] === 0 ? 'اليوم' : 'خلال ' . $i['days'] . ' يوم')) . '.',
                        hub_expiry_url($i), 'افتح السجل',
                        // المفتاح يحمل مميّزَ الحقل/الوثيقة (fkey) فلا تتصادم إشارتا انتهاءٍ
                        // على السجل نفسِه على حالةٍ واحدة (كان module:id وحدهما يُدمجانهما).
                        'expiry:' . $i['module'] . ':' . $i['id'] . ':' . ($i['fkey'] ?? ($i['flabel'] ?? '')),
                        // وصفُّ صاحبِ الشأنِ يمرّ بلا اشتراطِ صلاحيّةِ الوحدة (PROD-05)
                        $i['module'], $i['id'], ! empty($i['self']));
                }
            } catch (\Throwable $e) {}

            // ٧) عرضٌ مقبولٌ لم يُحوَّل إلى تسليم (فجوةُ CPQ→تنفيذ) — منطَّقٌ بـhub_scope
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('quotes')) {
                    $stuck = hub_scope(\App\Models\Quote::query(), 'quotes')
                        ->where('status', 'مقبول')->whereNotNull('accepted_at')
                        ->where('accepted_at', '<', now()->subDays(2))
                        ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
                        // فاصلٌ حتميّ: عروضٌ قُبلت في الثانية نفسها لا تُقترَع بين المحرّكين
                        ->orderByDesc('accepted_at')->orderByDesc('id')->limit(8)->get(['id', 'doc_no', 'title', 'accepted_at', 'meta', 'project_id']);
                    foreach ($stuck as $q) {
                        $meta = (array) (is_array($q->meta) ? $q->meta : (json_decode((string) $q->meta, true) ?: []));
                        /*
                         * **حقيقةُ التحويل من مصدرِها الواحد** (الجولة 2 · G18ب): كان
                         * الفحصُ على `meta` وحدَه، فعرضٌ رُبط مشروعُه في **العمود**
                         * (مسارُ التحويل القائم) يبقى موسوماً «لم يُحوَّل» والإشارةُ
                         * تناديه أبداً — رصدها وكيلُ محاكاةِ دورةِ المشروع: مشروعٌ
                         * وفاتورةٌ قائمان والعرضُ يقول «لم يُحوَّل». `linkedProjectId`
                         * هي الحاكمةُ (عمودٌ أو meta، مع التحقّق من وجود المشروع).
                         */
                        if ($q->linkedProjectId() || ! empty($meta['engagement_id'])) continue;
                        $days = (int) \Illuminate\Support\Carbon::parse($q->accepted_at)->diffInDays(now());
                        $add($days > 7 ? 'حرج' : 'مهم', '🔗', 'عرضٌ مقبولٌ لم يُحوَّل: ' . ($q->title ?: $q->doc_no),
                            'قُبل منذ ' . $days . ' يوماً ولا مشروعَ ولا ارتباط. حوّله لتبدأ التسليمَ والتحصيل.',
                            route('m.show', ['quotes', $q->id]), 'حوّله لمشروع',
                            'quote.unconverted:' . $q->id, 'quotes', $q->id);
                    }
                }
            } catch (\Throwable $e) {}

            // ٨) عهدةٌ متأخرةُ الاسترداد — من منتِج القائم `Custody::overdue` (منطَّقٌ سلفاً)
            try {
                foreach (\App\Support\Assets\Custody::overdue(8) as $c) {
                    $add(($c['late'] ?? 0) > 14 ? 'حرج' : 'مهم', '📦', 'عهدةٌ متأخرةُ الاسترداد: ' . $c['asset'],
                        'تصريحُ «' . $c['action'] . '» استحقّ رجوعُه ' . $c['due'] . ' — متأخرٌ ' . $c['late'] . ' يوماً. تابع الاسترداد.',
                        route('m.show', ['assets', $c['assetId']]), 'افتح الأصل',
                        'custody.overdue:' . $c['id'], 'assets', $c['assetId']);
                }
            } catch (\Throwable $e) {}

            // ٩) مشاريع راكدة: نشطةٌ (غيرُ مغلقةٍ ولا متوقّفة) بلا حِراكٍ منذ مدّة —
            // **لا محرّكَ صحّةٍ ثانٍ**: إشارةٌ مستقلّةٌ تُشتقّ من آخرِ أثرٍ فعليّ
            // (تدقيقُ المشروع + آخرُ تحديثِ مهمّة)، منطَّقةٌ بـhub_scope كالبقية.
            try {
                $paused = hub_paused_states();   // السلطةُ نفسُها التي يسألها الحاسب — F-05
                $projs = hub_scope(\Illuminate\Support\Facades\DB::table('projects')->whereNull('deleted_at'), 'projects')
                    ->where(fn ($w) => $w->whereNull('status')
                        ->orWhere(fn ($q) => $q->whereNotIn('status', hub_closed_states())->whereNotIn('status', $paused)))
                    ->when($projectId, fn ($q) => $q->where('id', $projectId))
                    // ترتيبٌ حتميّ قبل الحدّ: بلا `orderBy` يختلف الستّون المُختارون
                    // بين المحرّكين فيسقط راكدٌ خلف الصفّ ٦٠ بلا رصد. الأقدمُ تحديثاً
                    // أولاً — أرجحُ للركود، والمعرّفُ فاصلٌ ثابت.
                    ->orderBy('updated_at')->orderBy('id')
                    ->limit(60)->get(['id', 'name', 'updated_at']);
                if ($projs->isNotEmpty()) {
                    $ids = $projs->pluck('id')->all();
                    $auditMax = \Illuminate\Support\Facades\DB::table('audits')->where('module', 'projects')
                        ->whereIn('record_id', $ids)->select('record_id', \Illuminate\Support\Facades\DB::raw('MAX(created_at) as m'))
                        ->groupBy('record_id')->pluck('m', 'record_id');
                    $taskMax = \Illuminate\Support\Facades\DB::table('tasks')->whereNull('deleted_at')
                        ->whereIn('project_id', $ids)->select('project_id', \Illuminate\Support\Facades\DB::raw('MAX(updated_at) as m'))
                        ->groupBy('project_id')->pluck('m', 'project_id');
                    $threshold = (string) now()->subDays(7);
                    $stalled = [];
                    foreach ($projs as $p) {
                        $last = collect([$p->updated_at, $auditMax[$p->id] ?? null, $taskMax[$p->id] ?? null])
                            ->filter()->map(fn ($t) => (string) $t)->max();
                        if (! $last || $last >= $threshold) continue;
                        $stalled[] = ['p' => $p, 'last' => substr($last, 0, 10),
                            'days' => (int) \Illuminate\Support\Carbon::parse($last)->diffInDays(now())];
                    }
                    usort($stalled, fn ($a, $b) => [$b['days'], $a['p']->id] <=> [$a['days'], $b['p']->id]);
                    foreach (array_slice($stalled, 0, 6) as $s) {
                        $add($s['days'] > 21 ? 'حرج' : 'مهم', '🕸️', 'مشروعٌ راكد: ' . $s['p']->name,
                            'لا حِراكَ (مهامٌ أو تدقيق) منذ ' . $s['days'] . ' يوماً — آخرُ نشاطٍ ' . $s['last'] . '. راجعه أو اطلب تحديثاً.',
                            route('m.show', ['projects', $s['p']->id]), 'افتح المشروع',
                            'proj.stalled:' . $s['p']->id, 'projects', $s['p']->id);
                    }
                }
            } catch (\Throwable $e) {}

            // ١٠) تقاريرُ يوميّةٌ ناقصةٌ اليوم — من **محرّك يوم العمل** (لا محرّكَ حضورٍ
            // ثانٍ): `Workday::teamToday` منطَّقٌ بمفتاح `hub_screen` (دورٌ/مستخدمٌ/شركةٌ/
            // عميل) ومحروسٌ بصلاحية `hr` داخل `teamCalc` — فلا تسريبٌ ولا تكرار. القاعدةُ
            // الذهبية محفوظة: تقريرٌ ناقصٌ ليس غياباً. (إشارةٌ للعدسة العامّة لا لمشروع.)
            try {
                if (! $projectId && hub_can(auth()->user(), 'hr', 'v')
                    && \Illuminate\Support\Facades\Schema::hasTable('attendance')) {
                    $team = \App\Support\Workforce\Workday::teamToday();
                    $missing = (int) ($team['n']['noreport'] ?? 0);
                    if ($missing > 0) {
                        $add($missing >= 5 ? 'مهم' : 'اطّلاع', '📝', $missing . ' تقريرٌ يوميٌّ ناقصٌ اليوم',
                            'موظفون حاضرون اليوم بلا تقريرِ عمل (تقريرٌ ناقصٌ ليس غياباً). تابع مع فريقك.',
                            route('workforce.team'), 'افتح فريقي اليوم',
                            // **تصرّفٌ لكلِّ مستخدمٍ على حِدة**: هذه إشارةٌ تجميعيّةٌ (لا سجلٌّ
                            // واحد) يراها كلُّ مديري HR؛ فبمفتاحٍ مشترَكٍ كان تأجيلُ أحدهم
                            // يُخفيها عن البقية. المستخدمُ جزءٌ من المفتاح فيستقلّ تصرّفُه.
                            'report.missing:' . now()->toDateString() . ':u' . (auth()->id() ?? '0'), 'attend', null);
                    }
                }
            } catch (\Throwable $e) {}

            // ١١) حواجبُ مبلَّغة: مشاريعُ فيها تقاريرُ عملٍ حديثةٌ ذاتُ «مشكلات» — **لا
            // كيانَ حاجبٍ جديد**: يُقرأ من `work_updates.problems` (نفسُ ما يعدّه
            // `teamCalc`)، منطَّقٌ بالمشروع عبر hub_scope، وعمرُ الحاجب من أقدمِ بلاغ.
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('work_updates')) {
                    $projIds = hub_scope(\Illuminate\Support\Facades\DB::table('projects')->whereNull('deleted_at'), 'projects')
                        ->when($projectId, fn ($q) => $q->where('id', $projectId))->pluck('id');
                    if ($projIds->isNotEmpty()) {
                        $rows = \Illuminate\Support\Facades\DB::table('work_updates')->whereNull('deleted_at')
                            ->whereIn('project_id', $projIds->all())
                            ->whereNotNull('problems')->whereRaw("TRIM(problems) <> ''")
                            ->where('work_date', '>=', now()->subDays(14)->toDateString())
                            ->select('project_id', \Illuminate\Support\Facades\DB::raw('COUNT(*) as c'),
                                \Illuminate\Support\Facades\DB::raw('MIN(work_date) as firstd'))
                            ->groupBy('project_id')->orderByDesc('c')->orderBy('project_id')->limit(6)->get();
                        $names = hub_ref_labels('projects', $rows->pluck('project_id')->all());
                        foreach ($rows as $r) {
                            $age = (int) \Illuminate\Support\Carbon::parse($r->firstd)->diffInDays(now());
                            $add($r->c >= 3 ? 'حرج' : 'مهم', '🚧', 'حواجبُ مبلَّغة: ' . ($names[$r->project_id] ?? '—'),
                                $r->c . ' تقريرُ عملٍ يذكر مشكلةً/حاجباً، أقدمُها منذ ' . $age . ' يوماً. راجع المعوّقات مع الفريق.',
                                route('m.show', ['projects', $r->project_id]), 'افتح المشروع',
                                'proj.blockers:' . $r->project_id, 'projects', $r->project_id);
                        }
                    }
                }
            } catch (\Throwable $e) {}

            // ١٢) خرقُ SLA: تذاكرُ دعمٍ مفتوحةٌ تجاوزت موعدَ حلّها وفق قواعد `hub_sla`
            // القائمة — **لا محرّكَ SLA ثانٍ**: نفسُ الحاسبة (created_at + الأولوية + أوّلُ
            // ردٍّ غيرِ داخليّ + حالةُ الإغلاق)، منطَّقةٌ بـhub_scope ومحروسةٌ بصلاحية الرؤية.
            // تُحَلّ تلقائياً بحلِّ التذكرة (لا تعود resLate). لا مؤقّتاتٍ جديدة: تُشتقّ من القائم.
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('tickets') && hub_can(auth()->user(), 'tickets', 'v')) {
                    $open = hub_scope(\Illuminate\Support\Facades\DB::table('tickets')->whereNull('deleted_at'), 'tickets')
                        ->whereNotIn('status', ['تم الحل', 'مغلقة'])
                        ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
                        // الأقدمُ إنشاءً أولاً (أرجحُ للخرق)، والمعرّفُ فاصلٌ حتميّ
                        ->orderBy('created_at')->orderBy('id')->limit(80)
                        ->get(['id', 'subject', 'priority', 'status', 'meta', 'created_at', 'updated_at']);
                    if ($open->isNotEmpty()) {
                        // أوّلُ ردٍّ غيرِ داخليٍّ لكلِّ تذكرةٍ دفعةً واحدة — لا N+1 داخل hub_sla
                        $firsts = \App\Models\Comment::where('module', 'tickets')
                            ->whereIn('record_id', $open->pluck('id')->all())
                            ->where(fn ($q) => $q->where('internal', false)->orWhereNull('internal'))
                            ->select('record_id', \Illuminate\Support\Facades\DB::raw('MIN(created_at) as m'))
                            ->groupBy('record_id')->pluck('m', 'record_id');
                        $breached = [];
                        foreach ($open as $t) {
                            $s = hub_sla($t, $firsts[$t->id] ?? null);
                            if (! ($s['resLate'] ?? false)) continue;   // لم يتجاوز موعدَ الحلّ بعد
                            $over = (int) \Illuminate\Support\Carbon::parse($s['resDue'])->diffInDays(now());
                            $breached[] = ['t' => $t, 'over' => $over, 'noresp' => (bool) ($s['respPending'] ?? false)];
                        }
                        usort($breached, fn ($a, $b) => [$b['over'], $a['t']->id] <=> [$a['over'], $b['t']->id]);
                        foreach (array_slice($breached, 0, 6) as $b) {
                            $add($b['over'] > 3 ? 'حرج' : 'مهم', '⏱️', 'خرقُ SLA: ' . ($b['t']->subject ?: 'تذكرة'),
                                'تجاوزت موعدَ الحلّ بـ' . $b['over'] . ' يوماً' . ($b['noresp'] ? ' وبلا ردٍّ أوّلَ بعد' : '') . '. عالِجها أو صعّدها.',
                                route('m.show', ['tickets', $b['t']->id]), 'افتح التذكرة',
                                'sla.breach:' . $b['t']->id, 'tickets', $b['t']->id);
                        }
                    }
                }
            } catch (\Throwable $e) {}

            // ١٣) انحرافُ النطاق: مشروعٌ وُلد من عرضٍ مقبول (خطُّ أساسٍ محفوظ) تجاوزت
            // أوامرُ التغيير المطبَّقةُ عليه نسبةً جوهريّةً من قيمته الأصلية. يُقرأ من
            // `meta.baseline` القائم (الأصل + value_delta لكلِّ أمرٍ مطبَّق، يُحدَّث
            // معاملاتيّاً عند التطبيق في ChangeOrderController) — منطَّقٌ بـhub_scope.
            // نسبةٌ لا مبلغَ كلفةٍ داخليّ؛ القيمةُ التعاقدية إيرادٌ تراه لوحاتُ الرصد.
            try {
                $rows = hub_scope(\Illuminate\Support\Facades\DB::table('projects')->whereNull('deleted_at'), 'projects')
                    ->where(fn ($w) => $w->whereNull('status')->orWhereNotIn('status', hub_closed_states()))
                    ->when($projectId, fn ($q) => $q->where('id', $projectId))
                    ->orderBy('id')->limit(80)->get(['id', 'name', 'meta']);
                $drift = [];
                foreach ($rows as $p) {
                    $meta = (array) (is_array($p->meta) ? $p->meta : (json_decode((string) $p->meta, true) ?: []));
                    $bl = $meta['baseline'] ?? null;
                    $orig = (float) ($bl['amount'] ?? 0);
                    if (! $bl || $orig <= 0) continue;   // لا خطَّ أساسٍ → لا مرجعَ للانحراف
                    $cos = (array) ($bl['change_orders'] ?? []);
                    $sum = 0.0;
                    foreach ($cos as $co) $sum += (float) ($co['value_delta'] ?? 0);
                    if ($sum == 0.0) continue;
                    $pct = abs($sum) / $orig * 100;
                    if ($pct < 25) continue;   // عتبةُ الانحراف الجوهريّ
                    $drift[] = ['p' => $p, 'pct' => $pct, 'n' => count($cos)];
                }
                usort($drift, fn ($a, $b) => [$b['pct'], $a['p']->id] <=> [$a['pct'], $b['p']->id]);
                foreach (array_slice($drift, 0, 6) as $d) {
                    $add($d['pct'] >= 50 ? 'حرج' : 'مهم', '📐', 'انحرافُ نطاق: ' . $d['p']->name,
                        'أوامرُ التغيير المطبَّقة (' . $d['n'] . ') غيّرت القيمةَ التعاقدية بنسبة '
                        . number_format($d['pct'], 0) . '٪ عن خطِّ الأساس. راجع النطاقَ والتسعير.',
                        route('m.show', ['projects', $d['p']->id]), 'افتح المشروع',
                        'scope.drift:' . $d['p']->id, 'projects', $d['p']->id);
                }
            } catch (\Throwable $e) {}

            // ١٤) تدهورُ الهامش زمنيّاً: هامشُ المشروع اليومَ مقابلَ أوّلِ لقطةٍ له في
            // آخر ٣٠ يوماً — من سلسلة `metric_points` (projects/pl_margin) التي يكتبها
            // `hub:automation` يوميّاً. نقطتان على يومين مختلفين على الأقلّ، وإلا فلا
            // إشارة — لا تُختلق سلسلةٌ من نقطةٍ واحدة. العتبةُ: هبوطٌ ≥ ١٠ نقاطٍ مئوية
            // «مهم»، و≥ ٢٠ نقطةً أو انقلابُ الهامش إلى الخسارة «حرج». هامشٌ داخليٌّ
            // بحت: يبقى خلف `hub_org_analytics_guard` وصلاحيةِ المالية كسائر الكلفة.
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('metric_points') && hub_can(auth()->user(), 'fin', 'v')) {
                    $rows = hub_scope(\Illuminate\Support\Facades\DB::table('projects')->whereNull('deleted_at'), 'projects')
                        ->where(fn ($w) => $w->whereNull('status')->orWhereNotIn('status', hub_closed_states()))
                        ->when($projectId, fn ($q) => $q->where('id', $projectId))
                        ->orderBy('id')->limit(200)->get(['id', 'name'])->keyBy('id');
                    $pts = $rows->isEmpty() ? collect() : \Illuminate\Support\Facades\DB::table('metric_points')
                        ->where('module', 'projects')->where('metric', 'pl_margin')
                        ->whereIn('record_id', $rows->keys()->all())
                        ->where('at', '>=', now()->subDays(30)->startOfDay()->toDateTimeString())
                        ->orderBy('at')->orderBy('id')->get(['record_id', 'value', 'at']);
                    $series = [];
                    foreach ($pts as $pt) $series[(string) $pt->record_id][] = $pt;
                    $decl = [];
                    foreach ($series as $pid => $s) {
                        if (count($s) < 2) continue;
                        $first = $s[0]; $last = end($s);
                        if (\Illuminate\Support\Carbon::parse($first->at)->isSameDay(\Illuminate\Support\Carbon::parse($last->at))) continue;
                        $from = (float) $first->value; $to = (float) $last->value;
                        $drop = $from - $to;
                        $flip = $from >= 0 && $to < 0;   // انقلابٌ إلى الخسارة: حرجٌ مهما صغُر الهبوط
                        if ($drop < 10 && ! $flip) continue;
                        $decl[] = ['p' => $rows[$pid], 'from' => $from, 'to' => $to, 'drop' => $drop, 'flip' => $flip,
                                   'days' => (int) \Illuminate\Support\Carbon::parse($first->at)->diffInDays(\Illuminate\Support\Carbon::parse($last->at))];
                    }
                    usort($decl, fn ($a, $b) => [$b['drop'], $a['p']->id] <=> [$a['drop'], $b['p']->id]);
                    foreach (array_slice($decl, 0, 6) as $d) {
                        $add(($d['drop'] >= 20 || $d['flip']) ? 'حرج' : 'مهم', '📉',
                            'تدهورُ هامش: ' . $d['p']->name,
                            'هبط الهامشُ من ' . number_format($d['from'], 1) . '٪ إلى ' . number_format($d['to'], 1)
                            . '٪ خلال ' . $d['days'] . ' يوماً (−' . number_format($d['drop'], 1) . ' نقطة). راجع الكلفةَ والفوترة.',
                            route('m.show', ['projects', $d['p']->id]), 'افتح المشروع',
                            'margin.decline:' . $d['p']->id, 'projects', $d['p']->id);
                    }
                }
            } catch (\Throwable $e) {}

            // ١٥) معلمُ دفعٍ بُلغ ولم يُفوتَر (v2.399): دفعةٌ في جدول مدفوعات عرضٍ
            // مقبولٍ/محوَّلٍ أُعلن بلوغُها (`quote_milestones.reached_at` — فعلٌ بشريٌّ
            // مسجَّل) منذ ٣ أيامٍ فأكثر ولا فاتورةَ حيّةً لها (`invoice_id` غائبٌ أو
            // فاتورتُه محذوفةٌ/ملغاة). «حرج» بعد ٧ أيام. تنطفئ وحدها بسكّ الفاتورة،
            // وتعود إن أُلغيت. القيمةُ إيرادٌ تعاقديٌّ (لا كلفة) بقاعدة الشاشة نفسها.
            // منطَّقةٌ بعروضها (hub_scope quotes) خلف صلاحيةِ رؤية العروض.
            //
            // v2.399.1: الاستبعادُ في SQL قبل السقف لا بعده — فأربعون معلماً مفوتَراً
            // أقدمَ لا تحجب معلماً مكشوفاً أحدث؛ والعروضُ المنطَّقةُ استعلامٌ فرعيٌّ بلا
            // سقف ٢٠٠؛ وعدسةُ المشروع ترى العرضَ المحوَّل (`meta.project_id`) كما ترى
            // عمودَ `project_id`؛ والمعلمُ الصفريّ (لا مبلغَ ولا نسبة) لا يُشار إليه
            // لأنّ فاتورتَه لا تُسكّ أصلاً؛ وكذا معلمُ عرضٍ استوفت فواتيرُ دفعاتِه الحيّةُ
            // إجماليَّه (سقفُ العقد) — سكُّه مرفوضٌ فلا تُطلَب.
            //
            // والاستبعاداتُ التي لا تُكتَب شرطَ SQL مباشراً (مسارُ JSON لا يُقارَن بعمودٍ
            // لتباين المحرّكين؛ ومجموعُ الفواتير الحيّة بقاعدة PHP) لا تُترَك لما بعد الجلب
            // — فالمستبعَدُ يتراكم في أقدم الصفوف ولا يُصنَّف أبداً، حتى يستنفد صفحاتِ
            // المسح ويُحجَب معلمٌ أحدثُ سكُّه يُنجَز. تُحسَب مرّةً على مرشَّحي المسح كلِّهم
            // ثم تُغلَق بـ`whereNotIn` (أعمدةٌ مقابلَ قيمٍ مربوطة — واحدةٌ على المحرّكين)،
            // فلا يُنفِق مستبعَدٌ شيئاً من الميزانيّة. وفحصُ PHP بعد الجلب يبقى حزاماً.
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('quote_milestones')
                    && \Illuminate\Support\Facades\Schema::hasColumn('quote_milestones', 'reached_at')
                    && hub_can(auth()->user(), 'quotes', 'v')) {
                    $dead = (array) config('hub.fin.dead', []);
                    $qSub = hub_scope(\App\Models\Quote::query(), 'quotes')
                        ->whereIn('status', ['مقبول', 'محوّل'])
                        // عرضٌ لم يُسعَّر (إجماليُّه صفر): سقفُه صفرٌ فلا سكَّ (msInvoice) ولا طلبَ سكّ
                        ->where('total', '>', 0)
                        ->when($projectId, fn ($q) => $q->where(fn ($w) => $w->where('project_id', $projectId)
                            ->orWhere('meta->project_id', $projectId)))
                        ->select('id');
                    $msQ = \Illuminate\Support\Facades\DB::table('quote_milestones')
                        ->whereNull('deleted_at')->whereIn('quote_id', $qSub)
                        ->whereNotNull('reached_at')
                        ->where('reached_at', '<=', now()->subDays(3)->toDateTimeString())
                        ->where(fn ($w) => $w->where('amount', '>', 0)->orWhere('pct', '>', 0))
                        // فاتورةُ المعلم الحيّةُ (بتعريف FinDocument::isLive) تُستبعَد هنا لا في PHP
                        ->where(fn ($w) => $w->whereNull('invoice_id')
                            ->orWhereNotExists(fn ($s) => $s->select(\Illuminate\Support\Facades\DB::raw(1))->from('fin_documents')
                                ->whereColumn('fin_documents.id', 'quote_milestones.invoice_id')
                                ->whereNull('fin_documents.deleted_at')
                                ->when($dead, fn ($x) => $x->where(fn ($y) => $y->whereNull('fin_documents.state')
                                    ->orWhereNotIn('fin_documents.state', $dead)))))
                        // الأقدمُ بلوغاً أولاً، وid فاصلٌ حتميّ
                        ->orderBy('reached_at')->orderBy('id');
                    $isLive = fn ($x) => $x->whereNull('deleted_at')
                        ->when($dead, fn ($y) => $y->where(fn ($w) => $w->whereNull('state')->orWhereNotIn('state', $dead)));
                    // عروضُ المرشَّحين كلُّها مرّةً (لا لكلّ صفحة) — منها تُحسَب استبعاداتُ العرض:
                    $qs = \App\Models\Quote::query()->whereIn('id', (clone $msQ)->reorder()->distinct()->select('quote_id'))
                        ->get(['id', 'doc_no', 'title', 'total', 'meta'])->keyBy('id');
                    // (أ) عرضٌ مفوتَرٌ كاملاً بفاتورةٍ حيّة (meta.invoice_id من do=invoice): إيرادُه
                    //     مُطالَبٌ به كلُّه، فمعالمُه ليست «بلا فاتورة» (لا إشارةَ كاذبة ولا ازدواجَ فوترة)
                    $fullInv = $qs->map(fn ($q) => ((array) $q->meta)['invoice_id'] ?? null)
                        ->filter(fn ($v) => is_string($v) && $v !== '');
                    $live = $fullInv->isEmpty() ? [] : $isLive(\Illuminate\Support\Facades\DB::table('fin_documents')
                        ->whereIn('id', $fullInv->unique()->values()->all()))->pluck('id')->flip()->all();
                    // (ب) سقفُ العقد: عرضٌ غطّت فواتيرُ دفعاتِه الحيّةُ إجماليَّه لا يُطالَب بدفعةٍ
                    //     أخرى — فالسكُّ مرفوضٌ هناك (msInvoice) ولا تُشار إليه إشارةٌ لا تُنجَز
                    $billed = $qs->isEmpty() ? [] : \App\Models\Quote::liveMilestoneInvoicedTotals($qs->keys()->all());
                    $skipQ = $qs->filter(fn ($q) => (($f = $fullInv[$q->id] ?? null) && isset($live[$f]))
                        || round((float) $q->total - ($billed[$q->id] ?? 0.0), 3) <= 0)->keys()->all();
                    if ($skipQ) $msQ->whereNotIn('quote_id', $skipQ);
                    // (ج) معلمٌ عادت سابقتُه إلى الحياة (`meta.prev_invoices`: أُلغيت فسُكّ بدلُها ثم
                    //     أُرجعت من الماليّة): مفوتَرٌ بها وإن مات `invoice_id` — السكُّ يُردّ إليها
                    //     (msInvoice) فلا تُطلَب. `meta` لا يُكتَب على المعلم إلا لهذا، فمرشَّحوه قلّة.
                    //     (invoiceIdsOf تشمل `invoice_id` نفسَه — ميتٌ بشرط SQL، ففحصُه ثانيةً لا يضرّ)
                    $withPrev = (clone $msQ)->reorder()->whereNotNull('meta')->get(['id', 'invoice_id', 'meta'])
                        ->mapWithKeys(fn ($m) => [$m->id => \App\Models\QuoteMilestone::invoiceIdsOf($m->invoice_id, $m->meta)]);
                    $prevIds = $withPrev->flatten()->unique()->values()->all();
                    $prevLive = ! $prevIds ? [] : $isLive(\Illuminate\Support\Facades\DB::table('fin_documents')
                        ->whereIn('id', $prevIds))->pluck('id')->flip()->all();
                    $skipMs = $withPrev->filter(fn ($ids) => (bool) array_intersect($ids, array_keys($prevLive)))->keys()->all();
                    if ($skipMs) $msQ->whereNotIn('id', $skipMs);
                    // ما بقي: معالمُ مكشوفةٌ فعلاً — تُقرأ صفحاتٍ (٤٠ × ٥) حتى تكتمل ثمانُ
                    // إشاراتٍ أو ينفد المرشَّحون. (لا يستهلك الميزانيّةَ الآن إلا ما يُشار إليه —
                    // عدا نسبةٍ ضئيلةٍ تُقرَّب قيمتُها إلى صفر، حزامٌ في PHP كبقيّة الفحوص)
                    $n = 0;
                    for ($page = 0; $page < 5 && $n < 8; $page++) {
                        $ms = (clone $msQ)->offset($page * 40)->limit(40)
                            ->get(['id', 'quote_id', 'title', 'pct', 'amount', 'reached_at', 'invoice_id', 'meta']);
                        if ($ms->isEmpty()) break;
                        foreach ($ms as $m) {
                            $q = $qs[$m->quote_id] ?? null;
                            if (! $q) continue;
                            $full = $fullInv[$m->quote_id] ?? null;
                            if ($full && isset($live[$full])) continue;                 // عرضُه مفوتَرٌ كاملاً
                            foreach (\App\Models\QuoteMilestone::invoiceIdsOf($m->invoice_id, $m->meta) as $pid) {
                                if (isset($prevLive[$pid])) continue 2;                  // سابقتُه عادت حيّة
                            }
                            if (round((float) $q->total - ($billed[$q->id] ?? 0.0), 3) <= 0) continue; // لا متبقّيَ من الإجماليّ
                            // القيمةُ بقاعدة `amountDue` نفسِها (مقرَّبةً إلى ثلاث منازل) — فما يرفضه
                            // المتحكّم صفراً لا يُطلَب هنا
                            $amt = round((float) $m->amount > 0 ? (float) $m->amount
                                : ((float) $m->pct > 0 ? (float) $q->total * (float) $m->pct / 100 : 0.0), 3);
                            if ($amt <= 0) continue;                                    // بلا قيمةٍ تُسكّ
                            if ($n++ >= 8) break 2;
                            $days = (int) \Illuminate\Support\Carbon::parse($m->reached_at)->diffInDays(now());
                            $add($days >= 7 ? 'حرج' : 'مهم', '🧾',
                                'معلمُ دفعٍ بلا فاتورة: ' . $m->title . ' — ' . ($q->title ?: $q->doc_no),
                                'أُعلن بلوغُه منذ ' . $days . ' يوماً بقيمة ' . number_format($amt, 3)
                                . ' ولم تُسكّ فاتورتُه' . ($m->invoice_id ? ' (فاتورتُه السابقة أُلغيت أو حُذفت)' : '') . '. الإيرادُ المستحقّ لا يُحصَّل بلا فاتورة.',
                                route('m.show', ['quotes', $q->id]), 'سُكّ الفاتورة',
                                'milestone.uninvoiced:' . $m->id, 'quotes', $q->id);
                        }
                    }
                }
            } catch (\Throwable $e) {}

            // الترتيب: الأشد أولاً، وضمن الدرجة يبقى ترتيب الاكتشاف
            usort($out, fn ($a, $b) => ($rank[$b['sev']] ?? 0) <=> ($rank[$a['sev']] ?? 0));

            return [
                'items'  => $out,
                'counts' => [
                    'حرج'    => count(array_filter($out, fn ($r) => $r['sev'] === 'حرج')),
                    'مهم'    => count(array_filter($out, fn ($r) => $r['sev'] === 'مهم')),
                    'اطّلاع' => count(array_filter($out, fn ($r) => $r['sev'] === 'اطّلاع')),
                ],
            ];
        });
    }
}
