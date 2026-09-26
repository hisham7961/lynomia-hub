<?php

namespace App\Support\Platform\Modules;

/**
 * طرائقُ نُقلت من `ModuleController` بلا تغيير (docs/REORG_PLAN.md §R6) — والمتحكّمُ يفوّض
 * إليها بالتوقيعِ والظهورِ نفسَيهما، فالورثةُ (`V1Controller` · `ApprovalDecisionController` · `MobileWorkController`) لا يتغيّرون.
 */
final class ModuleExport
{
    /**
     * حزامُ أمان التصدير — بابٌ **واحد** لكل مسار يبثّ CSV (تصدير القائمة
     * و«تصدير المحدد» الجماعي). كان الحزامُ على `export()` وحده بينما
     * `bulk(do=export)` يبثّ بلا تجميدٍ ولا عتبةٍ ولا وسم — والحارسُ الذي
     * يُطبَّق في بابٍ ويُنسى في آخر ليس حارساً بل قناعةٌ كاذبة:
     *
     *   · **صلاحيةُ المصدِّر** (٤٠٣) — علم `exp`.
     *   · **مفتاحُ طوارئٍ مفصول** (٤٢٣): تجميدُ التصدير يصدّ سحبَ البيانات
     *     الجماعيّ لحظةَ الاشتباه — حتى للمالك، فالتجميدُ يُرفع من مركز
     *     الأمان لا بتصدير. (مطفأٌ افتراضاً فلا يمسّ العملَ العاديّ.)
     *   · **تصديرٌ كبير** = نقلُ بياناتٍ جماعيّ: فوق العتبة
     *     (security.export_stepup_rows، مطفأةٌ افتراضاً بـ0) يتطلب تأكيدَ
     *     الهوية، ويُوسَم الحدثُ «تصدير كبير» في التدقيق ليُرصد في مركز الأمن.
     *
     * يعيد استجابةَ التصعيد إن لزمت، وإلا `null` بعد كتابة بصمة التدقيق —
     * فلا بايتَ CSV قبل اجتياز الحزام كلِّه.
     */
    public static function exportBelt(string $module, int $count, string $unitLabel, array $def = [])
    {
        // تصديرُ هذه الوحدةِ الدقيق (<module>.export) أو رايةُ التصديرِ الجامعة (توافقٌ خلفيّ)
        abort_unless(hub_can(auth()->user(), $module, 'export') || hub_exporter(), 403, 'التصدير يتطلب صلاحية');
        abort_if((string) setting('security.freeze_exports', '0') === '1', 423,
            'التصدير مجمَّدٌ الآن بمفتاح طوارئٍ أمنيّ — يُرفع من مركز الأمان');

        /*
         * (الجولة 1 · F17) **قيدُ «خارج وقت العمل» على التصدير — في الحزام الواحد**:
         * حظرُ نقل الملفات الليليّ (WorkHours) كان بلا استثناءٍ فإقفالُ الشهر ليلاً
         * مستحيل، وكان «تصديرُ المحدد» الجماعيّ (`m.bulk`) يفلت منه أصلاً لأن مسارَه
         * ليس في قائمة FILE_ROUTES. هنا — البابُ الواحدُ لكل بثّ CSV — يُفرَض القيدُ
         * على المسارين معاً، وحاملُ المفتاح الدقيق `exportNight` وحده يُستثنى ويُوسَم
         * كلُّ استعمالٍ له في التدقيق. (استثناءُ مسار `m.export` من حظرِ الوسيط نفسِه
         * يحتاج سطرَ إعفاءٍ في Middleware/WorkHours — خارجَ ملفّات هذه الدفعة.)
         */
        $night = \App\Support\Platform\Modules\ModuleExport::exportOutsideWorkHours();
        if (hub_export_blocked_now($module)) {
            abort(403, 'نقل الملفات ممنوع خارج وقت العمل — يعود متاحاً مع بداية الدوام،'
                . ' أو يُمنح دورُك مفتاحَ «تصدير خارج الدوام» (exportNight) لإقفالٍ ليليٍّ مشروع');
        }

        $bigAt = (int) setting('security.export_stepup_rows', 0);
        $isBig = $bigAt > 0 && $count >= $bigAt;

        // (Work OS · الطور G · §22) تصديرُ ICCID الجماعيّ = سحبُ هويّاتِ شرائحَ خام —
        // خطرُ انتحالِ/استبدالِ SIM — فيتطلب تأكيدَ الهوية بمعزلٍ عن عتبةِ الحجم
        // العامّة، ومنطَّقاً بالعمود لا شاملاً (تصديرٌ بلا عمود ICCID لا يُعطَّل).
        $iccidBulk = $module === 'phones' && $count > 0 && \App\Support\Platform\Modules\ModuleExport::exportColumnsInclude($def, 'iccid');

        if (($isBig || $iccidBulk) && ($resp = hub_require_stepup())) return $resp;

        // بصمة التصدير في التدقيق — تُعرض في مركز الأمان (ICCID الجماعيّ موسومٌ بذاته،
        // واستعمالُ استثناء exportNight موسومٌ «خارج الدوام» فيُرصد كلُّ إقفالٍ ليليّ)
        $label = $iccidBulk ? 'تصدير ICCID جماعي' : ($isBig ? 'تصدير كبير' : 'تصدير');
        /*
         * **الوسمُ الزمنيُّ مستقلٌّ عن الاستثناء** (مجلس الخبراء · N-7).
         *
         * كان «خارج الدوام» يُكتب **حين يُستعمل استثناءُ `exportNight`** وحدَه —
         * والمالكُ مستثنًى بالتصميم فلا استثناءَ يُوسَم. فكشفُ الرواتبِ سُحب
         * الثالثةَ وإحدى وأربعين فجراً وسُجّل «تصدير» عارياً (`audits#3505`).
         * والسؤالان مختلفان: «أاستُعمل مفتاحٌ استثنائيّ؟» و«متى سُحب الملفّ؟».
         * فالثاني يُجاب دائماً — ومن يراجع التدقيقَ يرى الساعةَ لا يستنتجها.
         */
        if ($night) $label .= ' خارج الدوام (exportNight)';
        hub_audit($label, $module, null, $count . ' ' . $unitLabel,
            // **الوسمُ الزمنيُّ في الأثرِ لا في الاسم** (N-7): «متى سُحب الملفّ؟» سؤالٌ
            // مستقلٌّ عن «أاستُعمل مفتاحٌ استثنائيّ؟» — والمالكُ مستثنًى بالتصميم فلا
            // يُوسَم سحبُه فجراً أبداً. ووضعُه في **الاسم** يجعل فعلَ التدقيق متغيّراً
            // بالساعة (أسقطت الحزمةُ ذلك عند ١٩:٥٠) — فالحقيقةُ حقلٌ لا لفظ.
            // // **والأثرُ يُكتب في عمودٍ موجود** (N-14 · ما كشفه إغلاقُه): `hub_audit`
            // يمرّر `$extra` إلى `AuditEntry`، و`creating` **يُجرّد كلَّ مفتاحٍ لا
            // عمودَ له** صامتاً (درعُ «النشر قبل الترحيل»). فمفتاحا `after_hours`
            // و`at` المسطَّحان لم يصلا الجدولَ قطّ — **إصلاحٌ لا يُنفَّذ وهو مكتوب**.
            // فيُوضعان في `after` (عمودُ JSON مُعمَّد): للتصديرِ لا «حالةَ بعدُ»
            // فالعمودُ شاغرٌ له، وهو الاصطلاحُ نفسُه في سائرِ المواضع.
            hub_after_hours() ? ['after' => ['after_hours' => true, 'at' => now()->format('H:i')]] : []);

        return null;
    }

    /**
     * (الجولة 1 · F17) هل هذا التصديرُ واقعٌ «خارج وقت العمل» المحظورُ فيه نقلُ
     * الملفات؟ — مرآةُ شروط `Middleware/WorkHours` حرفاً بحرف (مفتاحُ التشغيل،
     * مفتاحُ منع الملفات، غيرُ المالكين، نافذةُ strict_from → hours_start) كي لا
     * يفترق قرارُ الحزام عن قرارِ الوسيط.
     */
    public static function exportOutsideWorkHours(): bool
    {
        // (مجلسُ الخبراء · التحقّقُ الثامن) النسخةُ اليدويّةُ صارت استدعاءً: التعريفُ
        // في `hub_export_night()` وحدَه، فيسأله هذا البابُ وبابُ CSV الشهريِّ معاً.
        return hub_export_night();
    }

    /**
     * هل يشمل التصديرُ عموداً بعينه؟ — يعيد بناءَ مجموعةِ أعمدة التصدير كما تفعل
     * `columnsAndLabels` (أعمدةُ المستخدم المخصّصةُ مقاطَعةً بالمرئي لدوره، أو
     * الافتراضيّة)، فحزامُ ICCID يُشعَل فقط حين يكون العمودُ فعلاً في المُخرَج
     * (لا محجوباً بـfield-mode، ولا خارجَ تفضيل الأعمدة).
     */
    public static function exportColumnsInclude(array $def, string $key): bool
    {
        if (empty($def)) return false;
        $fields = collect(hub_visible_fields(auth()->user(), (string) ($def['key'] ?? ''), $def));
        if (! $fields->contains('key', $key)) return false;             // محجوبٌ عن الدور → ليس في المُخرَج
        $userCols = array_values(array_intersect(
            (array) hub_pref('cols.' . ($def['key'] ?? ''), []), $fields->pluck('key')->all()));
        $keys = $userCols ?: ($def['columns'] ?? $fields->take(4)->pluck('key')->all());

        return in_array($key, $keys, true);
    }

    /** بث CSV بترويسة BOM (يقرأ Excel العربية) — تستعمله «تصدير القائمة» و«تصدير المحدد» */
    public static function streamCsv(string $module, array $def, $rows, bool $truncated = false)
    {
        [$columns, $labels] = \App\Support\Platform\Modules\ModuleExport::columnsAndLabels($def, $rows->all());

        return response()->streamDownload(function () use ($rows, $columns, $labels, $truncated) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            // معامل escape الفارغ صراحةً: سلوك RFC 4180 كما يقرأ Excel، ويُسكت
            // تحذير PHP 8.4 «$escape الافتراضي سيتغير» المتكرر مع كل سطر
            fputcsv($out, array_column($columns, 'label'), ',', '"', '');
            foreach ($rows as $row) {
                $line = [];
                foreach ($columns as $f) {
                    $v = $row->{$f['col']} ?? '';
                    if ($f['type'] === 'ref' && empty($f['multi'])) $v = $labels[$f['key']][$v] ?? $v;
                    elseif ($f['type'] === 'sec') $v = '••••';
                    elseif (is_array($v)) $v = implode('، ', $v);
                    elseif (is_string($v) && str_starts_with($v, '[')) { $d = json_decode($v, true); if (is_array($d)) $v = implode('، ', array_map(fn ($x) => is_scalar($x) ? $x : '', $d)); }
                    // تحييد حقن الصيغ: قيمة تبدأ بـ= + - @ أو تبويب تُنفَّذ صيغةً
                    // في Excel على جهاز المصدِّر (=HYPERLINK تسريب، =cmd تنفيذ) —
                    // فاصلة عليا بادئة تجعلها نصاً بريئاً كما تفعل جداول Google
                    if (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) $v = "'" . $v;
                    $line[] = $v;
                }
                fputcsv($out, $line, ',', '"', '');
            }
            if ($truncated) {
                fputcsv($out, ['⚠ قُصّ التصدير عند ٥٠٠٠ صف — ضيّق الفلاتر وصدّر على دفعات'], ',', '"', '');
            }
            fclose($out);
        }, $module . '-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** أعمدة الجدول (من تعريف الوحدة) + أسماء العرض للمراجع الظاهرة في الصفحة */
    public static function columnsAndLabels(array $def, array $rows, bool $all = false): array
    {
        // صلاحيات مستوى الحقل: المخفي عن دور المستخدم لا يظهر في جدول ولا صفحة ولا تصدير
        $fields = collect(hub_visible_fields(auth()->user(), (string) ($def['key'] ?? ''), $def));
        // أعمدة المستخدم المخصصة تتقدم على أعمدة السجل — وتُقاطَع مع المرئي لدوره
        $userCols = $all ? [] : array_values(array_intersect(
            (array) hub_pref('cols.' . ($def['key'] ?? ''), []), $fields->pluck('key')->all()));
        $keys   = $all ? $fields->pluck('key')->all()
            : ($userCols ?: ($def['columns'] ?? $fields->take(4)->pluck('key')->all()));
        $cols   = $fields->whereIn('key', $keys)->values()->all();

        $labels = [];
        foreach ($fields->where('type', 'ref') as $f) {
            if (! $all && ! in_array($f['key'], $keys, true)) continue;
            $ids = [];
            foreach ($rows as $row) {
                $v = $row->{$f['col']} ?? null;
                if (! $v) continue;
                if (! empty($f['multi'])) {
                    $arr = is_array($v) ? $v : (json_decode($v, true) ?: []);
                    $ids = array_merge($ids, $arr);
                } else {
                    $ids[] = $v;
                }
            }
            // (الطور H · WP-H.1 · §37) حافّةُ بنيةٍ مُعلَنة (`edge` في السجل): الاسمُ
            // لا يُحَلُّ إلا لقارئٍ يملك وحدةَ الطرف الآخر — «الحافّةُ لمن يملك
            // طرفَيها». القناعُ «—» لا المعرِّفُ الخام: الاسمُ وحده تسريبٌ (نمطُ
            // AuditScopeLeakTest) والمعرِّفُ إفشاءُ وجودٍ — ترشيحٌ خادميٌّ هنا
            // (يسري على الصفحة والجدول والتصدير معاً) لا إخفاءُ JS.
            if (! empty($f['edge']) && ! hub_can(auth()->user(), (string) $f['ref'], 'v')) {
                $labels[$f['key']] = array_fill_keys(
                    array_values(array_unique(array_map('strval', array_filter($ids)))), '—');
                continue;
            }
            $labels[$f['key']] = hub_ref_labels($f['ref'], $ids);
        }

        return [$cols, $labels];
    }
}
