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
        $prev = hash('sha256', 'lynomia-evidence:' . $req->id . ':' . (string) $req->doc_hash);
        $rows = [];
        foreach (ContractEvent::where('request_id', $req->id)
                     ->orderBy('created_at')->orderBy('id')->get() as $e) {
            $prev = hash('sha256', $prev . '|' . $e->id . '|' . $e->event . '|'
                . ($e->signer_id ?: '') . '|' . ($e->ip ?: '') . '|' . (string) $e->created_at);
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
