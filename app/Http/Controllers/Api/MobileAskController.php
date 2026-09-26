<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AskThread;
use App\Support\Ai\Ask\AskMemory;
use App\Support\Ai\Ask\AskPipeline;
use App\Support\Ai\Ask\AskPolicy;
use App\Support\Platform\Api;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * **«اسأل Hub» على الجوال** — المرحلة ٢ (`docs/ai-hub/46-ai-roadmap.md` §٤).
 *
 * **لا قاعدةَ ثانية:** السؤالُ يمرّ بـ`AskPipeline::ask` نفسِه (الحوكمة · الأدوات المُنطَّقة · مصادقةُ
 * المراجع)، والخيوطُ بـ`AskMemory` نفسِها (لصاحبها وحدَه · الجوابُ يُعاد تحقّقُه عند كلِّ قراءة ·
 * المتابعةُ بالأسئلة لا بالأجوبة). والبابُ `AskPolicy::canAsk` كالويب — وحسابُ العميل محجوبٌ قبله
 * بسياج `mobile.portal` (قائمةٌ بيضاء لا تشمل هذه المسارات).
 *
 * **وما يصل التطبيق:** الجوابُ المُصادَق ومصادرُه (وسمُ الوحدة وعددُ صفوفها ومعرّفاتُها للروابط العميقة)
 * — لا مظروفَ ولا حمولةَ أداةٍ ولا مفتاحَ ملاحظة. والإخفاقُ رمزٌ آليٌّ (`failure`) يُفرَّع عليه، ورسالةٌ عربيّة.
 */
class MobileAskController extends Controller
{
    private function gate(Request $r): ?Response
    {
        $r->attributes->set('request_source', 'mobile');
        if (! AskPolicy::canAsk($r->user())) {
            return Api::error(Api::FORBIDDEN, 403, '«اسأل Hub» يحتاج صلاحيّةَ استعمالِ المساعد');
        }

        return null;
    }

    private function ok(array $data, int $status = 200): Response
    {
        return response()->json(['data' => $data, 'request_id' => Api::requestId()], $status,
            [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** خيطُ صاحب الجلسة أو ٤٠٤ — غيرُ الموجود وخيطُ غيرِه سواء */
    private function thread(Request $r, ?string $id): AskThread|Response|null
    {
        if ($id === null || $id === '') return null;
        $t = AskMemory::open($r->user(), $id);

        return $t ?? Api::error(Api::RESOURCE_NOT_FOUND, 404, 'غير موجود');
    }

    /** `POST ask` — سؤالٌ (ومتابعةٌ في خيطٍ بـ`thread`) */
    public function ask(Request $r): Response
    {
        if ($deny = $this->gate($r)) return $deny;
        $data = $r->validate([
            'q' => ['required', 'string', 'max:' . AskPolicy::MAX_QUESTION_CHARS],
            'thread' => ['nullable', 'string', 'max:64'],
        ], [], ['q' => 'السؤال']);

        $thread = $this->thread($r, $data['thread'] ?? null);
        if ($thread instanceof Response) return $thread;
        if ($thread !== null && AskMemory::full($thread)) $thread = null;   // بلغ سقفَه ⇒ خيطٌ جديد

        $result = AskPipeline::ask((string) $data['q'], $r->user(), null, AskMemory::earlierQuestions($thread));
        $thread = AskMemory::record($r->user(), $thread, (string) $data['q'], $result) ?? $thread;

        return $this->ok([
            'ok' => (bool) $result['ok'],
            'answer' => $result['ok'] ? (string) $result['answer'] : null,
            'partial' => (bool) ($result['partial'] ?? false),
            'failure' => $result['ok'] ? null : (string) $result['failure'],
            'message' => (string) ($result['message'] ?? ''),
            'sources' => array_map(fn ($s) => [
                'n' => (int) ($s['n'] ?? 0),
                'module' => $s['module'] ?? null,
                'label' => $s['label'] ?? null,
                'rows' => (int) ($s['rows'] ?? 0),
                'ids' => array_values((array) ($s['ids'] ?? [])),
                'complete' => (bool) ($s['complete'] ?? false),
            ], (array) ($result['sources'] ?? [])),
            'thread' => $thread?->id,
            'request' => (string) ($result['meta']['correlation'] ?? ''),
        ]);
    }

    /** `GET ask/threads` — خيوطي (الأحدثُ أوّلاً) */
    public function threads(Request $r): Response
    {
        if ($deny = $this->gate($r)) return $deny;

        return $this->ok([
            'memory' => AskMemory::enabled(),
            'retention_days' => AskMemory::days(),
            'threads' => AskMemory::threads($r->user())->map(fn (AskThread $t) => [
                'id' => $t->id, 'title' => (string) $t->title, 'last_at' => $t->last_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    /** `GET ask/threads/{id}` — أدوارُ خيطي، والجوابُ المحفوظُ **مُعادُ التحقّق** (`hidden` حين سقطت مصادرُه) */
    public function show(Request $r, string $id): Response
    {
        if ($deny = $this->gate($r)) return $deny;
        $t = $this->thread($r, $id);
        if ($t instanceof Response) return $t;
        if ($t === null) return Api::error(Api::RESOURCE_NOT_FOUND, 404, 'غير موجود');

        return $this->ok([
            'id' => $t->id,
            'title' => (string) $t->title,
            'turns' => array_map(fn ($x) => [
                'question' => $x['question'], 'answer' => $x['answer'], 'ok' => $x['ok'],
                'hidden' => $x['hidden'], 'failure' => $x['failure'],
                'at' => $x['at']?->toIso8601String(),
            ], AskMemory::turns($r->user(), $t)),
        ]);
    }

    /** `DELETE ask/threads/{id}` — محوُ خيطي */
    public function destroy(Request $r, string $id): Response
    {
        if ($deny = $this->gate($r)) return $deny;
        $t = $this->thread($r, $id);
        if ($t instanceof Response) return $t;
        if ($t === null) return Api::error(Api::RESOURCE_NOT_FOUND, 404, 'غير موجود');

        return $this->ok(['deleted' => AskMemory::forget($r->user(), $t->id)]);
    }

    /** `DELETE ask/threads` — محوُ كلِّ خيوطي */
    public function destroyAll(Request $r): Response
    {
        if ($deny = $this->gate($r)) return $deny;

        return $this->ok(['deleted' => AskMemory::forget($r->user())]);
    }
}
