<?php

namespace App\Support;

use App\Models\ContractEvent;
use App\Models\SignRequest;

/**
 * سلسلة تجزئة الأدلة (CLM م6): تجزئة جارية sha256 على أحداث الطلب المرتبة —
 * كل حلقة تعتمد على سابقتها وعلى بصمة الوثيقة، فأي عبثٍ بحدثٍ ماضٍ يكسر كل
 * ما بعده. رأس السلسلة يُجمَّد في `sign_requests.evidence_hash` لحظة الاكتمال،
 * وإعادة الحساب في أي وقتٍ لاحق تثبت سلامة السجل حتى تلك اللحظة.
 */
class Evidence
{
    /**
     * السلسلة كاملة: [صفوف [event, hash]، الرأس الحالي] — بترتيب الوقوع الثابت.
     *
     * `$legacy` يُعيد الترتيبَ الذي كان معتمداً قبل v2.334 (وقتاً ثم معرّفاً بلا
     * رتبةٍ دلالية) — يلزم `verify()` وحدَها كي لا يُتَّهم رأسٌ جُمّد قبلها.
     */
    public static function chain(SignRequest $req, bool $legacy = false): array
    {
        // بصمات الموقّعين تدخل حلقاتهم: كانت الحلقة تُجزّئ الحدث وحده
        // (id|event|signer|ip|created_at) بينما **أهم ما يُزوَّر خارجها كلياً**:
        // صورة التوقيع نفسها، واسم الموقّع ورقم هويته وسيلفيه وsigned_at —
        // وجدول «الأطراف» في الشهادة يُعرض من هذه الأعمدة غير المُجزّأة حرفياً.
        // **والدور والحالة وIP الموقّع** تُعرض في الشهادة أيضاً خارج أي حلقة: قلبُ
        // حالةٍ من «رُفض» إلى «وُقّع»، أو تغييرُ دورٍ، أو تزويرُ IP توقيعٍ — كلها
        // كانت تُبقي السلسلة «سليمة» رغم الضمان المطبوع. ضُمّت الآن للبصمة.
        $signers = \App\Models\ContractSigner::where('request_id', $req->id)->get()
            ->mapWithKeys(fn ($s) => [$s->id => hash('sha256',
                (string) $s->name . '|' . (string) $s->id_no . '|' . (string) $s->signed_at
                . '|' . (string) $s->role . '|' . (string) $s->status . '|' . (string) $s->ip
                . '|' . hash('sha256', (string) $s->signature) . '|' . hash('sha256', (string) $s->selfie))])
            ->all();

        // مرساة الرأس تحمل signer_name على مستوى الطلب: تعرضه صفحة /verify، وهو
        // عمودٌ مختلف عن meta حدث signed — فبقي خارج التجزئة حتى الآن.
        $prev = hash('sha256', 'lynomia-evidence:' . $req->id . ':' . (string) $req->doc_hash
            . ':' . (string) $req->signer_name);
        $rows = [];
        $q = ContractEvent::where('request_id', $req->id);
        $q = $legacy ? $q->orderBy('created_at')->orderBy('id') : ContractEvent::ordered($q);
        foreach ($q->get() as $e) {
            $prev = hash('sha256', $prev . '|' . $e->id . '|' . $e->event . '|'
                . ($e->signer_id ?: '') . '|' . ($e->ip ?: '') . '|' . (string) $e->created_at
                // meta (سبب الرفض/الإبطال) وactor يدخلان الحلقة، وبصمة الموقّع
                // على حلقتَي توقيعه/رفضه — عبثٌ بأيٍّ منها يكسر السلسلة
                . '|' . hash('sha256', (string) $e->meta) . '|' . ($e->actor_id ?: '')
                . '|' . (in_array($e->event, ['signed', 'declined'], true) && $e->signer_id
                    ? ($signers[$e->signer_id] ?? '') : ''));
            $rows[] = ['e' => $e, 'hash' => $prev];
        }

        return [$rows, $prev];
    }

    /**
     * **الفاحصُ الذي كان ناقصاً**: مقارنةُ الرأس المُجمَّد بإعادة الحساب.
     *
     * `evidence_hash` كان يُجمَّد لحظةَ الاكتمال ولا يُقارَن بشيءٍ بعدها في أيّ
     * موضعٍ من المشروع، والشهادةُ تطبعه (`$req->evidence_hash ?: $head`) فيحجب
     * المُجمَّدُ المحسوبَ: عبثٌ بحدثٍ ماضٍ يغيّر الحساب والشهادةُ تظلّ تطبع
     * القديمَ وتبدو سليمة. فقيمةٌ مخزَّنةٌ تَعِد بضمانٍ ولا تابعَ لها أسوأُ من
     * غياب الضمان: تُسكِت السؤالَ الذي كان سيكشف العبث.
     *
     * والحكمُ يقبل الترتيبَ القديم كذلك: رأسٌ جُمّد قبل v2.334 حُسب بلا الرتبة
     * الدلالية، واتّهامُ عقدٍ سليمٍ بتغيّرِ تقنيةٍ عندنا خطأٌ لا حراسة.
     *
     * @return array{ok: bool, checked: bool, frozen: ?string, head: string, legacy: bool}
     */
    public static function verify(SignRequest $req): array
    {
        [, $head] = self::chain($req);
        $frozen = (string) ($req->evidence_hash ?? '');

        // لم يُجمَّد بعد (مسودةٌ أو طلبٌ سابقٌ للميزة): لا دعوى فلا تكذيب
        if ($frozen === '') {
            return ['ok' => true, 'checked' => false, 'frozen' => null, 'head' => $head, 'legacy' => false];
        }

        if (hash_equals($frozen, $head)) {
            return ['ok' => true, 'checked' => true, 'frozen' => $frozen, 'head' => $head, 'legacy' => false];
        }

        [, $old] = self::chain($req, legacy: true);
        if (hash_equals($frozen, $old)) {
            return ['ok' => true, 'checked' => true, 'frozen' => $frozen, 'head' => $old, 'legacy' => true];
        }

        return ['ok' => false, 'checked' => true, 'frozen' => $frozen, 'head' => $head, 'legacy' => false];
    }

    /** وصف عربي لحدث أدلة — نفس معجم الخط الزمني */
    public static function label(string $event): string
    {
        return [
            'created' => 'أُنشئ طلب التوقيع', 'sent' => 'أُرسل للتوقيع', 'opened' => 'فُتحت الوثيقة',
            'otp_sent' => 'أُرسل رمز تحقق', 'otp_ok' => 'تحقق ناجح', 'signed' => 'وُقّعت الوثيقة',
            'declined' => 'رُفض التوقيع', 'voided' => 'أُبطل الرابط', 'reminded' => 'تذكير بالتوقيع',
            'downloaded' => 'نُزّلت الوثيقة',
        ][$event] ?? $event;
    }
}
