<?php

namespace Tests\Feature\AskHub;

use App\Contracts\AskGenerator;

/**
 * **مولِّدٌ مزروعٌ** — ينطق بما نُمليه عليه حرفاً. (المرحلة ٣ · P3-W5/W7)
 *
 * **وغرضُه أن يُطيع الحقنَ إطاعةً تامّة.** كلُّ اختبارٍ عدائيٍّ هنا يفترض
 * **أسوأَ نموذجٍ ممكن**: واحدٌ استسلم للحقنِ بالكامل وطلب ما لا يجوز. فإن
 * بقي الوصولُ مستحيلاً مع ذلك، فالضمانُ في الخادمِ لا في طاعةِ النموذج —
 * وهو الضمانُ الوحيدُ الذي يُعتَدُّ به.
 *
 * ويحفظ ما رآه من مظروفاتٍ وكتالوجات، ليُثبَت أنّ **ما لا يملكه صاحبُ
 * الجلسةِ لم يصل النموذجَ أصلاً**.
 */
final class ScriptedGenerator implements AskGenerator
{
    /** @var list<array> */
    public array $seenEnvelopes = [];

    /** @var list<array> */
    public array $seenCatalogs = [];

    private int $at = 0;

    /**
     * @param  list<array>     $script  خطواتٌ تُنطَق بالترتيب
     * @param  callable|null   $onStep  يُنفَّذ قبل كلِّ خطوةٍ بترتيبِها —
     *   **يُحاكي ما يقع أثناءَ الطلب**: سحبُ صلاحيّةٍ، أو تغيُّرُ دورٍ، أو حذفُ
     *   سجلّ. وهو ما يجعل اختبارَ TOCTOU يمرُّ بالمنسّقِ كاملاً لا بدالّةٍ وحدَها.
     */
    public function __construct(
        private array $script,
        private bool $live = true,
        private $onStep = null,
    ) {}

    public function step(string $envelope, array $tools, array $history): array
    {
        if ($this->onStep !== null) ($this->onStep)($this->at + 1);

        $this->seenEnvelopes[] = ['envelope' => $envelope, 'history' => $history];
        $this->seenCatalogs[]  = array_keys($tools);

        $next = $this->script[$this->at] ?? ['kind' => 'answer', 'answer' => 'انتهى النصّ.', 'sources' => []];
        $this->at++;

        return $next;
    }

    public function isLive(): bool
    {
        return $this->live;
    }

    public function label(): string
    {
        return 'scripted';
    }

    /** المزروعُ لا يبلغ مزوّداً فلا يُنفق — **وسياقُ الحوكمةِ يُحفَظ للتأكيدِ عليه** */
    public array $governedWith = [];

    public function govern(array $ctx): void
    {
        $this->governedWith = $ctx;
    }

    /** **والسؤالُ يُحفَظ كما يُحفَظ السياق** — فيُؤكَّد على وصولِه بلا نداءٍ واحد */
    public string $askedWith = '';

    public function asking(string $question): void
    {
        $this->askedWith = $question;
    }

    /** كلُّ ما رآه النموذجُ من نصٍّ — للبحثِ عمّا يجب ألّا يكون فيه */
    public function everythingSeen(): string
    {
        return implode("\n", array_column($this->seenEnvelopes, 'envelope'));
    }
}
