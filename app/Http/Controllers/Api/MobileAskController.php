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

        return $this->ok($this->payload($result, $thread));
    }

    /**
     * `POST ask/stream` — السؤالُ نفسُه **بالبثّ** (SSE · خطّة التطبيق 4.7): أحداثُ الويب
     * نفسُها — `progress` ثمّ `done` أو `error` — بحمولاتٍ JSON. البابُ والتحقّقُ والخيطُ
     * (غيرُه ٤٠٤) **قبل** فتح البثّ، فلا يُرسَل رأسُ 200 لطلبٍ مرفوض؛ والخنقُ على المسار
     * (`AskPolicy::THROTTLE`). `done` يحمل حمولةَ `POST ask` حرفاً (الجوابُ لا يُبثّ قبل
     * مصادقة مراجعه — الأنبوبُ نفسُه)، و`error` رمزاً آليّاً + نصّاً عربيّاً.
     */
    public function stream(Request $r): Response
    {
        if ($deny = $this->gate($r)) return $deny;
        $data = $r->validate([
            'q' => ['required', 'string', 'max:' . AskPolicy::MAX_QUESTION_CHARS],
            'thread' => ['nullable', 'string', 'max:64'],
        ], [], ['q' => 'السؤال']);

        $thread = $this->thread($r, $data['thread'] ?? null);
        if ($thread instanceof Response) return $thread;
        if ($thread !== null && AskMemory::full($thread)) $thread = null;   // بلغ سقفَه ⇒ خيطٌ جديد
        $user = $r->user();
        $requestId = Api::requestId();

        return response()->stream(function () use ($data, $thread, $user, $requestId) {
            // **يُكمل وإن انقطع الاتصال**: النداءُ دُفع ثمنُه، فالتدقيقُ ودورُ الخيط يُكتبان
            ignore_user_abort(true);
            $send = function (string $event, array $payload): void {
                if (connection_aborted()) return;
                echo 'event: ' . $event . "\n" . 'data: '
                    . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                if (ob_get_level() > 0) @ob_flush();
                flush();
            };
            try {
                $result = AskPipeline::ask((string) $data['q'], $user, null, AskMemory::earlierQuestions($thread),
                    function (array $p) use ($send) {
                        $send('progress', array_filter([
                            'stage' => (string) $p['stage'],
                            'step' => isset($p['step']) ? (int) $p['step'] : null,
                            'label' => $p['label'] ?? null,
                            'rows' => isset($p['rows']) ? (int) $p['rows'] : null,
                            'text' => AskPipeline::progressText($p),
                        ], fn ($v) => $v !== null));
                    });
                $saved = AskMemory::record($user, $thread, (string) $data['q'], $result) ?? $thread;
                $send('done', ['data' => $this->payload($result, $saved), 'request_id' => $requestId]);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
                $send('error', $e->getStatusCode() === 404
                    ? ['code' => Api::RESOURCE_NOT_FOUND, 'text' => 'لم تعُد هذه المحادثةُ موجودة', 'request_id' => $requestId]
                    : ['code' => Api::codeFor($e->getStatusCode()), 'text' => 'تعذّر إكمالُ الطلب', 'request_id' => $requestId]);
            } catch (\Throwable $e) {
                report($e);
                $send('error', ['code' => Api::INTERNAL_ERROR,
                    'text' => 'تعذّر إكمالُ الجواب بسبب خطأٍ في الخادم — حاول مجدّداً', 'request_id' => $requestId]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',   // nginx: لا يُخزَّن البثُّ مؤقّتاً
        ]);
    }

    /** حمولةُ الجواب الواحدة — لـ`POST ask` ولحدث `done` في البثّ (لا شكلان يفترقان) */
    private function payload(array $result, ?AskThread $thread): array
    {
        return [
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
        ];
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
