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
    /** السلسلة كاملة: [صفوف [event, hash]، الرأس الحالي] — بترتيب الوقوع الثابت */
    public static function chain(SignRequest $req): array
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
        foreach (ContractEvent::where('request_id', $req->id)
                     ->orderBy('created_at')->orderBy('id')->get() as $e) {
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
