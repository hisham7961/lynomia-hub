<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Acks;
use Illuminate\Http\Request;

/**
 * الإقرار على سجل: «اعتمدتُ المحضر» · «استلمتُ العهدة» · «علمتُ بالقرار».
 * يُسجَّل بدليله (وقتٌ وعنوانٌ وجهاز) ويترك أثراً في التدقيق — إثباتٌ لا ذاكرة.
 */
class AckController extends Controller
{
    /** أقصى من يُذكَّر في الضغطة الواحدة، ومهلةُ التبريد بين ضغطتين على السجل نفسه */
    public const REMIND_CAP = 50;
    public const REMIND_COOLDOWN_H = 24;


    public function store(Request $r, string $module, string $id)
    {
        [$def, $row] = $this->target($module, $id);

        // لا يُقرّ عن غيره أحد: الإقرار شهادةٌ شخصية، وتوقيعُ الغير تزوير
        abort_unless(in_array((string) auth()->id(), Acks::targets($module, $row), true), 403,
            'الإقرار لمن طُلب منه وحده — ولا يُقرّ أحدٌ نيابةً عن غيره');

        $note = trim(hub_str($r->input('note')));
        abort_if(mb_strlen($note) > 1000, 422, 'التحفّظ أطول من المسموح');

        Acks::record($module, $row, (string) auth()->id(), $note ?: null);

        hub_audit($def['label'], $module, $row->id,
            (string) ($row->{hub_mod($module)['display'] ?? 'title'} ?? ''),
            ['after' => ['نسخة السجل' => Acks::version($row), 'تحفّظ' => $note ?: '—']]);

        // صاحب السجل يعرف أن إقراراً وقع — الاعتماد خبرٌ لا صمت
        foreach (array_filter([$row->created_by ?? null, $row->owner_id ?? null]) as $uid) {
            if ($uid !== auth()->id()) {
                hub_notify($uid, 'ack', '✅ ' . auth()->user()->name . ' — ' . $def['label']
                    . ': ' . \Illuminate\Support\Str::limit((string) ($row->title ?? $row->name ?? ''), 50),
                    $module, $row->id);
            }
        }

        return back()->with('ok', '✅ سُجّل إقرارك بدليله — الوقت والعنوان والجهاز');
    }

    /** تذكير من لم يُقرّ — لمن يملك تعديل الوحدة */
    public function remind(string $module, string $id)
    {
        [$def, $row] = $this->target($module, $id);
        abort_unless(hub_can(auth()->user(), $module, 'e'), 403, 'التذكير لمن يملك تعديل الوحدة');

        $st = Acks::state($module, $row);
        $late = collect($st['people'] ?? [])->where('acked', false);
        abort_if($late->isEmpty(), 422, 'أقرّ الجميع — لا أحد يُذكَّر');

        /*
         * **تبريدٌ وسقف.** كانت كلُّ ضغطةٍ تُطلق إشعاراً لكل من لم يُقرّ، بلا حدٍّ
         * ولا مهلة: سجلٌّ بمئتَي مُخاطَبٍ يُغرق صناديقهم بضغطاتٍ متتالية —
         * **والإشعارُ الذي يتكرّر بلا معنى يُدرّب الناس على تجاهل كل الإشعارات**،
         * فيضيع التذكير الذي يهمّ مع الذي لا يهمّ.
         */
        $key = 'ack:remind:' . $module . ':' . $row->id;
        if (\Illuminate\Support\Facades\Cache::get($key)) {
            return back()->with('err', 'ذُكِّروا قبل قليل — التذكير مرةً كل ' . self::REMIND_COOLDOWN_H
                . ' ساعة حتى لا يصير ضجيجاً يُتجاهَل');
        }
        \Illuminate\Support\Facades\Cache::put($key, 1, now()->addHours(self::REMIND_COOLDOWN_H));

        $sent = $late->take(self::REMIND_CAP);
        foreach ($sent as $p) {
            hub_notify($p['id'], 'ack', '📝 ينتظرك ' . $def['label'] . ': '
                . \Illuminate\Support\Str::limit((string) ($row->title ?? $row->name ?? ''), 50),
                $module, $row->id);
        }
        hub_audit('تذكير بالإقرار', $module, $row->id, $sent->count() . ' شخصاً');

        if ($late->count() > $sent->count()) {
            // لا سقفَ صامت: من لم يصله التذكير يُقال صراحةً لا يُطوى
            return back()->with('ok', '🔔 ذُكِّر ' . $sent->count() . ' من أصل ' . $late->count()
                . ' — السقف ' . self::REMIND_CAP . ' في المرة الواحدة، والبقية في التذكير القادم');
        }

        return back()->with('ok', '🔔 ذُكِّر ' . $late->count() . ' ممّن لم يُقرّ بعد');
    }

    /* ────────── داخلي ────────── */

    protected function target(string $module, string $id): array
    {
        $def = Acks::def($module);
        abort_unless($def && Acks::enabled($module), 404, 'لا إقرار على هذه الوحدة');
        abort_unless(hub_can(auth()->user(), $module, 'v'), 403);

        $md = hub_mod($module);
        $class = '\\App\\Models\\' . $md['model'];
        // النطاق يسري: سجلٌّ خارج نطاقي لا أُقرّ عليه ولا أعرف بوجوده
        $row = hub_scope($class::query(), $module)->findOrFail($id);

        return [$def, $row];
    }
}
