<?php

namespace App\Support;

use App\Contracts\AskGenerator;

/**
 * **مولِّدٌ لا يولّد** — الافتراضُ حتى يقرّر المالك. (المرحلة ٣ · P3-W5)
 *
 * **ولماذا مولِّدٌ فارغٌ بدل ربطٍ مباشرٍ بالبوّابة؟** لأنّ التوليدَ الحقيقيَّ
 * **يُنفق مالاً**، وقرارُ الإنفاقِ ليس قرارَ شيفرة. فما دام لم يُدخَل اعتمادُ
 * مزوّدٍ ولم يُقَرّ بالتكلفة، يبقى المنفذُ موصولاً بهذا — **يعود بخطأٍ
 * مصنَّفٍ صريح**، لا بجوابٍ مُختلَقٍ ولا بصمتٍ يُقرأ عطلاً.
 *
 * وهو ما يجعل المنسّقَ كلَّه قابلاً للاختبارِ بلا دينارٍ واحد: كلُّ مسارٍ في
 * `AskPipeline` يُقاس بمولِّدٍ مزروعٍ، والحقيقيُّ يُوصَل لاحقاً بسطرِ ربطٍ واحد.
 */
final class NullAskGenerator implements AskGenerator
{
    public function step(string $envelope, array $tools, array $history): array
    {
        return [
            'kind'  => 'error',
            'code'  => AskFailures::UNAVAILABLE,
            'usage' => [],
        ];
    }

    public function isLive(): bool
    {
        return false;
    }

    public function label(): string
    {
        return 'null';
    }
}
