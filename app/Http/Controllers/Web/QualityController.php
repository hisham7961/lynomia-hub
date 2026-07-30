<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** مركز جودة البيانات: مكررات ونواقص وركود — مع دمج آمن للعملاء المكررين */
class QualityController extends Controller
{
    protected function gate(): void
    {
        abort_unless(auth()->user()?->role?->is_owner, 403, 'مركز جودة البيانات للمالكين فقط');
    }

    public function index()
    {
        $this->gate();

        // ١) عملاء مكررون: نفس الاسم المطبع أو نفس البريد/الهاتف غير الفارغ
        $groups = [];
        $clients = Client::whereNull('deleted_at')->get(['id', 'name', 'email', 'phone', 'created_at']);
        foreach (['norm' => fn ($c) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $c->name))),
                  'email' => fn ($c) => mb_strtolower(trim((string) $c->email)),
                  'phone' => fn ($c) => preg_replace('/\D+/', '', (string) $c->phone)] as $kind => $fn) {
            foreach ($clients->groupBy($fn) as $key => $g) {
                if ($key === '' || $g->count() < 2) continue;
                $ids = $g->pluck('id')->sort()->values()->implode(',');
                $groups[$ids] = ['by' => $kind, 'rows' => $g->sortBy('created_at')->values()];
            }
        }

        // ٢) فحوص النواقص — كل فحص: عنوان، وحدة للرابط، عدد، عينة
        $checks = [];
        $mk = function (string $label, string $module, $q, string $hint = '') use (&$checks) {
            $count = (clone $q)->count();
            if (! $count) return;
            $disp = hub_display_col($module);
            $checks[] = ['label' => $label, 'module' => $module, 'count' => $count, 'hint' => $hint,
                         'sample' => $q->limit(8)->get()->map(fn ($r) => ['id' => $r->id, 'name' => $r->{$disp} ?? $r->id])];
        };

        foreach ([['clients', 'عملاء بلا شركة'], ['projects', 'مشاريع بلا شركة'], ['hr', 'موظفون بلا شركة']] as [$m, $lbl]) {
            $def = hub_mod($m);
            $mk($lbl, $m, DB::table($def['table'])->whereNull('deleted_at')->whereNull('company_id')
                ->select('id', hub_display_col($m)), 'اربط السجل بشركته ليظهر في تقاريرها');
        }
        $mk('موظفون بلا مدير مباشر', 'hr',
            DB::table('employees')->whereNull('deleted_at')->whereNull('manager_id')->select('id', 'name'),
            'المدير المباشر يلزم لمسارات الإجازات والتقييم');
        $mk('مشاريع بلا مسؤول', 'projects',
            DB::table('projects')->whereNull('deleted_at')->whereNull('manager_id')->select('id', 'name'),
            'مشروع بلا مسؤول = لا أحد يُسأل عنه');
        $mk('دومينات بلا تاريخ انتهاء', 'domains',
            DB::table('domains')->whereNull('deleted_at')->whereNull('expiry')->select('id', 'name'),
            'بلا تاريخ لن يصلك تنبيه تجديد — خطر فقدان الدومين');
        $mk('فواتير بلا طرف (عميل/مورد)', 'fin',
            DB::table('fin_documents')->whereNull('deleted_at')->where('kind', 'فاتورة')
                ->where(fn ($w) => $w->whereNull('partner')->orWhere('partner', ''))->select('id', 'doc_no'),
            'حدد الطرف ليصح كشف الحساب');
        $mk('بريد عملاء غير صالح', 'clients',
            DB::table('clients')->whereNull('deleted_at')->whereNotNull('email')->where('email', '!=', '')
                ->where('email', 'NOT LIKE', '%_@_%._%')->select('id', 'name'),
            'صحح الصيغة أو أفرغ الحقل');
        $mk('عملاء راكدون (بلا أي تحديث ٩٠ يوماً)', 'clients',
            DB::table('clients')->whereNull('deleted_at')->where('updated_at', '<', now()->subDays(90))
                ->select('id', 'name'),
            'حدّث الموقف أو أرشف بحذف ناعم');

        return view('admin.quality', ['groups' => $groups, 'checks' => $checks,
                                      'clean' => empty($groups) && empty($checks)]);
    }

    /** دمج مكررات عميل: يبقى الأساسي، تُعاد الإشارات إليه، وتُملأ فراغاته من المدموجين ثم يُحذفون ناعماً */
    public function merge(Request $r)
    {
        $this->gate();
        $d = $r->validate(['keep' => ['required', 'uuid'], 'ids' => ['required', 'string', 'max:2000']]);

        $keep = Client::whereNull('deleted_at')->findOrFail($d['keep']);
        $others = collect(explode(',', $d['ids']))->map(fn ($x) => trim($x))
            ->filter(fn ($x) => $x !== '' && $x !== $keep->id)->unique()->values();
        abort_if($others->isEmpty(), 422, 'لا سجلات لدمجها');

        $moved = 0;
        DB::transaction(function () use ($keep, $others, &$moved) {
            foreach ($others as $oid) {
                $dup = Client::whereNull('deleted_at')->find($oid);
                if (! $dup) continue;

                // إعادة توجيه كل الإشارات المسجلة في سجل الوحدات + التعليقات
                foreach (config('hub.modules') as $md) {
                    foreach ($md['fields'] as $f) {
                        if (($f['ref'] ?? null) !== 'clients' || ($f['type'] ?? '') !== 'ref') continue;
                        $moved += DB::table($md['table'])->where($f['col'], $dup->id)->update([$f['col'] => $keep->id]);
                    }
                }
                $moved += DB::table('comments')->where('module', 'clients')->where('record_id', $dup->id)
                    ->update(['record_id' => $keep->id]);

                // ملء فراغات الأساسي من المدموج (لا استبدال لقيم موجودة)
                foreach (['contact', 'email', 'phone', 'country', 'company_id', 'owner_id', 'source', 'notes'] as $col) {
                    if (blank($keep->{$col}) && filled($dup->{$col})) $keep->{$col} = $dup->{$col};
                }

                $dup->delete();   // ناعم — يمر بالتدقيق ويمكن استرجاعه من السلة
            }
            $keep->save();
        });

        return back()->with('ok', "دُمجت {$others->count()} نسخة في «{$keep->name}» وأُعيد توجيه {$moved} إشارة — النسخ المدموجة في سلة المحذوفات");
    }
}
