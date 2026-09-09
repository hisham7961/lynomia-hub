<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\DmMessage;
use App\Models\SavedMessage;
use App\Models\User;
use App\Support\Collaboration;
use App\Support\CommentService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * **المحفوظاتُ الشخصيّة** (§27) — «احفظ لاحقاً» لأيّ رسالةٍ في المحرّك الواحد
 * (تعليق/قناة أو رسالةٌ مباشرة). **مرجعٌ لا نسخُ محتوى**: يُخزَّن `(النوع، المعرّف)`
 * فقط، ويُحلُّ المحتوى **عند الفتح** بتخويلِ اللحظة — فمحفوظةٌ لقناةٍ أُخرجتَ منها،
 * أو سجلٍّ زالت صلاحيتُك عليه، لا تكشف نصَّها بعدُ. شخصيّةٌ بحتة: لا يرى أحدٌ محفوظاتِ
 * غيره، والحفظُ لا يمسّ الرسالةَ ولا أصحابَها.
 */
class SavedController extends Controller
{
    /** حفظٌ/إلغاءُ حفظٍ لهدفٍ (تبديل) — يُخوَّل الهدفُ الآن كي لا تُحفظ إشارةٌ لا تُرى */
    public function toggle(Request $r)
    {
        $data = $r->validate([
            'target_type' => ['required', 'string', \Illuminate\Validation\Rule::in(Collaboration::SAVED_TYPES)],
            'target_id'   => ['required', 'string'],
            'note'        => ['nullable', 'string', 'max:500'],
        ], [], ['target_type' => 'نوع الهدف', 'target_id' => 'الهدف']);

        $me = auth()->user();
        $this->guardTargetVisible($me, $data['target_type'], $data['target_id']);   // يرى = يحفظ

        $existing = SavedMessage::where('user_id', $me->id)
            ->where('target_type', $data['target_type'])
            ->where('target_id', $data['target_id'])->first();

        if ($existing) {
            $existing->delete();

            return back()->with('ok', 'أُزيل من المحفوظات');
        }

        SavedMessage::create([
            'user_id'     => $me->id,
            'target_type' => $data['target_type'],
            'target_id'   => $data['target_id'],
            'note'        => ($n = trim(hub_str($r->input('note')))) !== '' ? $n : null,
        ]);

        return back()->with('ok', 'حُفظت الرسالة');
    }

    /** إزالةُ محفوظةٍ بمعرّفها — لصاحبها وحده (لا يمسّ الرسالةَ الأصلية) */
    public function destroy(string $id)
    {
        $s = SavedMessage::where('user_id', auth()->id())->findOrFail($id);
        $s->delete();

        return back()->with('ok', 'أُزيل من المحفوظات');
    }

    /** قائمةُ محفوظاتي — يُحلُّ كلُّ هدفٍ بتخويلِ اللحظة؛ ما لم يعد يُرى يُعرَض «غيرَ متاح» */
    public function index()
    {
        $me = auth()->user();
        $saved = SavedMessage::where('user_id', $me->id)->orderByDesc('created_at')->get();

        $rows = $saved->map(fn (SavedMessage $s) => $this->resolveRow($me, $s))->all();

        return view('saved.index', ['rows' => $rows]);
    }

    /* ────────── داخلي ────────── */

    /** يُجهض إن كان الهدفُ غيرَ مرئيٍّ للمستخدم الآن (تعليق: guardTarget · DM: طرفٌ فيه) */
    private function guardTargetVisible(User $me, string $type, string $id): void
    {
        if ($type === 'comment') {
            $c = Comment::find($id);
            abort_unless($c, 422, 'لا رسالةَ بهذا المعرّف');
            CommentService::guardTarget($me, (string) $c->module, $c->record_id);   // يُجهض إن خفي

            return;
        }

        // dm
        $m = DmMessage::find($id);
        abort_unless($m, 422, 'لا رسالةَ بهذا المعرّف');
        abort_unless(in_array($me->id, [$m->from_id, $m->to_id], true), 403, 'لا شأن لك بهذه المحادثة');
    }

    /** صفُّ عرضٍ محلولٌ لمحفوظةٍ — [saved, type, when, note, available, title, author, link] */
    private function resolveRow(User $me, SavedMessage $s): array
    {
        $base = ['saved' => $s, 'type' => $s->target_type, 'when' => $s->created_at,
            'note' => $s->note, 'available' => false, 'title' => null, 'author' => null, 'link' => null];

        try {
            if ($s->target_type === 'comment') {
                $c = Comment::find($s->target_id);
                if (! $c) return $base;
                CommentService::guardTarget($me, (string) $c->module, $c->record_id);   // يُجهض إن خفي

                return array_merge($base, [
                    'available' => true,
                    'title'     => Str::limit(trim((string) $c->body), 90),
                    'author'    => optional($c->user)->name,
                    'link'      => $this->commentLink($c),
                ]);
            }

            $m = DmMessage::find($s->target_id);
            if (! $m || ! in_array($me->id, [$m->from_id, $m->to_id], true)) return $base;

            return array_merge($base, [
                'available' => $m->deleted_at === null,
                'title'     => $m->deleted_at === null ? Str::limit(trim((string) $m->body), 90) : 'حُذفت رسالة',
                'author'    => optional(User::find($m->from_id))->name,
                'link'      => \App\Support\MessageLink::dm($m, (string) $me->id),
            ]);
        } catch (\Throwable $e) {
            return $base;   // لم يعد يُرى — يبقى صفُّ المحفوظةِ كي يُزيلها صاحبُها
        }
    }

    /** رابطُ فتحِ تعليقٍ في مضيفه مع مرساةِ الرسالة (permalink مصغّر) */
    private function commentLink(Comment $c): string
    {
        $anchor = '#c-' . $c->id;
        if ($c->module === 'feed') return route('feed') . $anchor;
        if ($c->module === 'channel' && $c->record_id) return route('conversations.show', $c->record_id) . $anchor;
        if ($c->module && $c->record_id && hub_mod((string) $c->module)) {
            return route('m.show', [$c->module, $c->record_id]) . $anchor;
        }

        return route('feed');
    }
}
