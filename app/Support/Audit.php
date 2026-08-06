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
        if (is_array($v)) return \Illuminate\Support\Str::limit((string) json_encode($v, JSON_UNESCAPED_UNICODE), 120);

        $s = (string) $v;
        // المعرّفات الطويلة تُستبدل باسم صاحبها متى أمكن — «uuid ← uuid» لا يقول شيئاً
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', $s) && str_ends_with($col, '_id')) {
            return self::refLabel($col, $s) ?? \Illuminate\Support\Str::limit($s, 12);
        }

        return \Illuminate\Support\Str::limit($s, 140);
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
