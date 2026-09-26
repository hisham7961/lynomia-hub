<?php

namespace App\Support\Insights;

use Illuminate\Support\Facades\Cache;

/**
 * محرّكاتٌ نُقلت من `helpers.php` بلا تغيير (docs/REORG_PLAN.md §R5) — والدوالُّ العامّةُ بأسمائها
 * باقيةٌ هناك أغلفةً من سطرٍ واحد، فلا Blade ولا متحكّمَ يتغيّر.
 */
final class ExpiryRadar
{
    /** رادار الانتهاءات: كل ما ينتهي خلال نافذة `hub_radar_window()` أو انتهى فعلاً — مخبأ، ومحدود بنطاق المستخدم */
    public static function scan(bool $fresh = false, $user = null): array
    {
        $user   = $user ?? auth()->user();
        // **صفوفُ صاحبِ الشأنِ تُضمّ بعد التنطيق** (PROD-05) — تُحسب خارجَ المخبأ
        // لأنّها تخصّ مستخدماً بعينِه لا دوراً، ويُمنع تكرارُ ما رآه بالنطاق أصلاً
        $mine   = hub_expiry_self($user, $fresh);
        /*
         * **التنطيقُ يُطبَّق دائماً — لا بظنٍّ عمّن هو مقيَّد** (v2.556).
         *
         * كان الشرطُ يعدّ ثلاثَ آليّاتِ تضييق (مشروعٌ · شركةٌ · عميل) ثمّ يتخطّى
         * `hub_scope` كلَّها إن لم تنطبق. و`hub_scope` تُنفّذ **أكثرَ منها**:
         * منها حجبُ وثيقةِ «سري» عمّن لا يحمل `files:docsec` ولم يرفعها
         * (`helpers.php:278`). فمستخدمٌ غيرُ منطَّقٍ بمشروعٍ ولا مقيَّدٍ بشركةٍ
         * ولا بعميلٍ كان يُحسَب «غيرَ مقيَّد» فيُسرد له في **صفحةِ صباحِه** اسمُ
         * كلِّ وثيقةٍ سرّيّةٍ تنتهي قريباً — وفتحُها يردّ ٤٠٤.
         *
         * وتوثيقُ طبقةِ العزلِ نفسِها يقول الغاية: «الحجبُ في طبقة العزل فيسري
         * على **كلِّ بابِ قراءة**». والرادارُ بابُ قراءةٍ لم يسرِ عليه.
         *
         * و`hub_scope` **لا تضيّق من لا يضيّقه** (المالكُ يعود قبل النطاق
         * أصلاً)، فتطبيقُها دائماً لا يُنقص أحداً حقَّه — ويُسقط الظنَّ كلَّه.
         * وبذلك صار المخبأُ **لكلِّ مستخدمٍ على حدة** بالضرورة: مخبأٌ مشتركٌ
         * بالدور كان يُسرّب ما ملأه أحدُهم للآخر.
         */
        // المخبأ يفصل بالدور أيضاً: وثائق الملفات تُرشَّح بصلاحية رؤية وحدتها،
        // فمخبأٌ مشترك بين دورين مختلفين كان سيسرّب ما لا يُرى لأحدهما.
        // و«الجيل» يُبطل المخبأ فور رفع وثيقةٍ مؤرَّخة — لا انتظارَ عشر دقائق.
        $gen    = (int) Cache::get('hub:expiry:gen', 0);
        // **الختم يسبق المهلة**: المفتاح يحمل ختم الجداول المؤرَّخة التي يقرؤها +
        // ختم roles — فتجديدُ عقدٍ أو سحبُ صلاحية عرضٍ يُبطله فوراً لا بعد 10 دقائق.
        $tables = array_values(array_unique(array_filter(array_map(
            fn ($x) => (string) (hub_mod($x[0])['table'] ?? ''), hub_expiry_fields()))));
        $tables[] = 'roles';
        // **والنافذةُ جزءٌ من المفتاح** (N-6): صارت رقماً من الإعدادات، فتغييرُها
        // بلا ختمٍ يُبقي المخبوءَ على النافذةِ السابقةِ حتى تنتهي المهلة — ومن
        // وسّع رادارَه يراه كما كان ويحسب الإعدادَ لا يعمل.
        $key    = ($user ? 'hub:expiry:u:' . $user->id : 'hub:expiry:sys')
                . ':g' . $gen . ':w' . hub_radar_window() . '-' . hub_radar_lookback()
                . hub_data_stamp($tables);
        if ($fresh) \Illuminate\Support\Facades\Cache::forget($key);

        $scan = \Illuminate\Support\Facades\Cache::remember($key, $user ? 300 : 600, function () use ($user) {
            $today = now()->toDateString();
            // **نافذةٌ واحدةٌ لشاشةٍ واحدة** (N-6): كانت `30` هنا و`60` في
            // `hub_doc_expiry` — رقمان في رادارٍ واحد. صارا `hub_radar_window()`.
            $window = hub_radar_window();
            $limit = now()->addDays($window)->toDateString();
            // عتبة التنبيه لكل سجل: حقول «تنبيه قبل (يوم)» كانت تُعرض ولا تُقرأ —
            // وحدةٌ لها عمود عتبة تُجلب بنافذة موسّعة ثم يُرشَّح كل سجل بعتبته هو
            $alertCols = ['domains' => 'alert', 'files' => 'alert', 'subs' => 'alerts'];
            // جلبٌ موسَّعٌ للوحداتِ التي لها عمودُ عتبةٍ خاصّ — ثمّ يُرشَّح كلُّ
            // صفٍّ بعتبتِه هو. ولا يضيقُ الجلبُ عن النافذةِ المعلنة أبداً.
            $wide = now()->addDays(max(120, $window))->toDateString();
            $items = [];
            foreach (hub_expiry_fields() as [$mk, $f]) {
                // **صلاحية الوحدة قبل نطاقها**: الرادار كان يُرشَّح بالنطاق وحده،
                // فيسرد أسماء سجلاتٍ من وحداتٍ لا يملك المستخدم رؤيتها أصلاً —
                // اسمُ العقد وتاريخُه إفشاءٌ ولو لم تُفتح الوحدة.
                // وبلا مستخدم (طرفيةٌ أو مهمةٌ مجدولة) لا ترشيح: السياق نظاميّ،
                // والمستقبِلون يُرشَّحون في موضعهم لا هنا.
                if ($user && ! hub_can($user, $mk, 'v')) continue;
                // **وقناعُ الحقل بعد صلاحية الوحدة** (v2.337): الرادارُ كان
                // يُرشّح بالوحدة وحدها، فمن حُجب عنه «انتهاء الإقامة» في ملفّات
                // الموظفين يقرؤه هنا باسم صاحبه وتاريخِه — في «ينتهي قريباً»
                // وفي مركز التنبيهات. والقناعُ إن سرى في بابٍ وسقط في آخر
                // فليس قناعاً بل ظنُّ ساتر.
                if ($user && hub_field_mode($user, $mk, (string) ($f['key'] ?? '')) === 'hide') continue;
                $md = hub_mod($mk);
                $disp = hub_display_col($mk);
                $acol = $alertCols[$mk] ?? null;
                try {
                    if ($acol && ! \Illuminate\Support\Facades\Schema::hasColumn($md['table'], $acol)) $acol = null;
                    $q = \Illuminate\Support\Facades\DB::table($md['table'])
                        ->whereNull('deleted_at')
                        ->whereNotNull($f['col'])
                        ->whereBetween(\Illuminate\Support\Facades\DB::raw("DATE(`{$f['col']}`)"), [now()->subDays(hub_radar_lookback())->toDateString(), $acol ? $wide : $limit]);
                    // بلا مستخدمٍ لا ترشيح (سياقٌ نظاميّ)؛ ومعه **دائماً** — لا بشرط
                    if ($user) $q = hub_scope($q, $mk, $user);

                    // لا تنبيه على ما أُغلق: مهمة منجزة أو فاتورة مدفوعة أو عقد منتهٍ
                    // كانت تظل تنبّه إلى الأبد فتفقد الصفحة مصداقيتها.
                    // `expiryIgnoresStatus`: وحدةٌ «حالتُها» وصفٌ لا مرحلةٌ في
                    // مسار (حالةُ شهادة الدومين مثلاً) — الإقصاءُ بها يُسقط من
                    // الرادار أشدَّ السجلات حاجةً إليه
                    if (empty($md['expiryIgnoresStatus'])
                        && ($sc = hub_status_col($mk)) && \Illuminate\Support\Facades\Schema::hasColumn($md['table'], $sc)) {
                        $q->where(fn ($w) => $w->whereNull($sc)->orWhereNotIn($sc, hub_closed_states()));
                    }

                    // مستند مالي سُدّد بالكامل لا يستحق تنبيه استحقاق ولو لم تُحدَّث حالته:
                    // السداد المسجَّل هو الحقيقة، لا التسمية.
                    if ($mk === 'fin' && \Illuminate\Support\Facades\Schema::hasColumn($md['table'], 'paid')) {
                        $q->whereRaw('COALESCE(paid,0) < COALESCE(total,0)');
                    }

                    // الترتيب تصاعدياً بالتاريخ قبل الحد: نُبقي الأربعين الأقرب/الأكثر تأخراً
                    // لا أربعين عشوائية بترتيب القاعدة (كان يُسقط أعجل السجلات صمتاً).
                    $cols = ['id', $disp . ' as _n', $f['col'] . ' as _d'];
                    if ($acol) $cols[] = $acol . ' as _a';
                    $rows = $q->orderBy(\Illuminate\Support\Facades\DB::raw("DATE(`{$f['col']}`)"))
                        ->limit(40)->get($cols);
                } catch (\Throwable $e) { continue; }
                foreach ($rows as $row) {
                    $d = substr((string) $row->_d, 0, 10);
                    $days = (int) now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($d)->startOfDay(), false);
                    if ($acol) {
                        // عتبة السجل نفسه: رقم مفرد أو قائمة «90,60,30» تؤخذ أقصاها — والفارغ = 30
                        $nums = array_filter(array_map('intval',
                            preg_split('/[\s,،]+/u', (string) ($row->_a ?? ''), -1, PREG_SPLIT_NO_EMPTY)));
                        // عتبةٌ فارغةٌ ⇒ نافذةُ الرادارِ نفسُها، لا رقمٌ ثالثٌ مدفون
                        if ($days > ($nums ? max($nums) : $window)) continue;
                    }
                    // `fkey` مميّزٌ ثابتٌ للحقل: سجلٌّ بحقلَي تاريخٍ (نهايةٌ وتجديد)
                    // يُنتج إشارتين تتقاسمان module+id — فبلا هذا المميّز تنهار حالتُهما
                    // على مفتاحٍ واحدٍ في مركز الفعل (تأجيلُ إحداهما يُخفي الأخرى).
                    $items[] = ['module' => $mk, 'mlabel' => $md['label'], 'flabel' => $f['label'],
                                'fkey' => (string) ($f['key'] ?? $f['col'] ?? ''),
                                'id' => $row->id, 'name' => (string) $row->_n, 'date' => $d, 'days' => $days];
                }
            }
            // وثائق ملفات الكيانات: شهادةٌ منتهية أخطر من حقلٍ منتهٍ — بها
            // يتوقف تعاملٌ أو يسقط ترخيص. تُضَمّ للرادار نفسه بنطاقها.
            foreach (hub_doc_expiry($user) as $doc) $items[] = $doc;

            usort($items, fn ($a, $b) => $a['days'] <=> $b['days']);
            return array_slice($items, 0, 200);
        });

        /*
         * **ضمُّ صفوفِ صاحبِ الشأن** (PROD-05) بعد المسحِ المنطَّق، مع منعِ
         * التكرار: من يملك `hr:v` رآها في المسحِ أصلاً، فلا تُعرض مرّتين.
         * والمفتاحُ `الوحدة|السجلّ|الحقل` — لا `id` وحدَه، فللسجلِّ الواحدِ
         * حقولُ انتهاءٍ عدّة (إقامةٌ وجوازٌ ونهايةُ خدمة).
         */
        if ($mine) {
            $seen = [];
            foreach ($scan as $r) {
                $seen[($r['module'] ?? '') . '|' . ($r['id'] ?? '') . '|' . ($r['fkey'] ?? '')] = true;
            }
            foreach ($mine as $r) {
                if (isset($seen[$r['module'] . '|' . $r['id'] . '|' . $r['fkey']])) continue;
                $scan[] = $r;
            }
            usort($scan, fn ($a, $b) => $a['days'] <=> $b['days']);
        }

        return $scan;
    }

    /** المسحُ الفعليُّ لصفوفِ صاحبِ الشأن — يُستدعى من خلفِ المخبأ */
    public static function selfScan(array $md, $user): array
    {
        try {
            /*
             * **كلُّ صفوفِه لا أوّلُها** (مجلس الخبراء · F4). كان `->first()` بلا
             * `orderBy` — و`CLAUDE.md` يسمّيه **قرعة**. وأثبت التحقّقُ المستقلُّ
             * أنّها ليست نظريّة: لمن له سجلّان أنذرَ الرادارُ **بالأبعد** وحجب
             * **الأقرب**، بينما تراهما الموارد البشريّة. والقرعةُ تُخفي النقصَ:
             * صفٌّ واحدٌ من اثنين يبدو نجاحاً وهو نصفُ جواب. فتُقرأ صفوفُه كلُّها،
             * مرتّبةً بـ`id` كي لا يبقى للترتيبِ أثرٌ في ما يُعرَض.
             */
            $emps = \Illuminate\Support\Facades\DB::table($md['table'])
                ->whereNull('deleted_at')->where('user_id', $user->id)
                ->orderBy('id')->get();
            if ($emps->isEmpty()) return [];   // حسابُ إدارةٍ أو مالكٍ بلا ملفِّ موظّف
        } catch (\Throwable $e) {
            return [];
        }

        $disp = hub_display_col('hr');
        $out = [];
        foreach ($emps as $emp) {
            foreach (hub_expiry_fields() as [$mk, $f]) {
                if ($mk !== 'hr') continue;
                /*
                 * **والقناعُ يسري هنا كما يسري هناك** (مجلس الخبراء · F3). المسحُ
                 * الرئيسيُّ يستشير `hub_field_mode` ويُعلن في تعليقِه أنّ «القناعَ
                 * إن سرى في بابٍ وسقط في آخر فليس قناعاً بل ظنُّ ساتر» — وكان هذا
                 * البابُ ساقطاً: قناعُ `hide` على «نهاية الخدمة» لا يمنع شيئاً عن
                 * صاحبِه بينما يمنعه عن مديرِ العمليّات. ومن أخفت المنشأةُ عنه حقلاً
                 * عمداً لا يُكشف له من بابٍ خلفيّ.
                 */
                if (hub_field_mode($user, 'hr', (string) ($f['key'] ?? '')) === 'hide') continue;
                $col = $f['col'] ?? '';
                $raw = $col !== '' ? ($emp->{$col} ?? null) : null;
                if (! $raw) continue;

                $d = substr((string) $raw, 0, 10);
                try {
                    $days = (int) now()->startOfDay()
                        ->diffInDays(\Illuminate\Support\Carbon::parse($d)->startOfDay(), false);
                } catch (\Throwable $e) { continue; }
                // النافذةُ نفسُها التي يستعملها الرادار — من تعريفٍ واحدٍ لا من رقمٍ منسوخ (N-6)
                if ($days > hub_radar_window() || $days < -hub_radar_lookback()) continue;

                $out[] = [
                    'module' => 'hr', 'mlabel' => (string) ($md['label'] ?? 'ملفات الموظفين'),
                    'flabel' => (string) ($f['label'] ?? ''), 'fkey' => (string) ($f['key'] ?? ''),
                    'id' => (string) $emp->id, 'name' => (string) ($emp->{$disp} ?? $user->name),
                    'date' => $d, 'days' => $days, 'self' => true,
                ];
            }
        }

        /*
         * **ووثائقُ ملفِّه معه** (مجلس الخبراء · F5). كان الاستثناءُ **نصفَ
         * استثناء**: يقرأ أعمدةَ ملفِّه ولا يضمّ وثائقَه المؤرَّخة، فترى الموارد
         * البشريّةُ على ملفِّه «الهوية / الإقامة» المنتهيةَ ولا يراها هو في أيِّ
         * شاشة — **وهو من يجدّدها**.
         *
         * وقاعدةُ الوثيقةِ تسري كما تسري في `hub_doc_expiry`: وثيقةٌ ممنوعةٌ
         * صراحةً عن القارئِ لا تظهر له ولو كانت على ملفِّه — القرارُ لمن قيّدها.
         */
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('attachments')
                && \Illuminate\Support\Facades\Schema::hasColumn('attachments', 'expires_at')) {
                /*
                 * **النموذجُ كاملاً لا منتقىً** (مجلس الخبراء). كنتُ أنتقي أربعةَ
                 * أعمدةٍ ثمّ أُسلّم النموذجَ الناقصَ إلى `DocumentPolicy` — فتصير
                 * `hub_doc_sensitive('', $kind)` **false** و`hub_mod('')` **null**:
                 * **بوّابتان من خمسٍ تُطفآن صامتتين**. وأثبت التحقّقُ المستقلُّ أنّ
                 * من يملك `hr:v` بلا `docsec` كان يرى «عقد العمل» على ملفِّه —
                 * وهي الوثيقةُ التي تمنعها السياسةُ عنه بعينها. وكلُّ أنواعِ وثائقِ
                 * الموارد البشريّةِ المؤرَّخةِ حسّاسة، فالميزةُ كانت تعمل **بفضلِ
                 * الإطفاء لا رغمَه**. والانتقاءُ لم يكن يوفّر شيئاً يُذكر.
                 *
                 * **والنافذةُ متماثلةٌ مع `hub_doc_expiry`** (`±60`): كانت `+30`
                 * هنا و`+60` هناك، فوثيقةٌ بعد أربعين يوماً **تراها الموارد
                 * البشريّةُ ولا يراها صاحبُها** — وهي الشكوى نفسُها التي وُضع هذا
                 * المسحُ لإغلاقها. والقصُّ النهائيُّ يقع في `hub_expiry` كما لها.
                 */
                $docs = \App\Models\Attachment::whereNull('deleted_at')
                    ->where('module', 'hr')->whereIn('record_id', $emps->pluck('id')->all())
                    ->whereNotNull('expires_at')
                    ->whereBetween('expires_at', [now()->subDays(hub_radar_lookback())->toDateString(),
                                                  now()->addDays(hub_radar_window())->toDateString()])
                    ->orderBy('expires_at')->orderBy('id')->limit(40)->get();

                if ($docs->isNotEmpty()) {
                    \App\Support\Documents\DocumentPolicy::primeMemo($docs->pluck('id'));
                    $names = $emps->pluck($disp, 'id');
                    foreach ($docs as $a) {
                        /*
                         * **قرارٌ صريحٌ يُعلَن لا يُستدرَج** (مجلس الخبراء).
                         *
                         * كلُّ أنواعِ وثائقِ الموارد البشريّةِ المؤرَّخةِ موسومةٌ
                         * `sec => true`، فبوّابةُ `docsec` تحجب عن **صاحبِ الشأنِ
                         * نفسِه** إقامتَه وجوازَه وعقدَه. والمبدأُ الذي قام عليه
                         * هذا المسحُ كلُّه: **«إقامتُها ليست سرّاً عنها»** —
                         * ووثيقةُ إقامتِها كذلك، وهو من يُطالَب بتجديدها.
                         *
                         * فيُستثنى **صاحبُ الشأنِ وحدَه** من بوّابةِ الحساسيّة، وفي
                         * أضيقِ حدّ:
                         *   • سجلُّه هو (الاستعلامُ محصورٌ بـ`user_id` أصلاً)،
                         *   • **وجودُ الوثيقةِ وتاريخُها ونوعُها** لا محتواها —
                         *     و`att.view`/`att.dl` تبقى محكومةً بالسياسةِ كاملةً
                         *     فتردّ 403 كما هي،
                         *   • **والمنعُ الصريحُ يعلو** (قاعدةٌ على المستخدمِ أو
                         *     دورِه): منشأةٌ قيّدت وثيقةً عن شخصٍ بعينِه قرارُها
                         *     مُحترَم، ولا يُنقض باستثناءٍ عامّ.
                         *
                         * وما قبلَ هذا لم يكن قراراً بل **عَرَضاً**: نموذجٌ ناقصُ
                         * عمودَين أطفأ البوّابتين صامتاً، فمرّ كلُّ شيءٍ بلا تمييز.
                         */
                        /*
                         * **والقاعدةُ من تعريفِها الوحيد** (N-5): كان هذا المنطقُ
                         * مكتوباً هنا بالكامل، ثمّ احتاجته بوّابةُ الموظّفِ نفسُها —
                         * فاستُخرج إلى `DocumentPolicy::subjectMayAny` بدل أن يُنسَخ.
                         * ونسختان لقاعدةٍ واحدةٍ تصيران تعريفَين: الرادارُ يعرض
                         * وثيقةً والبوّابةُ تردّها، أو العكس — وكلٌّ منهما صادقٌ
                         * داخليّاً. وذلك أصلُ ما يلاحقه هذا المجلس.
                         */
                        if (! \App\Support\Documents\DocumentPolicy::subjectMayAny($user, $a)) continue;
                        $out[] = [
                            'module' => 'hr', 'mlabel' => (string) ($md['label'] ?? 'ملفات الموظفين'),
                            'flabel' => hub_doc_label('hr', $a->kind) ?? 'وثيقة',
                            'fkey' => 'doc:' . (string) $a->kind,
                            'id' => (string) $a->record_id,
                            'name' => (string) ($names[$a->record_id] ?? $user->name),
                            'date' => $a->expires_at->toDateString(), 'doc' => true, 'self' => true,
                            'days' => (int) now()->startOfDay()
                                ->diffInDays($a->expires_at->copy()->startOfDay(), false),
                        ];
                    }
                }
            }
        } catch (\Throwable $e) { /* الوثائقُ إضافةٌ — لا تُسقط مسحَ الأعمدة */ }

        return $out;
    }
}
