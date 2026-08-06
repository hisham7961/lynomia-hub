<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Sheet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * معالج الاستيراد لأي وحدة: رفع CSV/Excel ← مطابقة أعمدة (تلقائية قابلة للتعديل) ← تنفيذ.
 * الحقول المرجعية تُحل بالاسم (اسم الشركة في الملف ← معرّفها في القاعدة).
 */
class ImportController extends Controller
{
    /** خبيئة حلّ المراجع خلال طلب الاستيراد الواحد (جدول|قيمة ← معرف) */
    protected array $refCache = [];

    protected function resolve(string $module): array
    {
        $def = hub_mod($module);
        abort_if(! $def || $module === 'users', 404);
        abort_unless(hub_can(auth()->user(), $module, 'a'), 403, 'الاستيراد يتطلب صلاحية إضافة');
        abort_if(hub_scoped(auth()->user()), 403, 'الاستيراد متاح للحسابات كاملة النطاق');
        $class = '\\App\\Models\\' . $def['model'];
        abort_unless(class_exists($class), 404);

        return [$def, $class];
    }

    /** ١) نموذج الرفع */
    public function form(string $module)
    {
        [$def] = $this->resolve($module);

        return view('import.form', compact('module', 'def'));
    }

    /** ٢) قراءة الملف وعرض شاشة المطابقة */
    public function map(Request $r, string $module)
    {
        [$def] = $this->resolve($module);
        $r->validate(['file' => ['required', 'file', 'max:20480']]);

        $ext = strtolower($r->file('file')->getClientOriginalExtension());
        abort_unless(in_array($ext, ['csv', 'txt', 'xlsx'], true), 422, 'الملفات المدعومة: CSV أو Excel (xlsx)');

        // تنظيف اليتيم: ملفٌ رُفع ثم هُجرت شاشة المطابقة كان يبقى ببياناته
        // التجارية الخام على القرص إلى الأبد — يوم كامل مهلةً ثم يُمحى
        foreach (glob(storage_path('app/imports/*')) ?: [] as $old) {
            if (@filemtime($old) < time() - 86400) @unlink($old);
        }

        $stored = $r->file('file')->store('imports', 'local');   // القرص الخاص صراحةً — الافتراضي قد يكون public
        $rows = Sheet::read(storage_path('app/' . $stored), $ext);
        abort_if(count($rows) < 2, 422, 'الملف فارغ أو بلا صفوف بيانات تحت سطر العناوين');

        $headers = $rows[0];
        $sample  = array_slice($rows, 1, 3);
        $r->session()->put('import', ['module' => $module, 'path' => $stored, 'ext' => $ext]);

        // مطابقة تلقائية: عنوان الملف يطابق تسمية الحقل أو مفتاحه
        $auto = [];
        foreach ($headers as $i => $h) {
            $hNorm = mb_strtolower(trim($h));
            foreach ($def['fields'] as $f) {
                if ($hNorm !== '' && (mb_strtolower($f['label']) === $hNorm || mb_strtolower($f['key']) === $hNorm
                    || mb_stripos($f['label'], $h) !== false)) { $auto[$i] = $f['key']; break; }
            }
        }

        return view('import.map', compact('module', 'def', 'headers', 'sample', 'auto') + ['total' => count($rows) - 1]);
    }

    /** ٣) التنفيذ حسب المطابقة المختارة */
    public function run(Request $r, string $module)
    {
        [$def, $class] = $this->resolve($module);
        $sess = $r->session()->get('import');
        abort_unless($sess && $sess['module'] === $module && file_exists(storage_path('app/' . $sess['path'])), 422, 'انتهت جلسة الاستيراد — ارفع الملف من جديد');

        $mapping = array_filter((array) $r->input('map', []), fn ($v) => $v !== '');   // colIndex => fieldKey
        abort_unless(count($mapping), 422, 'اختر حقلاً واحداً على الأقل');

        $rows = Sheet::read(storage_path('app/' . $sess['path']), $sess['ext']);
        array_shift($rows);
        // سقفٌ صريح: حدّ الرفع 20MB كان يسمح بمئات آلاف الصفوف في طلبٍ واحد
        abort_if(count($rows) > 5000, 422, 'الملف يتجاوز ٥٠٠٠ صف — قسّمه على دفعات أصغر');
        $fields = collect($def['fields'])->keyBy('key');

        // خارج الحلقة لا داخلها: فحص العمود استعلام information_schema ثابت
        // النتيجة، وكان يُنفَّذ لكل صف؛ وحلّ المراجع يُخبّأ فاسم الشركة المتكرر
        // في آلاف الصفوف لا يُستعلم إلا مرة
        $hasCompany = \Illuminate\Support\Facades\Schema::hasColumn($def['table'], 'company_id');
        $allowed = hub_company_ids();
        $this->refCache = [];

        $ok = 0; $skipped = [];
        // معاملةٌ واحدة: عطلٌ في المنتصف (قيمة أطول من عمودها على MySQL الصارم،
        // خرق unique) كان يترك استيراداً جزئياً صامتاً — الصفوف السابقة مكتوبة
        // والملف باقٍ والجلسة حية، فإعادة المحاولة تُدرج المكرر. الآن كلٌّ أو لا شيء.
        try {
            DB::transaction(function () use ($rows, $mapping, $fields, $def, $class, $module, $hasCompany, $allowed, &$ok, &$skipped) {
                foreach ($rows as $n => $line) {
                    $m = new $class;
                    $rowErr = null;

                    foreach ($mapping as $i => $fk) {
                        $f = $fields[$fk] ?? null;
                        if (! $f) continue;
                        // صلاحية الحقل تسري على الاستيراد كما تسري على النموذج: ملفٌّ
                        // بعمودٍ اسمه «الراتب» كان يكتبه لمن لا يملك حتى رؤيته
                        if (hub_field_mode(auth()->user(), $module, (string) $fk) !== '') continue;
                        $v = trim((string) ($line[(int) $i] ?? ''));
                        if ($v === '') continue;

                        $val = $this->cast($f, $v, $rowErr, $def['table']);
                        if ($rowErr) break;
                        $m->{$f['col']} = $val;
                    }

                    // الحقول الإلزامية
                    if (! $rowErr) {
                        foreach ($def['fields'] as $f) {
                            if (! empty($f['required']) && ($m->{$f['col']} ?? null) === null) {
                                $rowErr = 'حقل «' . $f['label'] . '» إلزامي وفارغ';
                                break;
                            }
                        }
                    }

                    // **عزل الشركات يسري على الإنشاء كما على القراءة**: المستوردُ المعزول
                    // كان يكتب سجلاً داخل شركةٍ لا يراها لمجرّد أن الملف يسمّيها، فيدسّ
                    // بياناتٍ في كيانٍ ليس له — والنتيجة لا تظهر له بعدها فلا يُكتشف.
                    if ($allowed !== null && $hasCompany) {
                        $cid = (string) ($m->company_id ?? '');
                        if ($cid !== '' && ! in_array($cid, $allowed, true)) {
                            $skipped[] = 'صف ' . ($n + 2) . ': شركة خارج نطاقك';
                            continue;
                        }
                        if ($cid === '') $m->company_id = $allowed[0];      // شركته الافتراضية لا الفراغ
                    }

                    if ($rowErr) { $skipped[] = 'صف ' . ($n + 2) . ': ' . $rowErr; continue; }
                    $m->save();
                    $ok++;
                }
            });
        } finally {
            // التنظيف يقع نجح الاستيراد أم انفجر — كان الملف والجلسة يبقيان بعد العطل
            @unlink(storage_path('app/' . $sess['path']));
            $r->session()->forget('import');
        }

        hub_audit('استيراد', $module, null,
            "{$ok} سجل" . ($skipped ? ' (' . count($skipped) . ' متخطى)' : ''));

        return view('import.result', compact('module', 'def', 'ok', 'skipped'));
    }

    /** تحويل قيمة نصية من الملف حسب نوع الحقل — المراجع تُحل بالاسم */
    protected function cast(array $f, string $v, ?string &$err, ?string $table = null)
    {
        switch ($f['type']) {
            case 'num': case 'big':
                $clean = str_replace(['،', ','], ['', ''], $v);
                // hub_num ترفض غير المنتهي (`1e400` → INF) وغير الرقميّ معاً
                $n = hub_num($clean);
                if ($n === null) { $err = '«' . $f['label'] . '»: قيمة غير رقمية (' . Str::limit($v, 20) . ')'; return null; }
                // **ومدى العمود** (v2.325): كان الطولُ النصّيّ وحده يُفحَص، فقيمةٌ
                // فائضة تُقبل على SQLite وتُسقط **الدفعة كلَّها** بـ22003 على
                // MySQL — والعطلُ في الإنتاج وحده بينما الحزمةُ خضراء.
                if ($table && ($nm = hub_col_num_max($table, (string) $f['col'])) !== null && abs($n) > (float) $nm) {
                    $err = '«' . $f['label'] . '»: خارج مدى العمود (أقصاه ' . $nm . ')';
                    return null;
                }

                return $n;

            case 'date': case 'dt':
                // تواريخ Excel التسلسلية: خلية التاريخ في xlsx رقمٌ (45292 = 2024-01-01)
                // لا نص — كانت تُرفض «تاريخ غير مفهوم» فتتخطى كل صفوف ملف Excel أصلي
                if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) {
                    return \Illuminate\Support\Carbon::create(1899, 12, 30)->addDays((int) $v)->toDateString();
                }
                try { return \Illuminate\Support\Carbon::parse($v)->toDateString(); }
                catch (\Throwable $e) { $err = '«' . $f['label'] . '»: تاريخ غير مفهوم (' . Str::limit($v, 20) . ')'; return null; }

            case 'bool':
                return in_array(mb_strtolower($v), ['1', 'نعم', 'yes', 'true', 'صح'], true);

            case 'tags':
                return array_values(array_filter(array_map('trim', preg_split('/[,،;]/u', $v))));

            case 'ref':
                $refKey = (string) $f['ref'];
                $refClass = '\\App\\Models\\' . (string) (hub_mod($refKey)['model'] ?? '');
                if (! class_exists($refClass)) return null;
                // **النطاق وعزل الشركات يسريان على حلّ المرجع كما على القراءة**: كان
                // بـDB::table الخام، فالاسم يُحلُّ عبر كل الشركات (ربطٌ/تسريب لكيانٍ
                // خارج العزل) وعند تكراره «قرعة» بلا orderBy. الآن Eloquent المنطَّق
                // (SoftDeletes تُقصي المحذوف) + orderBy('id') حاسمٌ عند التساوي.
                $ck = $refKey . '|' . $v;
                $hit = $this->refCache[$ck] ??= (function () use ($refClass, $refKey, $v) {
                    $scoped = fn () => hub_company_scope(hub_scope($refClass::query(), $refKey), $refKey);

                    return $scoped()->whereKey($v)->value('id')
                        ?? $scoped()->where(hub_ref_display($refKey), $v)->orderBy('id')->value('id')
                        ?? false;
                })();
                if (! $hit) { $err = '«' . $f['label'] . '»: لا يوجد سجل باسم «' . Str::limit($v, 25) . '»'; return null; }
                if (! empty($f['multi'])) return [$hit];
                return $hit;

            case 'file': case 'img': case 'sec':
                return null;   // لا تُستورد

            default:
                // فحص الطول مقابل عرض العمود: قيمةٌ أطول تُقبل صامتة على SQLite وتنفجر
                // 22001 على MySQL الصارمة فتُلغى المعاملة كلها. تُتخطّى بوضوح كأي خطأ صف.
                if ($table && ($max = hub_col_max($table, (string) $f['col'])) && mb_strlen($v) > $max) {
                    $err = '«' . $f['label'] . '»: أطول من حدّ العمود (' . $max . ' حرف)';
                    return null;
                }

                // **وقائمةُ الخيارات المعرَّفة** (v2.325): كان الاستيرادُ يزرع في
                // حقول `sel` حالاتٍ لا يعرفها السجل — لا تظهر في مرشِّح ولا في
                // لوحةٍ، والسجلُّ يعلق في حالةٍ لا مخرجَ منها.
                if (($f['type'] ?? '') === 'sel' && ! empty($f['options'])
                    && ! in_array($v, (array) $f['options'], true)) {
                    $err = '«' . $f['label'] . '»: قيمةٌ خارج الخيارات المعرَّفة ('
                         . Str::limit(implode('، ', (array) $f['options']), 60) . ')';
                    return null;
                }

                return $v;
        }
    }
}
