<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **ERR-09 (TECH_DEBT #19): عطلُ مخزنِ الـIdempotency لا يفتح باباً لتنفيذٍ ثانٍ.**
 *
 * كان `idempotentFinish` يلتقط فشلَ تخزينِ الردِّ بـ`report($e)` وحدَه: التنفيذُ تمّ،
 * والحجزُ بقي بلا ردّ، فتعدّه المحاولةُ التالية «يتيماً» بعد دقيقة وتستولي عليه —
 * **فيُنفَّذ الطلبُ نفسُه مرّةً ثانية** (سجلّان وويبهوكان). وكان `idempotentBegin`
 * إذا تعذّر الحجزُ ولم يجد صفّاً يمضي **بلا حجز** («لا نعطّل العميل») — فتحٌ عند العطل.
 *
 * الآن: الردُّ يُحفظ في مخزنٍ احتياطيّ (الكاش) حين يفشل الجدول، فتُعاد المحاولةُ
 * إعادةَ ردٍّ لا تنفيذاً؛ والحجزُ المتعذّرُ يُغلق بـ503 `SERVICE_UNAVAILABLE` قابلٍ
 * لإعادة المحاولة بدل التنفيذ غيرِ المحروس.
 *
 * العطلُ يُحاكى بـ`beforeExecuting` على الاتصال (لا DDL ولا مشغّلاتٍ خاصّةً بمحرّك)
 * فيسري الاختبارُ نفسُه على SQLite وMySQL.
 */
class IdempotencyStoreFailureTest extends TestCase
{
    private ?string $failOn = null;   // 'insert' | 'update' | null

    protected function setUp(): void
    {
        parent::setUp();
        DB::connection()->beforeExecuting(function (string $sql, array $bindings, $conn) {
            if ($this->failOn === null) return;
            $s = strtolower(ltrim($sql));
            if (str_starts_with($s, $this->failOn) && str_contains($s, 'idempotency_keys')) {
                throw new QueryException($conn->getName(), $sql, $bindings,
                    new \RuntimeException('مخزنُ الـIdempotency معطَّلٌ (محاكاة)'));
            }
        });
    }

    /** فشلُ تخزينِ الردّ بعد التنفيذ ⇒ المحاولةُ التاليةُ إعادةُ ردٍّ لا تنفيذٌ ثانٍ */
    public function test_finish_failure_does_not_allow_a_second_execution(): void
    {
        $this->seedCore();
        $h = ['Authorization' => 'Bearer ' . $this->apiToken($this->owner), 'Idempotency-Key' => 'err09-finish'];

        $this->failOn = 'update';
        $this->withHeaders($h)->postJson('/api/v1/clients', ['name' => 'عميل ERR09'])->assertStatus(201);
        $this->failOn = null;

        // الحجزُ بلا ردٍّ بعد دقيقتين يبدو «يتيماً» — وهذا بالضبط ما كان يُستولى عليه
        $this->travel(2)->minutes();

        $this->withHeaders($h)->postJson('/api/v1/clients', ['name' => 'عميل ERR09'])
            ->assertStatus(201)->assertHeader('X-Idempotent-Replay', 'true');

        $this->assertSame(1, Client::where('name', 'عميل ERR09')->count(),
            'عطلُ تخزينِ الردّ سمح بتنفيذٍ ثانٍ للطلب نفسِه');
    }

    /** تعذُّرُ الحجز أصلاً ⇒ رفضٌ صريحٌ قابلٌ للإعادة، لا تنفيذٌ بلا حراسة */
    public function test_reservation_failure_fails_closed(): void
    {
        $this->seedCore();
        $h = ['Authorization' => 'Bearer ' . $this->apiToken($this->owner), 'Idempotency-Key' => 'err09-begin'];

        $this->failOn = 'insert';
        $this->withHeaders($h)->postJson('/api/v1/clients', ['name' => 'عميل بلا حجز'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'SERVICE_UNAVAILABLE')
            ->assertHeader('Retry-After');
        $this->failOn = null;

        $this->assertFalse(Client::where('name', 'عميل بلا حجز')->exists(),
            'نُفّذ الطلبُ بلا حجزِ idempotency — فتحٌ عند العطل');

        // وحين يعود المخزن يمضي الطلبُ نفسُه عادياً مرّةً واحدة
        $this->withHeaders($h)->postJson('/api/v1/clients', ['name' => 'عميل بلا حجز'])->assertStatus(201);
        $this->assertSame(1, Client::where('name', 'عميل بلا حجز')->count());
    }

    /** بلا مفتاح Idempotency لا يمسّ عطلُ المخزن شيئاً (لا حجزَ أصلاً) */
    public function test_requests_without_key_are_unaffected(): void
    {
        $this->seedCore();
        $this->failOn = 'insert';
        $this->withHeader('Authorization', 'Bearer ' . $this->apiToken($this->owner))
            ->postJson('/api/v1/clients', ['name' => 'عميل بلا مفتاح'])->assertStatus(201);
    }
}
