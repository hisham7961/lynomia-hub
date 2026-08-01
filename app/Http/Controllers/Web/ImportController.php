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
        $fields = collect($def['fields'])->keyBy('key');

        $ok = 0; $skipped = [];
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

                $val = $this->cast($f, $v, $rowErr);
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
            $allowed = hub_company_ids();
            if ($allowed !== null && \Illuminate\Support\Facades\Schema::hasColumn($def['table'], 'company_id')) {
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

        @unlink(storage_path('app/' . $sess['path']));
        $r->session()->forget('import');

        hub_audit('استيراد', $module, null,
            "{$ok} سجل" . ($skipped ? ' (' . count($skipped) . ' متخطى)' : ''));

        return view('import.result', compact('module', 'def', 'ok', 'skipped'));
    }

    /** تحويل قيمة نصية من الملف حسب نوع الحقل — المراجع تُحل بالاسم */
    protected function cast(array $f, string $v, ?string &$err)
    {
        switch ($f['type']) {
            case 'num': case 'big':
                if (! is_numeric(str_replace(['،', ','], ['', ''], $v))) { $err = '«' . $f['label'] . '»: قيمة غير رقمية (' . Str::limit($v, 20) . ')'; return null; }
                return (float) str_replace(['،', ','], ['', ''], $v);

            case 'date': case 'dt':
                try { return \Illuminate\Support\Carbon::parse($v)->toDateString(); }
                catch (\Throwable $e) { $err = '«' . $f['label'] . '»: تاريخ غير مفهوم (' . Str::limit($v, 20) . ')'; return null; }

            case 'bool':
                return in_array(mb_strtolower($v), ['1', 'نعم', 'yes', 'true', 'صح'], true);

            case 'tags':
                return array_values(array_filter(array_map('trim', preg_split('/[,،;]/u', $v))));

            case 'ref':
                $table = hub_ref_table($f['ref']);
                if (! $table) return null;
                // معرف مباشر أو اسم عرض
                $hit = DB::table($table)->where('id', $v)->value('id')
                    ?? DB::table($table)->whereNull('deleted_at')->where(hub_ref_display($f['ref']), $v)->value('id');
                if (! $hit) { $err = '«' . $f['label'] . '»: لا يوجد سجل باسم «' . Str::limit($v, 25) . '»'; return null; }
                if (! empty($f['multi'])) return [$hit];
                return $hit;

            case 'file': case 'img': case 'sec':
                return null;   // لا تُستورد

            default:
                return $v;
        }
    }
}
