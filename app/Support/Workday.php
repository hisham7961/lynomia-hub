<?php

namespace App\Support;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **محرك يوم العمل — منطقُ الحضور كلُّه في مكانٍ واحد.**
 *
 * كان الحضور صفَّ إدخالٍ يدويّ: وقتان نصيّان وساعاتٌ تُكتب باليد ولا زرَّ
 * حضورٍ أصلاً. صار يوماً حياً: الموظف يسجّل حضوره بضغطة (بوضعه ومشروعه
 * وعميله)، والانصرافُ يحسب الساعات، والحالةُ النهائية تُقيَّم من الواقع كله —
 * الجدول والإجازة المعتمدة والتقرير اليومي.
 *
 * **القاعدة الذهبية: غيابُ التقرير ≠ غياب.** من حضر ونسي الكتابة حالتُه
 * «حاضر — بلا تقرير» تُراجَع، والغيابُ حصراً لمن كان يومُ عملٍ مجدولٌ عليه
 * ولم يحضر ولا إجازةَ له — ويختمه كنسُ نهاية اليوم لا لحظةُ التقييم.
 *
 * ليس منتجَ مراقبة: لا تتبعَ مستمراً — يُسجَّل عنوانُ الشبكة والجهاز لحظةَ
 * الحضور والانصراف فقط (كما يسجّلهما الدخولُ أصلاً)، ضمن meta لا في عمودٍ
 * يُعرض لكل قارئ.
 */
class Workday
{
    /** حالاتُ اليوم — تطابق خيارات وحدة الحضور حرفياً */
    public const PRESENT = 'حاضر';
    public const LATE = 'متأخر';
    public const ABSENT = 'غائب';
    public const LEAVE = 'إجازة';
    public const REMOTE = 'عمل عن بعد';
    public const FIELD = 'عمل ميداني';
    public const NO_REPORT = 'حاضر — بلا تقرير';

    /** أوضاعُ العمل التي تُحسب حضوراً عن بُعد/ميدانياً في التقييم */
    public const REMOTE_MODES = ['عن بعد' => self::REMOTE, 'عمل ميداني' => self::FIELD];

    /* ────────── الجسر: المستخدم ← ملفه الوظيفي ────────── */

    public static function emp(?User $user): ?Employee
    {
        if (! $user) return null;

        return Employee::whereNull('deleted_at')->where('user_id', $user->id)
            ->where('status', 'نشط')->orderBy('id')->first();
    }

    /** صفُّ اليوم للموظف — أو null */
    /**
     * **سقفُ الورديّةِ الواحدة.** حدُّ تبنّي صفِّ الأمسِ **مدّةٌ لا ترتيبُ عقربَين**:
     * الحدُّ الأوّلُ الذي كتبتُه («لحظةُ الضغطِ قبل وقتِ الدخول») كان يفتح نافذةً من
     * ٠٠:٠٠ حتى وقتِ دخولِ الأمس — ثمانيَ ساعاتٍ كلَّ صباحٍ عند دوامٍ يبدأ ٠٨:٠٠ —
     * تُغلق فيها ضغطةٌ واحدةٌ يومَ الناسي بـ٢٣٫٧٨ ساعة، مرفوعةَ رايةِ العبورِ فيسقط
     * عنها وسمُ «مدّةٌ غير صالحة»، مملوءةَ الانصرافِ فيسقط «انصرافٌ مفقود» — **رقمٌ
     * ملفَّقٌ يدخل كشفَ الرواتبِ بلا شذوذٍ ولا وسم**. فالسؤالُ الصحيحُ: أهذه مدّةُ
     * ورديّةٍ أصلاً؟
     */
    public const MAX_SHIFT_HOURS = 16;

    /**
     * **الصفُّ المفتوحُ الآن — جوابٌ واحدٌ يسأله الحارسُ والشاشةُ معاً.**
     *
     * صفُّ اليومِ هو المرجعُ متى وُجد (مفتوحاً كان أو مغلقاً). فإن لم يكن، فصفُّ
     * الأمسِ **إن كان ما يزال مفتوحاً ومدّتُه مدّةُ ورديّة** — وإلّا فلا شيء.
     * (كانت `checkOut` تعرف صفَّ الأمسِ و`mine()` لا تعرفه، فيفتح موظّفُ الليلِ
     * الصفحةَ ٠٣:٤٨ فلا يجد زرَّ «انصراف» أصلاً، ويُنشئ ضغطُه على «تسجيل الحضور»
     * صفّاً ثانياً تُهجَر معه ورديّتُه — التحقّقُ المستقلّ التاسع · ع‑٢.)
     */
    public static function openRow(string $empId, $now = null): ?Attendance
    {
        $now = $now instanceof \Illuminate\Support\Carbon ? $now : now();

        $row = self::today($empId, $now->toDateString());
        if ($row && $row->time_in) return $row;

        // **كلُّ صفوفِ الأمسِ المفتوحة**، لا صفّاً واحداً تختاره قرعة — فيطابق
        // الجوابُ الفرديُّ الجوابَ الجماعيَّ (`openCrossingByEmp`) حرفاً بحرف
        $prev = self::pickRow(
            Attendance::whereNull('deleted_at')->where('emp_id', $empId)
                ->whereDate('date', $now->copy()->subDay()->toDateString())
                ->whereNotNull('time_in')->whereNull('time_out')->get()
        );
        if (! $prev) return $row;

        $start = $prev->in_at
            ?: \Illuminate\Support\Carbon::parse(
                ($prev->date?->toDateString() ?? $prev->date) . ' ' . $prev->time_in);
        $mins = $start->diffInMinutes($now, false);

        return ($mins > 0 && $mins <= self::MAX_SHIFT_HOURS * 60) ? $prev : $row;
    }

    /**
     * **من هم الآن على ورديّةٍ عبرت منتصفَ الليل؟** — نظيرُ `openRow()` للجماعة.
     *
     * شاشةُ المدير كانت تسأل يومَها وحدَه، فتقول «لم يسجّل بعد» عن موظّفٍ بطاقتُه
     * تعرض «انصراف» في الدقيقةِ نفسِها (التحقّقُ المستقلّ العاشر · N-13). واستعلامٌ
     * لكلِّ موظّفٍ كان سيكسر انضباطَ «لا N+1» الذي تحرسه هذه الشاشة، فالجوابُ
     * **استعلامٌ واحد** وفلترةُ النافذةِ في الذاكرة — بالقاعدةِ نفسِها التي يقيس
     * بها `openRow()`: مدّةٌ لا ترتيبُ عقربَين.
     *
     * @return array<string, Attendance>  معرّفُ الموظّف ⇐ صفُّ ورديّتِه المفتوحة
     */
    public static function openCrossingByEmp($empIds, $now = null): array
    {
        $ids = collect($empIds)->filter()->map(fn ($i) => (string) $i)->unique()->values();
        if ($ids->isEmpty()) return [];

        $now = $now instanceof \Illuminate\Support\Carbon ? $now : now();
        $prev = $now->copy()->subDay()->toDateString();

        $rows = Attendance::whereNull('deleted_at')->whereIn('emp_id', $ids)
            ->whereDate('date', $prev)->whereNotNull('time_in')->whereNull('time_out')
            ->get()
            ->filter(function ($row) use ($now) {
                $start = $row->in_at
                    ?: \Illuminate\Support\Carbon::parse(
                        ($row->date?->toDateString() ?? $row->date) . ' '
                        . (Attendance::normTime($row->time_in) ?? '00:00:00'));
                $mins = $start->diffInMinutes($now, false);

                return $mins > 0 && $mins <= self::MAX_SHIFT_HOURS * 60;
            });

        $out = [];
        // **بالقاعدةِ نفسِها التي يختار بها الفرديّ** — لا بترتيبِ SQL (قرعةٌ بين المحرّكين)
        foreach ($rows->groupBy(fn ($r) => (string) $r->emp_id) as $empId => $group) {
            if ($pick = self::pickRow($group)) $out[(string) $empId] = $pick;
        }

        return $out;
    }

    /**
     * **اختيارُ صفِّ اليومِ بمعنًى لا بقرعة** (التحقّقُ المستقلّ الحادي عشر).
     *
     * كان `->orderBy('id')->first()` — و`attendance.id` هو `char(36)` **عشوائيّ**.
     * فما إن يحمل اليومُ صفَّين (وهو مفهومٌ يعترف به المنتجُ صراحةً بحقل `multi`)
     * حتى يتقرّر الجوابُ بقرعةِ معرّف: البطاقةُ تقول «لا ورديّة» بينما الجوابُ
     * الجماعيُّ يقول «مفتوحة»، وضغطةٌ واحدةٌ تفتح صفّاً ثالثاً وتهجر الورديّةَ
     * بلا انصرافٍ أبداً. **فالجوابُ الواحدُ الذي بُني عليه الإصلاحُ كلُّه كان
     * هو نفسُه قرعة.**
     *
     * والقاعدةُ الآن صريحةٌ ومرتّبةٌ: **المفتوحُ قبل المُغلَق** (هو ما يمكن
     * التصرّفُ فيه)، ثمّ **الأحدثُ دخولاً**، والمعرّفُ آخرَ فاصلٍ لا أوّلَه.
     */
    public static function today(string $empId, ?string $date = null): ?Attendance
    {
        $rows = Attendance::whereNull('deleted_at')->where('emp_id', $empId)
            ->whereDate('date', $date ?? now()->toDateString())
            ->get();

        return self::pickRow($rows);
    }

    /** الصفُّ الأحقُّ من مجموعةِ صفوفِ يومٍ واحد — تعريفٌ واحدٌ يسأله كلُّ قارئ */
    public static function pickRow($rows): ?Attendance
    {
        $rows = collect($rows);
        if ($rows->isEmpty()) return null;

        $withIn = $rows->filter(fn ($a) => (bool) $a->time_in);
        $pool = $withIn->isNotEmpty() ? $withIn : $rows;

        $open = $pool->filter(fn ($a) => $a->time_out === null);
        $pool = $open->isNotEmpty() ? $open : $pool;

        /*
         * **مفتاحُ الترتيبِ لحظةٌ لا نصّ** (التحقّقُ الثاني عشر): `in_at` أوّلاً لأنّه
         * لحظةٌ حقيقيّة، و`time_in` **مُصفَّراً** احتياطاً لصفوفٍ قديمةٍ كُتبت قبل
         * التصفير — فـ`'9:00'` كانت تغلب `'23:00:00'` معجميّاً فتفوز ورديّةُ الصباحِ
         * المنسيّةُ على ورديّةِ الليلِ المفتوحة.
         */
        return $pool->sortBy(fn ($a) => sprintf('%s|%s|%s',
            (string) ($a->in_at?->format('Y-m-d H:i:s')
                ?: (($a->date?->toDateString() ?? $a->date) . ' ' . (Attendance::normTime($a->time_in) ?? ''))),
            (string) (Attendance::normTime($a->time_in) ?? ''),
            (string) $a->id))->last();
    }

    /* ────────── الحضور ────────── */

    /**
     * تسجيلُ الحضور — يكتب لسجل صاحبه وحده، فلا يحتاج صلاحيةَ وحدة الحضور:
     * كبوابة الموظف الذاتية تماماً. النداءُ الثاني في اليوم نفسه لا يكرر صفاً.
     */
    public static function checkIn(User $user, array $in = []): array
    {
        $emp = self::emp($user);
        if (! $emp) return ['ok' => false, 'msg' => 'لا ملفَ موظفٍ نشطاً مربوطاً بحسابك — اطلب من الموارد البشرية ربطه من حقل «حساب النظام»'];

        /*
         * **والكاتبُ يسأل ما تسأله الشاشةُ والحارس** (التحقّقُ العاشر · ع‑أ): كان
         * `checkIn` على `today()` وحدَها، فمن له ورديّةٌ ليليّةٌ مفتوحةٌ يضغط «تسجيل
         * الحضور» فيُفتَح صفٌّ ثانٍ، ثمّ يُغلق الانصرافُ صفَّ اليومِ وتُهجَر الورديّةُ
         * بلا انصرافٍ أبداً — **عينُ الضررِ الذي فُتح البابُ لإغلاقِه**.
         */
        $now = now();
        $row = self::openRow($emp->id, $now);

        if ($row && $row->time_in && ! $row->time_out
            && (string) ($row->date?->toDateString() ?? $row->date) !== $now->toDateString()) {
            return ['ok' => false, 'row' => $row,
                'msg' => 'ورديّتُك التي بدأت ' . $row->time_in . ' (أمس) ما تزال مفتوحة — '
                    . 'سجّل انصرافَك منها أوّلاً ثمّ ابدأ يومَك'];
        }

        if ($row && $row->time_in) {
            return ['ok' => false, 'msg' => 'حضورُك اليوم مسجَّلٌ منذ ' . $row->time_in, 'row' => $row];
        }

        $start = (string) setting('sec.hours_start', '08:00');
        $grace = max(0, (int) setting('work.late_grace', 15));
        // مقارنةٌ بدقائق اليوم لا بنصٍّ: بدايةٌ قرب منتصف الليل + سماحية كانت
        // تلتفّ إلى «00:14» فيُوسم النهارُ كلُّه متأخراً
        [$sh, $sm] = array_map('intval', array_pad(explode(':', $start), 2, 0));
        $late = ((int) $now->format('H') * 60 + (int) $now->format('i')) > ($sh * 60 + $sm + $grace);

        $mode = in_array($in['mode'] ?? '', ['مكتب', 'عن بعد', 'موقع عميل', 'عمل ميداني', 'مهمة خارجية'], true)
            ? $in['mode'] : 'مكتب';

        $status = self::REMOTE_MODES[$mode] ?? ($late ? self::LATE : self::PRESENT);

        // **مشروع/عميلُ الحضورِ ضمنَ نطاقِ الموظّف** (Permissions 360 · 10.4): قيمةٌ خارجَ
        // نطاقِه تُتجاهَل — فلا يُربَطُ حضورُه بمشروعٍ/عميلٍ لا يراه (صفٌّ ذاتيٌّ، لكنّ المرجعَ
        // يُنطَّق كسائرِ الكتابات). النطاقُ الشاملُ يمرّ بلا تغيير.
        $pid = $in['project_id'] ?? null;
        if ($pid && ! hub_scope(\App\Models\Project::query(), 'projects', $user)->whereKey($pid)->exists()) {
            $pid = null;
        }
        $cid = $in['client_id'] ?? null;
        if ($cid && ! hub_scope(\App\Models\Client::query(), 'clients', $user)->whereKey($cid)->exists()) {
            $cid = null;
        }

        $row = $row ?: new Attendance(['emp_id' => $emp->id, 'date' => $now->toDateString()]);
        $row->fill([
            // **لحظةُ الضغطِ الفعليّةُ بالثانية** — لا قصَّ للدقيقة: ما ضغطه الموظّفُ هو ما يُسجَّل
            'time_in' => $now->format('H:i:s'),
            'mode' => $mode,
            'client_id' => $cid,
            'project_id' => $pid,
            'company_id' => $emp->company_id,
            'status' => $status,
            'meta' => array_merge((array) $row->meta, ['checkin' => array_filter([
                // ختمُ الحقيقةِ الكامل (تاريخٌ ووقتٌ ومنطقةٌ زمنيّة) — يبقى أثراً حتى لو
                // عُدِّل time_in لاحقاً من نموذجِ الموارد: لحظةُ الضغطِ لا تُطمَس
                'at' => $now->toIso8601String(),
                'ip' => request()?->ip(),
                'device' => hub_fit((string) request()?->userAgent(), 200),
                'geo' => (setting('work.geo', '0') === '1' && ($in['geo'] ?? null)) ? $in['geo'] : null,
            ])]),
        ]);
        $row->save();

        hub_audit('تسجيل حضور', 'attend', $row->id, $emp->name,
            ['after' => array_filter(['mode' => $mode, 'in' => $row->time_in, 'project' => $in['project_id'] ?? null])]);

        return ['ok' => true, 'msg' => 'سُجّل حضورُك ' . $row->time_in . ($late ? ' — متأخراً عن ' . $start : ''), 'row' => $row];
    }

    /** الانصراف: يحسب الساعات ويختم الحالةَ النهائية من الواقع كله */
    public static function checkOut(User $user): array
    {
        $emp = self::emp($user);
        if (! $emp) return ['ok' => false, 'msg' => 'لا ملفَ موظفٍ مربوطاً بحسابك'];

        $now = now();

        /*
         * **الوردياتُ التي تعبر منتصفَ الليل** (التحقّقُ الثامن · ن-٣): من بدأ ورديتَه
         * ٢٣:٠٠ يضغط «انصراف» الساعةَ ٠٣:٤٨ — و**يومُه في السجلِّ هو الأمس**. فكان
         * `today()` لا يجدُ شيئاً ويردُّ «سجّل حضورَك أولاً»، فلا ينصرفُ أبداً ويبقى
         * يومُه «انصرافٌ مفقود» بساعاتٍ فارغة — **والساعاتُ تُغذّي الرواتبَ والامتثال**.
         * فيُفتَّشُ صفُّ الأمسِ **إن كان ما يزال مفتوحاً** (حضورٌ بلا انصراف) قبل الردّ.
         */
        $row = self::openRow($emp->id, $now);

        if (! $row || ! $row->time_in) return ['ok' => false, 'msg' => 'لا حضورَ مسجَّلاً اليوم — سجّل حضورَك أولاً'];
        if ($row->time_out) return ['ok' => false, 'msg' => 'انصرافُك مسجَّلٌ منذ ' . $row->time_out, 'row' => $row];

        // **لحظةُ الضغطِ الفعليّةُ بالثانية** كالحضور — والفرقُ يُحسب عليها لا على دقيقةٍ مقصوصة
        $mins = (strtotime($now->format('H:i:s')) - strtotime((string) $row->time_in)) / 60;
        $crosses = $mins < 0;
        if ($crosses) $mins += 24 * 60;                      // وردية تعبر منتصف الليل

        $row->time_out = $now->format('H:i:s');
        /*
         * **ورفعُ الرايةِ صراحةً — وإلّا فالفرعُ أعلاه ميّت.** حارسُ `Attendance` يرفض
         * كلَّ انصرافٍ قبل الدخولِ ما لم تُرفع `overnight`، فكان هذا الحسابُ يُجرى ثمّ
         * يُرمى الصفُّ عند الحفظ: سؤالٌ واحدٌ («أهذا يومٌ يعبر منتصفَ الليل؟») بتعريفَين،
         * أحدُهما ضمنيٌّ هنا والآخرُ صريحٌ هناك. صارا واحداً.
         */
        if ($crosses) $row->overnight = true;
        $row->hours = round(max(0, $mins) / 60, 2);
        // الحالةُ فيزيائيّةٌ محضة — لا تُطمَس بغيابِ التقرير (§6)
        $row->status = self::evaluate($row, $user);
        // مهلةُ التقرير (§53) يختمها **خطّافُ النموذج** الآن لا هذا البابُ وحدَه
        // (N-21): كان بابٌ من أربعةٍ يكتبها، فما أدخلته المواردُ يدويّاً لا تراه
        // شبكةُ أمانِ المصالحة أبداً. الحسابُ نفسُه — والكاتبُ واحدٌ يغطّي الجميع.
        $row->meta = array_merge((array) $row->meta, ['checkout' => array_filter([
            // ختمُ لحظةِ الانصرافِ الكامل — أثرٌ لا يُطمَس بتعديلٍ لاحقٍ للحقل
            'at' => $now->toIso8601String(),
            'ip' => request()?->ip(), 'device' => hub_fit((string) request()?->userAgent(), 200),
        ])]);
        $row->save();

        hub_audit('تسجيل انصراف', 'attend', $row->id, $emp->name,
            ['after' => ['out' => $row->time_out, 'hours' => $row->hours, 'status' => $row->status]]);

        // رسالةُ الانصراف (§64): إن كان التقريرُ ما زال ناقصاً وواجباً، أخبِرْ بالمهلة —
        // بلا حجبِ الانصراف، ومن المُحلِّلِ المركزيّ لا من عمودِ حالةٍ مطموس
        $c = \App\Support\DailyWorkCompliance::resolve($emp, (string) ($row->date?->toDateString() ?? $row->date));
        $pendingNote = '';
        if (in_array($c['state'], [\App\Support\DailyWorkCompliance::REPORT_PENDING,
            \App\Support\DailyWorkCompliance::PRESENT_WITHOUT_REPORT], true)) {
            $pendingNote = '. تقريرُ اليوم لم يُقدَّم بعد'
                . ($c['deadline_at'] ? '، ويجب تقديمُه قبل ' . $c['deadline_at']->format('H:i')
                    . ' — بعدها يُحتسب اليومُ غيابًا لعدم تقديم التقرير' : ' — أضِف بنودَ يومك من تحديثات العمل');
        }

        return ['ok' => true, 'row' => $row, 'compliance' => $c,
            'msg' => 'انصرفتَ ' . $row->time_out . ' — ' . $row->hours . ' ساعة' . $pendingNote];
    }

    /**
     * الحالةُ الفيزيائيّة النهائية لليوم — **حضورٌ لا امتثال** (§6).
     *
     * إجازةٌ معتمدة تغلب، ثم متأخّر/ميدانيّ/عن بعد/حاضر. **لا يطمس هذا العمودُ
     * غيابَ التقرير أبداً** بعد اليوم: الامتثالُ التقريريُّ والأثرُ الفعّال يُشتقّان
     * مركزيّاً في `DailyWorkCompliance` (فيُعاد تقييمُهما لحظةَ تقديمِ التقريرِ ولو
     * بعد الانصراف). كان هذا الموضعُ يُرجع «حاضر — بلا تقرير» فيدهس الحضورَ ولا
     * يُعاد أبداً — جذرُ العيب (ROOT_CAUSE.md).
     */
    public static function evaluate(Attendance $row, ?User $user = null): string
    {
        if (self::onLeave((string) $row->emp_id, (string) ($row->date?->toDateString() ?? $row->date))) {
            return self::LEAVE;
        }

        $base = self::REMOTE_MODES[$row->mode] ?? null;
        return in_array($row->status, [self::LATE], true) ? self::LATE : ($base ?: self::PRESENT);
    }

    /** أَعلى الموظفِ إجازةٌ معتمدةٌ من أنواع الغياب تشمل هذا اليوم؟ */
    public static function onLeave(string $empId, string $date): bool
    {
        if (! Schema::hasTable('leave_requests')) return false;

        return LeaveRequest::whereNull('deleted_at')->where('emp_id', $empId)
            ->where('status', 'معتمد')
            ->whereIn('type', config('hub.leave.deduct_types', []))
            // **مدىً لا دالّةً على العمود** (#33/§٥): الفهرسُ المركّبُ
            // `leave_requests_emp_dates_idx` أُضيف في هذه الدفعة، وقِيس بـ`EXPLAIN`
            // أنّ المدى يُنصّف الصفوفَ المفحوصةَ فوق ما يكسبه الفهرسُ وحدَه.
            ->tap(fn ($q) => \App\Support\DayRange::upto($q, 'date_from', $date))
            ->where(fn ($q) => \App\Support\DayRange::since($q, 'date_to', $date)->orWhereNull('date_to'))
            ->exists();
    }

    /**
     * كنسُ نهاية اليوم (الأتمتة): من كان يومُ أمسٍ يومَ عملٍ عليه ولم يسجّل
     * حضوراً ولا إجازةَ معتمدةً له — يُختم غائباً. idempotent: الصفُّ الموجود
     * لا يُمسّ، والعطلةُ الأسبوعية ليست غياباً.
     */
    public static function close(?string $date = null): int
    {
        $date = $date ?? now()->subDay()->toDateString();
        if (! Schema::hasTable('attendance') || ! Schema::hasTable('employees')) return 0;
        // عطلة الأسبوع من الإعداد نفسه الذي تقرؤه hub_workdays — لا غياب في عطلة
        $weekend = array_map('intval', array_filter(explode(',', (string) setting('cost.weekend', '5,6'))));
        if (in_array((int) date('N', strtotime($date)), $weekend, true)) return 0;

        /*
         * **ومن امتدّت ورديّتُه داخلَ اليومِ المكنوس ليس غائباً فيه** (التحقّقُ
         * العاشر · ع‑ب): من بدأ ٢٣:٠٠ وانصرف التاسعةَ صباحاً كان يُختم «غائباً»
         * في اليومِ نفسِه الذي كان فيه على رأسِ عملِه — و`effective` هو العمودُ
         * الذي تُبنى عليه المحاسبةُ الشهريّة. استعلامٌ واحدٌ قبل الحلقة (لا
         * استعلامَ لكلِّ موظّف).
         */
        $prevDay = date('Y-m-d', strtotime($date . ' -1 day'));

        // (أ) من عبرت ورديّتُه وأُغلقت داخلَ اليومِ المكنوس
        $crossers = Attendance::whereNull('deleted_at')->whereDate('date', $prevDay)
            ->where('overnight', true)->whereDate('out_at', $date)
            ->pluck('emp_id')->flip()->all();

        /*
         * (ب) **ومن ورديّتُه ما تزال مفتوحة** (التحقّقُ الثاني عشر · ع‑٧): الاستثناءُ
         * السابقُ اشترط `overnight=true` و`out_at` — وكلاهما لا يُكتب إلّا عند
         * الانصراف. فمن نسي انصرافَه من ورديّةٍ ليليّةٍ كان يُدان **غياباً في السجلِّ
         * الدائم** الذي تُبنى عليه المحاسبةُ الشهريّة، وهو عينُ الفئةِ التي وُضع
         * الاستثناءُ لأجلِها.
         */
        $openIds = Attendance::whereNull('deleted_at')->whereDate('date', $prevDay)
            ->whereNotNull('time_in')->whereNull('time_out')->pluck('emp_id')->unique();
        foreach (self::openCrossingByEmp($openIds, \Illuminate\Support\Carbon::parse($date . ' 00:00:01')) as $empId => $r) {
            $crossers[$empId] = true;
        }

        $n = 0;
        Employee::whereNull('deleted_at')->where('status', 'نشط')
            ->orderBy('id')->chunkById(100, function ($emps) use ($date, $crossers, &$n) {
                foreach ($emps as $emp) {
                    if (isset($crossers[$emp->id])) continue;
                    if (self::today($emp->id, $date)) continue;
                    if (self::onLeave($emp->id, $date)) continue;
                    // **والعذرُ المعتمَدُ غيرُ الخصميِّ يمنع الختمَ أيضاً** (الخاتمة · X1):
                    // «إذن خروج» و«عمل عن بعد» معتمَدان كانا يُختمان «غائباً» في السجلِّ
                    // الدائمِ لأنّ الكنسَ يقرأ `onLeave` (أنواعَ الخصمِ) وحدَها — فيصير
                    // اعتمادُ الموارد البشريّةِ سبباً في إدانةٍ مكتوبةٍ لا عرضاً عابراً
                    if (\App\Support\DailyWorkCompliance::excuseFor($emp->id, $date) !== null) continue;
                    Attendance::create([
                        'emp_id' => $emp->id, 'date' => $date, 'status' => self::ABSENT,
                        'company_id' => $emp->company_id,
                        'notes' => 'ختم آلي: يوم عمل بلا حضور ولا إجازة معتمدة',
                    ]);
                    $n++;
                }
            });

        return $n;
    }

    /* ────────── شاشة المدير: فريقي اليوم ────────── */

    public static function teamToday(): array
    {
        return hub_screen('wd:team', 120, fn () => self::teamCalc(), ['attendance', 'work_updates', 'employees']);
    }

    protected static function teamCalc(): array
    {
        if (! hub_can(auth()->user(), 'hr', 'v') || ! Schema::hasTable('employees')) {
            return ['rows' => [], 'n' => [], 'roll' => null];
        }

        $today = now()->toDateString();
        $emps = hub_company_scope(hub_scope(Employee::query(), 'hr'), 'hr')
            ->whereNull('deleted_at')->where('status', 'نشط')
            ->orderBy('name')->get(['id', 'name', 'dept', 'user_id']);

        // الحلُّ الجماعيُّ المركزيّ (§101 · N+1=0): استعلامان لا استعلامٌ لكلِّ صف
        $comp = \App\Support\DailyWorkCompliance::resolveMany($emps, $today);

        // بلاغاتُ العوائق: تُقرأ مع بنودِ اليوم دفعةً واحدة (لا استعلامَ لكلِّ موظف)
        $blockersByUser = DB::table('work_updates')->whereNull('deleted_at')
            ->tap(fn ($q) => \App\Support\DayRange::on($q, 'work_date', $today))
            ->whereIn('created_by', $emps->pluck('user_id')->filter())
            ->whereNotNull('problems')->where('problems', '!=', '')
            ->get(['created_by'])->groupBy('created_by');

        $projects = hub_ref_labels('projects',
            collect($comp)->pluck('projects')->flatten(1)->filter()->unique()->values()->all());

        // ورديّاتٌ عبرت منتصفَ الليل وما تزال مفتوحةً — الجوابُ نفسُه الذي تسأله بطاقتُه
        $night = self::openCrossingByEmp($emps->pluck('id'), now());

        /*
         * **نداءُ اليومِ من اللقطةِ نفسِها** (مجلس الخبراء · N-22).
         *
         * كان المتحكّمُ يحسبه **حيّاً** بجوارِ جدولٍ مخبوءٍ ١٢٠ ثانية — فنافذةُ
         * تناقضٍ ≤١٢٠ ثانية: عند تجاوزِ بدايةِ الدوامِ يقول النداءُ «غائب» ويقول
         * الجدولُ «لم يبدأ الدوامُ بعد»، والمجموعُ يتجاوز عددَ الموظّفين. وليست
         * البيانات هي ما يختلف (الخبيئةُ تُبطَل بتغيّرِها) بل **لحظةُ السؤال**.
         *
         * فصار جوابَين من سؤالٍ واحدٍ في لقطةٍ واحدة. ويُوفّر معه استعلامَ
         * الموظّفين الذي كان المتحكّمُ يكرّره حرفاً.
         */
        $roll = \App\Support\DailyWorkCompliance::rollCall($emps, $today);

        $rows = [];
        $n = ['emps' => $emps->count(), 'in' => 0, 'noreport' => 0, 'leave' => 0,
            'field' => 0, 'absent' => 0, 'late' => 0, 'none' => 0, 'hours' => 0.0, 'blockers' => 0,
            'reported' => 0, 'pending' => 0, 'missing' => 0, 'absence_report' => 0];

        foreach ($emps as $e) {
            $c = $comp[$e->id];
            $a = $c['attendance'];
            // شرطُ `openRow` نفسُه: غيابُ **دخول** لا غيابُ صفّ (ع‑٤)
            $nightRow = ! $c['checked_in'] ? ($night[(string) $e->id] ?? null) : null;
            $blockers = (int) ($blockersByUser->get($e->user_id)?->count() ?? 0);

            if ($c['checked_in'] || $nightRow) $n['in']++;
            // «تقريرٌ ناقص» = حاضرٌ بلا تقريرٍ صالح (بانتظار أو ناقص) — تتبع الإشارةَ القائمة
            if ($c['checked_in'] && ! $c['report_submitted'] && ! $c['on_leave']) $n['noreport']++;
            if ($c['report_submitted']) $n['reported']++;
            if ($c['compliance'] === 'pending') $n['pending']++;
            if ($c['state'] === \App\Support\DailyWorkCompliance::PRESENT_WITHOUT_REPORT) $n['missing']++;
            if ($c['effective'] === 'absent_due_to_missing_report') $n['absence_report']++;
            if ($c['on_leave']) $n['leave']++;
            if (in_array($c['physical'], [self::FIELD, self::REMOTE], true)) $n['field']++;
            if ($c['physical'] === self::ABSENT || (! $c['checked_in'] && $a)) $n['absent']++;
            if ($c['physical'] === self::LATE) $n['late']++;
            if (! $a && ! $nightRow) $n['none']++;
            $n['hours'] += $c['reported_hours'];
            $n['blockers'] += $blockers;

            $rows[] = [
                'emp' => $e, 'att' => $a, 'comp' => $c, 'night' => $nightRow,
                'entries' => $c['report_count'], 'hours' => $c['reported_hours'],
                'blockers' => $blockers,
                'projects' => collect($c['projects'])->map(fn ($pid) => $projects[$pid] ?? '—')->values()->all(),
            ];
        }

        $n['hours'] = round($n['hours'], 1);

        return ['rows' => $rows, 'n' => $n, 'date' => $today, 'roll' => $roll];
    }

    /** بطاقةُ الموظف الذاتية (ودجة الرئيسية): حالُ يومي وبنودُه */
    public static function mine(User $user): ?array
    {
        $emp = self::emp($user);
        if (! $emp) return null;

        // الشاشةُ تسأل ما يسأله الحارس — لا تعريفَ ثانياً في البطاقة
        $row = self::openRow($emp->id);
        $entries = DB::table('work_updates')->whereNull('deleted_at')
            ->where('created_by', $user->id)->whereDate('work_date', now()->toDateString())
            ->get(['project_id', 'hours']);

        return [
            'emp' => $emp, 'att' => $row,
            'entries' => $entries->count(),
            'hours' => round((float) $entries->sum('hours'), 1),
            'projects' => hub_ref_options_scoped('projects', null, $user),
            'clients' => hub_can($user, 'clients', 'v') ? hub_ref_options_scoped('clients', null, $user) : [],
        ];
    }
}
