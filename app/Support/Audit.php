<?php

namespace App\Support;

use App\Models\AuditEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * قراءة سجل التدقيق: الفرق المقروء، وسلامة السلسلة حيّةً على الشاشة.
 *
 * كان القيد يحمل `before` و`after` كاملين فتُطبع الوثيقتان JSON خاماً بأسماء
 * أعمدةٍ إنجليزية — فيقرأ المالك عشرين حقلاً ليجد الحقل الواحد الذي تغيّر.
 * وكانت البصمة المتشابكة تُحسب مع كل قيد ثم **لا يقرؤها أحد** إلا بأمر طرفية:
 * حمايةٌ لا تُرى ليست حماية.
 */
class Audit
{
    /** أعمدةٌ لا تُعرض قيمتها مهما كانت — سرٌّ أو بصمةُ سرّ */
    protected const MASKED = ['password', 'secret_cipher', 'totp_secret_cipher', 'token_hash',
                              'password_hash', 'recovery_codes', 'remember_token', 'value_cipher'];

    /** أعمدةٌ تقنية لا معنى لعرضها في فرقٍ يقرؤه إنسان */
    protected const NOISE = ['updated_at', 'search_vec', 'version', 'updated_by', 'hash', 'prev_hash'];

    /**
     * قاعدةُ قراءة سجلّ التدقيق **منطَّقةً** — كلُّ قارئٍ للجدول (القائمة، النبض،
     * أيُّ عدّاد) يبدأ من هنا لا من `DB::table('audits')` الخام.
     *
     * القاعدة: **قارئ الأثر لا يرى أثرَ ما لا يراه.** كان النطاقُ منسوخاً في
     * ثلاثة مواضع داخل شاشة التدقيق وحدها وكلُّها تنسى المرشِّحين الأهمّ:
     * وحدةٌ لا يملك القارئ عرضَها كان **اسمُ** سجلّها يُطبع ويُربَط بشاشته —
     * والاسمُ وحده تسريب («مسيّر رواتب أبريل» يقول ما يكفي) — وعزلُ العملاء
     * (`hub_client_ids`) لم يُطبَّق مطلقاً رغم أنّ ١٦ وحدةً تحمل عمودَ عميل.
     * النمطُ المرجعيّ: بلاطةُ «آخر النشاطات» في WidgetRegistry — معمَّماً هنا.
     */
    public static function scopedQuery($user = null)
    {
        $user = $user ?? auth()->user();
        $q = DB::table('audits');
        // بلا مستخدمٍ لا قراءة — درعٌ لمستهلكٍ يُستدعى خارج سياق مصادقة
        if (! $user) return $q->whereRaw('1 = 0');

        // ١) وحدةٌ لا يراها المستخدم لا يظهر أثرُها. القيدُ النظاميّ — module
        //    فارغ، أو خارجَ سجلّ الوحدات كـ`settings` — تحكمه رايةُ `audit`
        //    وحدها، كما في Audit::diff تماماً (حجبٌ بقائمة المسجَّل المحجوب لا
        //    بقائمة المسموح، كي لا تختفي القيودُ النظامية عن المالك نفسِه).
        $hidden = array_values(array_filter(array_keys(hub_modules()),
            fn ($m) => ! hub_can($user, $m, 'v')));
        if ($hidden) {
            $q->where(fn ($w) => $w->whereNull('audits.module')
                                   ->orWhereNotIn('audits.module', $hidden));
        }

        // ٢) النطاق المشاريعي: فعلُه هو، أو ما جرى في مشاريعه
        if (hub_scoped($user)) {
            $ids = $user->visibleProjectIds();
            $q->where(fn ($w) => $w->where('audits.user_id', $user->id)
                                   ->orWhereIn('audits.project_id', $ids));
        }

        // ٣) عزل الشركات: القيد يحمل شركته منذ سمة Auditable، والمعدومُ الشركةِ
        //    يبقى لصاحبه وحده. (درعُ النشر-قبل-الترحيل: إن غاب العمودُ بعدُ
        //    نتحفّظ على نشاط المستخدم نفسِه — لا تسريبَ ولا انهيار.)
        if (($cids = hub_company_ids($user)) !== null) {
            if (Schema::hasColumn('audits', 'company_id')) {
                $uid = $user->id;
                $q->where(fn ($w) => $w->whereIn('audits.company_id', $cids)
                    ->orWhere(fn ($y) => $y->whereNull('audits.company_id')
                                           ->where('audits.user_id', $uid)));
            } else {
                $q->where('audits.user_id', $user->id);
            }
        }

        // ٤) عزل العملاء — نظيرُ hub_scope حرفياً منقولاً إلى الأثر: وحدةٌ لها
        //    عمودُ عميلٍ لا يُعرَض قيدُها للمحصور إلا إن كان سجلُّه لعميلٍ مسموح.
        //    القيدُ اليتيم (بلا record_id، أو سجلُّه لم يعد في جدوله) يُحجَب
        //    **تحفّظاً** لا يُكشف قرعةً. الوحداتُ بلا عمودِ عميلٍ والقيودُ
        //    النظامية تبقى محكومةً بالمصفوفة — كما في hub_scope سواء.
        if (($kids = hub_client_ids($user)) !== null) {
            $clientMods = array_values(array_filter(array_keys(hub_modules()),
                fn ($m) => hub_client_col($m) !== null));
            if ($clientMods) {
                $q->where(function ($w) use ($clientMods, $kids, $user) {
                    $w->whereNull('audits.module')->orWhereNotIn('audits.module', $clientMods);
                    foreach ($clientMods as $mk) {
                        if (! hub_can($user, $mk, 'v')) continue;   // محجوبةٌ أصلاً بالمرشِّح ١
                        $col = hub_client_col($mk);
                        if ($col === 'id') {                        // وحدة العملاء نفسها: السجلُّ هو العميل
                            $w->orWhere(fn ($x) => $x->where('audits.module', $mk)
                                                     ->whereIn('audits.record_id', $kids));
                            continue;
                        }
                        $table = hub_mod($mk)['table'] ?? null;
                        try {
                            if (! $table || ! Schema::hasTable($table)) continue; // بلا جدولٍ تُحجَب الوحدة تحفّظاً
                        } catch (\Throwable $e) {
                            continue;
                        }
                        $w->orWhere(fn ($x) => $x->where('audits.module', $mk)
                            ->whereIn('audits.record_id',
                                fn ($s) => $s->select('id')->from($table)->whereIn($col, $kids)));
                    }
                });
            }
        }

        return $q;
    }

    /**
     * الفرق المقروء: الحقول **المتغيّرة وحدها**، بأسمائها العربية من سجل الوحدات.
     *
     * @return array<int, array{label:string, col:string, from:string, to:string}>
     */
    public static function diff(?string $module, $before, $after): array
    {
        $b = self::decode($before);
        $a = self::decode($after);
        if (! $b && ! $a) return [];

        // **قيود مستوى الحقل تسري على السجل كما تسري على السجلات**: القيد يحمل
        // «قبل ← بعد» بقيمهما، فطباعتُه كانت نافذةً خلفية على كل حقلٍ مخفيّ —
        // يكفي أن يحمل الدور راية «سجل التدقيق» ليقرأ رواتب من لا يراها.
        $u = auth()->user();

        // **وصلاحيةُ الوحدة قبل قيود الحقل** (v2.317): القيدُ يحمل «قبل ← بعد»
        // بقيمهما، وكانت قيودُ الحقل وحدها تُطبَّق — فمن يحمل رايةَ سجلّ التدقيق
        // يقرأ قيمَ وحدةٍ لا يملك `v` عليها أصلاً (راتبٌ من hr مثلاً). رايةُ
        // التدقيق تفتح السجلَّ لا كلَّ الوحدات.
        if ((string) $module !== '' && hub_mod((string) $module) && ! hub_can($u, (string) $module, 'v')) {
            return [];
        }

        $labels = [];
        $locked = [];
        foreach ((array) (hub_mod((string) $module)['fields'] ?? []) as $f) {
            $col = (string) ($f['col'] ?? '');
            if ($col === '') continue;
            $labels[$col] = (string) ($f['label'] ?? $col);
            if (hub_field_mode($u, (string) $module, (string) ($f['key'] ?? $col)) !== '') $locked[$col] = 1;
        }

        $out = [];
        foreach (array_keys($a + $b) as $col) {
            if (in_array($col, self::NOISE, true)) continue;
            $from = $b[$col] ?? null;
            $to   = array_key_exists($col, $a) ? $a[$col] : null;
            // الإضافة والحذف يحملان صورةً كاملة لا فرقاً: الحقول الفارغة ضجيج
            if ($from === $to) continue;
            if (($from === null || $from === '') && ($to === null || $to === '')) continue;

            $out[] = [
                'col'   => $col,
                'label' => $labels[$col] ?? self::humanCol($col),
                // الحقل المحجوب: يُقال إنه **تغيّر** ولا تُكشف قيمتاه — فلا
                // يضيع أثرُ التعديل ولا تُسرَّب القيمة
                'from'  => isset($locked[$col]) ? '••• محجوب' : self::show($col, $from),
                'to'    => isset($locked[$col]) ? '••• محجوب' : self::show($col, $to),
            ];
        }

        return $out;
    }

    /**
     * سلامة الذيل: يُعيد حساب بصمات آخر السجلات ويقارن رأس السلسلة.
     *
     * فحصُ الجدول كاملاً مع كل فتحةِ شاشة لا يُحتمل على جدولٍ ناضج، وفحصُ
     * الذيل يمسك ما يُعبَث به فعلاً: أحدث القيود (تغطيةً كاملةً يبقى الأمر
     * `hub:audit-verify`، وهذه الشاشة تحيل إليه عند أول كسر).
     */
    public static function verifyTail(int $n = 60): array
    {
        $none = ['ok' => true, 'broken' => 0, 'why' => '', 'label' => 'لا سلسلة بعد'];
        if (! Schema::hasTable('audits') || ! Schema::hasTable('audit_chain')) return $none;

        try {
            $rows = AuditEntry::whereNotNull('hash')->orderByDesc('id')->limit($n)->get();
            if ($rows->isEmpty()) {
                $unsealed = self::unsealedAfterEpoch();

                return $unsealed
                    ? ['ok' => false, 'broken' => $unsealed, 'why' => "{$unsealed} قيداً كُتب بلا بصمة", 'label' => 'بلا ختم']
                    : $none;
            }

            $head = (string) DB::table('audit_chain')->where('id', 1)->value('head');
            if ($head !== '' && $head !== $rows->first()->hash) {
                return ['ok' => false, 'broken' => 1, 'label' => '⚠️ عبث',
                        'why' => 'رأس السلسلة لا يطابق آخر قيد — حُذفت قيودٌ من الذيل على الأرجح'];
            }

            // **والوصلُ بين القيود، لا بصمةُ كلٍّ وحدها.** حذفُ قيدٍ من المنتصف
            // يُبقي الرأسَ مطابقاً وبصمةَ كلِّ قيدٍ باقٍ صحيحة — فكان يُبلَّغ
            // «سلسلة سليمة»، وهذا بالضبط ما تُصان السلسلةُ من أجله. الصفوفُ
            // مرتَّبةٌ تنازلياً، فـ`prev_hash` كلِّ قيدٍ لا بدّ أن يساوي `hash`
            // تاليه في المصفوفة (وآخرُ عنصرٍ في النافذة سابقُه خارجها فيُتخطّى).
            $rows = $rows->values();
            $broken = 0;
            foreach ($rows as $i => $row) {
                $p = (string) $row->prev_hash;
                $match = hash('sha256', $p . '|' . $row->canonical()) === $row->hash
                      || hash('sha256', $p . '|' . $row->canonical('v2raw')) === $row->hash
                      || hash('sha256', $p . '|' . $row->canonical('v1')) === $row->hash;
                if (! $match) $broken++;
                elseif ($i + 1 < $rows->count() && $p !== (string) $rows[$i + 1]->hash) $broken++;
            }

            $unsealed = self::unsealedAfterEpoch();
            if ($broken || $unsealed) {
                return ['ok' => false, 'broken' => $broken + $unsealed, 'label' => '⚠️ عبث',
                        'why' => $broken
                            ? "{$broken} قيداً من آخر {$n} لا تطابق بصمتُه محتواه — عُدِّل مباشرةً في القاعدة"
                            : "{$unsealed} قيداً كُتب بلا بصمة بعد بدء السلسلة"];
            }

            return ['ok' => true, 'broken' => 0, 'why' => '', 'label' => 'سلسلة سليمة (آخر ' . $rows->count() . ')'];
        } catch (\Throwable $e) {
            return ['ok' => true, 'broken' => 0, 'why' => '', 'label' => 'تعذّر الفحص'];
        }
    }

    /**
     * (WP-5.4) التحقّق الموضعيّ لقيدٍ واحد — منطقُ نافذة `verifyTail` نفسُه
     * (المطابقة الثلاثية v2/v2raw/v1) على هذا الصفّ وحده، بلا استعلامٍ إضافيّ.
     * **ولا فحصَ سلسلةٍ كاملاً في طلبٍ أبداً** — التغطيةُ الكاملة (الوصلُ بين
     * القيود ورأسُ السلسلة) لأمر `hub:audit-verify` وزرِّ مركز التشغيل.
     *
     * @return array{status:string, label:string, tone:string, why:string}
     */
    public static function verifyRow(AuditEntry $row): array
    {
        if ((string) $row->hash === '') {
            return ['status' => 'unsealed', 'label' => 'بلا ختم', 'tone' => 'wn',
                    'why' => 'القيد كُتب بلا بصمة — طبيعيٌّ لما قبل بدء السلسلة، وعيبٌ يكشفه hub:audit-verify لما بعده'];
        }

        $p = (string) $row->prev_hash;
        $match = hash('sha256', $p . '|' . $row->canonical()) === $row->hash
              || hash('sha256', $p . '|' . $row->canonical('v2raw')) === $row->hash
              || hash('sha256', $p . '|' . $row->canonical('v1')) === $row->hash;

        return $match
            ? ['status' => 'ok', 'label' => 'بصمة مطابقة', 'tone' => 'ok',
               'why' => 'أُعيد حسابُ بصمة القيد من محتواه المخزّن فطابقت المختومة']
            : ['status' => 'tampered', 'label' => '⚠️ عبث', 'tone' => 'bad',
               'why' => 'بصمةُ القيد لا تطابق محتواه — عُدِّل مباشرةً في القاعدة بعد الختم'];
    }

    protected static function unsealedAfterEpoch(): int
    {
        if (! Schema::hasColumn('audit_chain', 'started_at')) return 0;
        $epoch = DB::table('audit_chain')->where('id', 1)->value('started_at');

        return $epoch ? AuditEntry::whereNull('hash')->where('created_at', '>=', $epoch)->count() : 0;
    }

    // ── عرض القيم ──

    protected static function decode($v): array
    {
        if (is_array($v)) return $v;
        if (! is_string($v) || $v === '') return [];
        $d = json_decode($v, true);

        return is_array($d) ? $d : [];
    }

    protected static function show(string $col, $v): string
    {
        if (in_array($col, self::MASKED, true)) return $v === null || $v === '' ? '—' : '••• مخفيّ';
        if ($v === null || $v === '') return '—';
        if (is_bool($v)) return $v ? 'نعم' : 'لا';
        // المُطهِّرُ الواحد هنا لا في متحكّمٍ بعينه (WP-5.4 مُعمَّماً): رمزٌ داخل
        // قيمةٍ بريئة (lyn_/JWT/token=) يُطمَس لدى **كلِّ** عارضٍ للفرق — القائمة
        // والتفصيل سواء. والطمسُ **قبل** القصّ: رمزٌ بُتر نصفُه يفلت من نمطه.
        if (is_array($v)) return \Illuminate\Support\Str::limit(
            \App\Support\Redactor::text((string) json_encode($v, JSON_UNESCAPED_UNICODE)), 120);

        $s = (string) $v;
        // المعرّفات الطويلة تُستبدل باسم صاحبها متى أمكن — «uuid ← uuid» لا يقول شيئاً
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', $s) && str_ends_with($col, '_id')) {
            return self::refLabel($col, $s) ?? \Illuminate\Support\Str::limit($s, 12);
        }

        return \Illuminate\Support\Str::limit(\App\Support\Redactor::text($s), 140);
    }

    /** اسمٌ لمعرّفٍ مرجعي — يُقرأ من الجدول المرجَّح للعمود، بلا كسرٍ إن غاب */
    protected static function refLabel(string $col, string $id): ?string
    {
        static $memo = [];
        if (isset($memo[$id])) return $memo[$id];

        $guess = ['project_id' => ['projects', 'name'], 'user_id' => ['users', 'name'],
                  'assignee_id' => ['users', 'name'], 'company_id' => ['companies', 'name_ar'],
                  'client_id' => ['clients', 'name'], 'app_id' => ['applications', 'name'],
                  'server_id' => ['servers', 'name'], 'emp_id' => ['employees', 'name'],
                  'employee_id' => ['employees', 'name'], 'supplier_id' => ['suppliers', 'name']][$col] ?? null;
        if (! $guess) return null;

        try {
            [$table, $field] = $guess;
            if (! Schema::hasTable($table)) return null;

            return $memo[$id] = DB::table($table)->where('id', $id)->value($field) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected static function humanCol(string $col): string
    {
        return ['status' => 'الحالة', 'title' => 'العنوان', 'name' => 'الاسم', 'name_ar' => 'الاسم',
                'notes' => 'ملاحظات', 'amount' => 'المبلغ', 'total' => 'الإجمالي', 'progress' => 'التقدم',
                'priority' => 'الأولوية', 'due' => 'الاستحقاق', 'archived' => 'مؤرشف',
                'deleted_at' => 'الحذف', 'created_at' => 'الإنشاء'][$col] ?? $col;
    }
}
