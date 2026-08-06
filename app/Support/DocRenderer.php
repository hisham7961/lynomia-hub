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
     * صورةُ توقيعٍ آمنة للتضمين — أو `null`.
     *
     * التوقيعُ مدخلٌ خارجيّ يرسله طرفٌ **بلا حساب** عبر رابطه العام. وكان
     * يُلصَق في الـHTML بلا تهريب بينما كل حقلٍ آخر هنا يمرّ بـ`e()`، فحمولةٌ
     * تبدأ بالبادئة المسموحة ثم تكسر السمة (`AAAA" onerror="…"><h1>…`) تزرع
     * وسماً في الوثيقة القانونية المؤرشَفة — تزويرٌ لا تكشفه `doc_hash` لأنها
     * على النصّ لا على المخرَج — وتزرع طلباً صادراً من داخل الشبكة
     * (`<img src="http://169.254.169.254/…">`) يرسمه mPDF على الخادم.
     *
     * الحارس قائمةُ سماحٍ صارمة: `data:image/png;base64,` ثمّ Base64 خالصة.
     */
    protected static function safeSignature($sig): ?string
    {
        $sig = (string) $sig;

        return preg_match('#^data:image/png;base64,[A-Za-z0-9+/]+={0,2}$#', $sig) ? $sig : null;
    }

    /**
     * HTML مكتفٍ بذاته للوثيقة الموقعة — أنماط سطرية بسيطة يفهمها محرك PDF
     * (لا متغيرات CSS ولا ملفات خارجية)، والنص والأسماء كلها مُهرَّبة.
     *
     * `$evidence`: الأثرُ الحسّاس (IP ورقم الهوية) للمالك داخليّاً فقط. نسخةُ
     * العميل تُمرّر `false` — بنفس سياسة `doc.blade.php` التي تحجبهما عن
     * `$client` و`$public` صراحةً: الموقّعُ الآخر ومستلمُ النسخة يريان الوثيقة
     * والتوقيع لا آثارَ غيرهما. وكان الـPDF يخالف هذه السياسة على مسار العميل.
     */
    public static function docHtml(SignRequest $req, bool $evidence = true): string
    {
        $h = fn ($v) => e((string) $v);

        // رمز QR على متن الـPDF كما على الويب: مسحُه يفتح الوثيقة ويتحقّق منها —
        // كان يُذكَر الرمزُ نصّاً فقط فتُطبَع الوثيقةُ الرسمية بلا رمزٍ يُمسَح.
        // يُضمَّن صورةً (data-URI) — أوثقُ صيغةٍ في mPDF — وللموقّعة ذات الرمز فقط.
        $qr = ($req->status === 'وُقّع' && $req->verify_code)
            ? Qr::svg(route('sign.verify.doc', $req->verify_code), 90) : null;
        $qrImg = $qr
            ? '<br><img src="data:image/svg+xml;base64,' . base64_encode($qr) . '" style="width:90px;height:90px">'
                . '<br><span style="font-size:8.5px;color:#777">امسح لفتح الوثيقة والتحقّق</span>'
            : '';

        $out = '<div style="font-size:13px;line-height:1.9">'
            . '<table width="100%"><tr>'
            . '<td style="font-size:19px;font-weight:bold">' . $h($req->title) . '</td>'
            . '<td style="text-align:left;font-size:11px">رمز التحقق<br><b dir="ltr">' . $h($req->verify_code) . '</b>' . $qrImg . '</td>'
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
            // الأثرُ الحسّاس للمالك وحده — نسخةُ العميل تحمل الوقتَ والتوقيع فقط
            $trace = $evidence
                ? ' — IP <span dir="ltr">' . $h($b['ip']) . '</span>'
                    . ($b['id_no'] ? ' — هوية: <span dir="ltr">' . $h($b['id_no']) . '</span>' : '')
                : '';
            $sig = static::safeSignature($b['sig']);

            $out .= '<hr><table width="100%"><tr><td>'
                . '<b>' . $h($b['name']) . '</b> (' . $h($b['role']) . ')<br>'
                . '<span style="font-size:10.5px">وقّع في ' . $h($b['at']?->format('Y-m-d H:i:s'))
                . $trace
                . '</span></td><td style="text-align:left">'
                . ($sig ? '<img src="' . $h($sig) . '" style="width:150px">' : '')
                . '</td></tr></table>';
        }

        $out .= '<hr><div style="font-size:9.5px;color:#555">بصمة الوثيقة SHA-256: <span dir="ltr">'
            . $h($req->doc_hash) . '</span><br>للتحقق: ' . $h(route('sign.verify'))
            . ' — الرمز ' . $h($req->verify_code) . '</div></div>';

        return $out;
    }
}
