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
        abort_unless(hub_is_owner(), 403, 'مركز جودة البيانات للمالكين فقط');
    }

    public function index(Request $r)
    {
        $this->gate();

        // ١) عملاء مكررون: نفس الاسم المطبع أو نفس البريد/الهاتف غير الفارغ
        $groups = [];
        // صفوف خام لا نماذج: تحميل النماذج كان ~٢.٦ كيلوبايت للصف (٥٠ ميجابايت لعشرين ألف عميل)
        $clients = collect(DB::table('clients')->whereNull('deleted_at')
            ->limit(50000)->get(['id', 'name', 'email', 'phone', 'created_at']));
        foreach (['norm' => fn ($c) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $c->name))),
                  'email' => fn ($c) => mb_strtolower(trim((string) $c->email)),
                  'phone' => fn ($c) => preg_replace('/\D+/', '', (string) $c->phone)] as $kind => $fn) {
            foreach ($clients->groupBy($fn) as $key => $g) {
                if ($key === '' || $g->count() < 2) continue;
                $ids = $g->pluck('id')->sort()->values()->implode(',');
                $groups[$ids] = ['by' => $kind, 'rows' => $g->sortBy('created_at')->values()];
            }
        }

        // ٢) المسح المشتقّ من سجل الوحدات — كل وحدةٍ وكل حقل، لا ثلاث وحدات
        $scan = \App\Support\DataQuality::scan((bool) $r->query('fresh'));

        return view('admin.quality', [
            'groups'  => $groups,
            'checks'  => $scan['checks'],
            'byMod'   => $scan['byModule'],
            'totals'  => $scan['totals'],
            'history' => \App\Support\DataQuality::history(),
            'clean'   => empty($groups) && empty($scan['checks']),
        ]);
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
                        $n = DB::table($md['table'])->where($f['col'], $dup->id)->update([$f['col'] => $keep->id]);
                        // كتابةٌ خام لا تُطلق أحداث Eloquent — يُرفع ختم الجدول يدوياً
                        if ($n) hub_data_bump($md['table']);
                        $moved += $n;
                    }
                }
                // المرفقات الحيّة المشيرة للسجل بـ (وحدة + معرّف) خارج سجل الوحدات.
                // نتعمّد استثناء التدقيق والإصدارات: تاريخٌ يبقى ملتصقاً بالسجل الأصلي.
                foreach (['comments', 'inbox_documents'] as $t) {
                    $moved += DB::table($t)->where('module', 'clients')->where('record_id', $dup->id)
                        ->update(['record_id' => $keep->id]);
                }

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
