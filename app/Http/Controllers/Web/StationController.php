<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Models\StationAssignment;
use App\Models\User;
use App\Support\FlowRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * **المحطات — المقعدُ الدائم** (Work OS · الطور F · WP-F.1 · §25–27).
 *
 * الـCRUD/العرض/التصدير تخدمها `ModuleController` (وحدةُ `stations` في السجل)؛ هذا
 * المتحكّمُ يضيف ما لا يقدر عليه المحرّكُ العامّ فقط:
 *
 *   · **`s/{code}`** — مسحُ ملصق المحطة: كودٌ ← صفحتُها. مُنطَّقٌ كغيره (خارجَ
 *     الشركة ٤٠٤ لا كشفَ وجود)، **داخلَ auth** (QR يتطلّب دخولاً) — نظيرُ
 *     `CustodyController::byCode` (`c/{code}`) حرفاً.
 *   · **الإسناد/الإخلاء** — تغييرُ شاغلِ المقعد عبر **معاملةٍ مقفلةٍ مُدقَّقة** (نمطُ
 *     `Custody::move` DB::transaction): تكتب صفَّ تاريخٍ في `station_assignments`
 *     **وتحدّث** `stations.current_employee_id` معاً، فلا يتغيّر مقعدٌ بلا أثر. هذا
 *     هو الطريقُ الوحيدُ لكتابة `current_employee_id` (الحقلُ مقفلٌ في السجل عن CRUD).
 *
 * **الحرّاسُ على كلّ مسار (لا إخفاءَ رابط):**
 *   · `hub_can('stations', v/e)` — الصلاحيةُ من مصفوفة الدور.
 *   · عزلُ الشركة: `hub_scope(Station::query(),'stations')->findOrFail` — لمحطةِ
 *     شركةٍ أجنبيةٍ ٤٠٤ (IDOR).
 *   · العميلُ (`account_type=client`) ٤٠٤ على كلّ مسارٍ هنا: `PortalGuard` قائمةٌ
 *     بيضاء، والمحطاتُ **ليست** فيها — فوق المصفوفة (داخليّةٌ فقط).
 */
class StationController extends Controller
{
    /** المحطةُ بنطاق القارئ — بوّابةُ العزل: شركةٌ أجنبيةٌ ٤٠٤ لا كشفَ وجود */
    protected function scoped(?string $id): Station
    {
        abort_unless(filled($id), 404);

        return hub_scope(Station::query(), 'stations')->findOrFail($id);
    }

    /**
     * مسحُ الملصق: كودٌ ← صفحةُ المحطة. مُنطَّقٌ كغيره — وكودٌ خارج نطاق القارئ
     * يردّ ٤٠٤ لا صفحةً ولا رسالةً تُثبت وجودَه (نظيرُ `CustodyController::byCode`).
     */
    public function byCode(string $code)
    {
        abort_unless(hub_can(auth()->user(), 'stations', 'v'), 403, 'لا تملك عرض المحطات');

        $s = hub_scope(Station::query(), 'stations')->where('code', $code)->firstOrFail();

        return redirect()->route('m.show', ['stations', $s->id]);
    }

    /**
     * إسنادُ المحطة لموظف — **معاملةٌ واحدةٌ مقفلة** (نمطُ `Custody::move`): تُقفل
     * المحطةُ (`lockForUpdate`) فلا يسندها معالجان معاً، ثم تُكتب حركةُ الإسناد
     * ويُحدَّث `current_employee_id` — الأثرُ والحالةُ معاً أو لا شيء.
     */
    public function assign(Request $r, string $id)
    {
        abort_unless(hub_can(auth()->user(), 'stations', 'e'), 403, 'لا تملك تعديل المحطات');
        $station = $this->scoped($id);

        $d = $r->validate([
            'user_id' => ['required', 'uuid', 'exists:users,id'],
            'note'    => ['nullable', 'string'],
        ]);

        // داخليّةٌ فقط: لا يُسنَد مقعدٌ داخليٌّ لحساب عميل (يُحسَم بالتصنيف الصلب لا الاستنتاج)
        $user = User::find($d['user_id']);
        if (! $user || hub_is_client($user)) {
            throw ValidationException::withMessages(['user_id' => 'المحطاتُ داخليّةٌ — لا تُسنَد لحساب عميل']);
        }

        DB::transaction(function () use ($station, $user, $d) {
            // إقفالُ الصفِّ يمنع سباقَ الإسناد المتزامن (نمطُ الحركة المقفلة)
            $locked = Station::whereKey($station->id)->lockForUpdate()->firstOrFail();

            StationAssignment::create([
                'station_id' => $locked->id,
                'user_id'    => $user->id,
                'company_id' => $locked->company_id,
                'action'     => 'assign',
                'at'         => now(),
                'note'       => isset($d['note']) && $d['note'] !== '' ? hub_fit((string) $d['note'], 500) : null,
                'by_id'      => auth()->id(),
            ]);

            $locked->current_employee_id = $user->id;
            $locked->save();
            $station->setRawAttributes($locked->getAttributes(), true);
        });

        hub_audit('إسناد محطة', 'stations', $station->id, (string) $station->code,
            ['after' => ['user_id' => $user->id]]);
        $this->fire('assigned', $station);

        return redirect()->route('m.show', ['stations', $station->id])
            ->with('ok', 'أُسنِدت المحطةُ «' . $station->code . '» إلى ' . $user->name);
    }

    /**
     * إخلاءُ المحطة — يُفرّغ الشاغلَ ويكتب حركةَ إخلاءٍ تحفظ من كان يجلس، في المعاملةِ
     * المقفلةِ نفسها. حركةُ الإخلاء تبقى أثراً بعد مغادرة الموظف.
     */
    public function vacate(Request $r, string $id)
    {
        abort_unless(hub_can(auth()->user(), 'stations', 'e'), 403, 'لا تملك تعديل المحطات');
        $station = $this->scoped($id);

        $d = $r->validate(['note' => ['nullable', 'string']]);

        DB::transaction(function () use ($station, $d) {
            $locked = Station::whereKey($station->id)->lockForUpdate()->firstOrFail();

            StationAssignment::create([
                'station_id' => $locked->id,
                // من كان يجلس — يُحفَظ في صفِّ الإخلاء فلا يضيع بتفريغ current_employee_id
                'user_id'    => $locked->current_employee_id,
                'company_id' => $locked->company_id,
                'action'     => 'vacate',
                'at'         => now(),
                'note'       => isset($d['note']) && $d['note'] !== '' ? hub_fit((string) $d['note'], 500) : null,
                'by_id'      => auth()->id(),
            ]);

            $locked->current_employee_id = null;
            $locked->save();
            $station->setRawAttributes($locked->getAttributes(), true);
        });

        hub_audit('إخلاء محطة', 'stations', $station->id, (string) $station->code);
        $this->fire('vacated', $station);

        return redirect()->route('m.show', ['stations', $station->id])
            ->with('ok', 'أُخليت المحطةُ «' . $station->code . '»');
    }

    /** بثُّ الحدث الدلاليّ — لا يكسر العمليةَ الأصلية أبداً (نمطُ CustodyPostingService) */
    protected function fire(string $event, Station $station): void
    {
        try {
            FlowRunner::fire($event, 'stations', $station);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
