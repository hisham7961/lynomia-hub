<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * ── Control Plane: Phase 6 (WP-6.2) ──
 * **ربطُ الأدلّة بالحادثة** (§8.2): زرُّ «اربط بحادثة» على صفحات المصادر
 * (خطأ / قيد تدقيق / حدث أمنيّ) يكتب صفَّ وصلٍ في `incident_links` يقرؤه
 * الخطُّ الزمنيّ الموحَّد وبطاقةُ الأثر.
 *
 * — الصلاحية **والنطاق** معاً: `hub_can('incidents','e')` تقول «يحرّر الحوادث»
 *   لا «يحرّر **هذه** الحادثة» — فالسجلُّ يُقرأ عبر `hub_scope` كما يفعل
 *   `ModuleController::findScoped` في كلّ كاتبٍ آخر. بدونها كان محرّرٌ منطَّقٌ
 *   بالمشاريع (يرى ٤٠٤ على صفحة الحادثة) يكتب فيها صفَّ وصلٍ **ويقرأ عنوانَها**
 *   من رسالة النجاح: كتابةٌ خارج النطاق وتسريبُ عنوانٍ في مسارٍ واحد.
 * — **الفريدُ المنطقيّ** على (incident_id, kind, ref): إعادةُ الربط بنفس
 *   المرجع تُحدّث الملخّصَ ولا تكرّر الصفّ (الملاحظاتُ الحرّة بلا ref تُضاف
 *   دائماً — فلا مرجعَ يُفرَد عليه).
 * — **القصُّ عند الكاتب** (critic #36): `summary` بعرض عموده (٣٠٠) بـ
 *   `mb_substr` لا اعتماداً على بتر SQLite الصامت — MySQL الصارمة ترمي.
 */
class IncidentLinkController extends Controller
{
    /** أنواع الدليل المقبولة — مرآةُ تعليق عمود kind في الهجرة */
    public const KINDS = ['error', 'audit', 'security', 'request', 'alert', 'task', 'deploy', 'note'];

    public function store(Request $r, string $id)
    {
        abort_unless(hub_can(auth()->user(), 'incidents', 'e'), 403, 'ربطُ الأدلّة يتطلب صلاحيةَ تحرير الحوادث');
        // النطاقُ قبل الكتابة: حادثةٌ خارجَه ٤٠٤ كصفحتها — لا صفٌّ يُكتب فيها ولا عنوانٌ يُقرأ منها
        $i = hub_scope(Incident::whereNull('deleted_at'), 'incidents')->findOrFail($id);

        $data = $r->validate([
            'kind' => ['required', Rule::in(self::KINDS)],
            // الوحدةُ من السجلّ وحدَه — لا نصَّ حرّاً يصير «وحدةً» في العرض
            'module' => ['nullable', 'string', Rule::in(array_keys((array) config('hub.modules')))],
            'record_id' => ['nullable', 'uuid'],
            'ref' => ['nullable', 'string', 'max:400'],
            'summary' => ['required', 'string', 'max:4000'],
        ]);

        // القصُّ عند الكاتب (critic #36) — بعرض العمودين المعلنَين في الهجرة
        $ref = isset($data['ref']) && $data['ref'] !== '' ? mb_substr((string) $data['ref'], 0, 120) : null;
        $fresh = [
            'module' => $data['module'] ?? null,
            'record_id' => $data['record_id'] ?? null,
            'summary' => mb_substr(trim((string) $data['summary']), 0, 300),
            'by' => auth()->id(),
        ];

        // الفريدُ المنطقيّ (incident_id, kind, ref): تحديثٌ لا تكرار
        $existing = $ref !== null
            ? DB::table('incident_links')->where('incident_id', $i->id)
                ->where('kind', $data['kind'])->where('ref', $ref)
                ->orderBy('id')->first(['id'])
            : null;

        if ($existing) {
            DB::table('incident_links')->where('id', $existing->id)->update($fresh);
            hub_audit('تحديث ربط دليل بحادثة', 'incidents', (string) $i->id,
                $data['kind'] . ($ref !== null ? ' — ' . $ref : ''));

            return back()->with('ok', 'الدليلُ مربوطٌ سلفاً — حُدّث ملخّصُه ولم يُكرَّر');
        }

        DB::table('incident_links')->insert($fresh + [
            'incident_id' => (string) $i->id,
            'kind' => $data['kind'],
            'ref' => $ref,
            'created_at' => now(),
        ]);
        hub_audit('ربط دليل بحادثة', 'incidents', (string) $i->id,
            $data['kind'] . ($ref !== null ? ' — ' . $ref : ''));

        return back()->with('ok', 'رُبط الدليلُ بالحادثة «' . \Illuminate\Support\Str::limit($i->title, 60) . '»');
    }
}
