<?php

namespace App\Support;

use App\Models\ContractSigner;
use App\Models\SignRequest;

/**
 * مولّد الوثائق (CLM م6): HTML مبسط للوثيقة والشهادة + PDF عبر mPDF المضمومة.
 * العزل مبدئي: `pdf()` تعيد null عند غياب المكتبة أو أي فشل، وكل المستدعين
 * يتساقطون لمسار HTML القائم تلقائياً — فشل PDF لا يمس التوقيع نفسه أبداً.
 */
class DocRenderer
{
    public static function available(): bool
    {
        return class_exists(\Mpdf\Mpdf::class);
    }

    /** PDF ثنائي من HTML — null بلا المكتبة أو عند أي عطل */
    public static function pdf(string $html, string $title = ''): ?string
    {
        if (! static::available()) return null;

        try {
            $tmp = storage_path('app/mpdf');
            if (! is_dir($tmp)) @mkdir($tmp, 0775, true);
            $m = new \Mpdf\Mpdf([
                'mode' => 'utf-8', 'format' => 'A4', 'directionality' => 'rtl',
                'autoScriptToLang' => true, 'autoLangToFont' => true, 'tempDir' => $tmp,
            ]);
            if ($title !== '') $m->SetTitle($title);
            $m->WriteHTML($html);

            return $m->Output('', 'S');
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * HTML مكتفٍ بذاته للوثيقة الموقعة — أنماط سطرية بسيطة يفهمها محرك PDF
     * (لا متغيرات CSS ولا ملفات خارجية)، والنص والأسماء كلها مُهرَّبة.
     */
    public static function docHtml(SignRequest $req): string
    {
        $h = fn ($v) => e((string) $v);
        $out = '<div style="font-size:13px;line-height:1.9">'
            . '<table width="100%"><tr>'
            . '<td style="font-size:19px;font-weight:bold">' . $h($req->title) . '</td>'
            . '<td style="text-align:left;font-size:11px">رمز التحقق<br><b dir="ltr">' . $h($req->verify_code) . '</b></td>'
            . '</tr></table><hr>'
            . '<div style="white-space:pre-wrap">' . nl2br($h($req->body)) . '</div>';

        // الموقّعون المستقلون (م4) — ولمسار الموقّع الواحد القديم كتلة الطلب نفسها
        $signers = ContractSigner::where('request_id', $req->id)
            ->where('status', 'وُقّع')->where('token', '!=', (string) $req->token)
            ->orderBy('order')->get();
        $blocks = $signers->isNotEmpty()
            ? $signers->map(fn ($s) => [
                'name' => $s->name, 'sig' => $s->signature, 'at' => $s->signed_at,
                'ip' => $s->ip, 'id_no' => $s->id_no, 'role' => $s->role,
            ])->all()
            : ($req->status === 'وُقّع' ? [[
                'name' => $req->signer_name, 'sig' => $req->signature, 'at' => $req->signed_at,
                'ip' => $req->signed_ip, 'id_no' => $req->signer_id_no, 'role' => 'موقّع',
            ]] : []);

        foreach ($blocks as $b) {
            $out .= '<hr><table width="100%"><tr><td>'
                . '<b>' . $h($b['name']) . '</b> (' . $h($b['role']) . ')<br>'
                . '<span style="font-size:10.5px">وقّع في ' . $h($b['at']?->format('Y-m-d H:i:s'))
                . ' — IP <span dir="ltr">' . $h($b['ip']) . '</span>'
                . ($b['id_no'] ? ' — هوية: <span dir="ltr">' . $h($b['id_no']) . '</span>' : '')
                . '</span></td><td style="text-align:left">'
                . (str_starts_with((string) $b['sig'], 'data:image/png')
                    ? '<img src="' . $b['sig'] . '" style="width:150px">' : '')
                . '</td></tr></table>';
        }

        $out .= '<hr><div style="font-size:9.5px;color:#555">بصمة الوثيقة SHA-256: <span dir="ltr">'
            . $h($req->doc_hash) . '</span><br>للتحقق: ' . $h(route('sign.verify'))
            . ' — الرمز ' . $h($req->verify_code) . '</div></div>';

        return $out;
    }
}
