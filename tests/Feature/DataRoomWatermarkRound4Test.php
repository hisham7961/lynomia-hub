<?php

namespace Tests\Feature;

use App\Models\ShareLink;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الموجة الرابعة (مؤجّل) — غرفة البيانات «عرض فقط + علامة مائية»:
 * (٥٩) رابطُ «عرض فقط» (no_download) كان يَبثّ **بايتات الملف الخام** عبر
 *      `share.file` — والعلامةُ المائيةُ الموعودة (docstring: «منع تنزيل بعلامة
 *      مائية») مجرّدُ طبقةٍ في صفحة الغلاف (CSS overlay) تُتجاوَز بجلب رابط الملف
 *      مباشرةً (وهو نفسه مصدرُ الإطار/الصورة). فالمُشاهِدُ يحصل على نسخةٍ نظيفةٍ
 *      بلا علامة من مستندٍ وُصف حسّاساً — والوعدُ كذبةٌ على البايتات المخدومة.
 *
 * الإصلاح: حرقُ العلامة (اسم النظام · IP المُشاهِد · الوقت) في البايتات المخدومة
 * للـ no_download — PDF عبر mPDF+FPDI، والصور النقطية عبر GD. المسارُ القابل
 * للتنزيل يبقى خاماً (المستخدم مسموحٌ له بالأصل).
 */
class DataRoomWatermarkRound4Test extends TestCase
{
    /** @var string[] مساراتٌ مطلقةٌ تُنظَّف بعد كل اختبار */
    protected array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @is_file($f) && @unlink($f);
        }
        parent::tearDown();
    }

    /** البايتات المخدومة فعلاً — يعمل على BinaryFileResponse والاستجابة العادية */
    protected function bodyOf(\Illuminate\Testing\TestResponse $res): string
    {
        ob_start();
        $res->baseResponse->sendContent();

        return (string) ob_get_clean();
    }

    /** يكتب ملفاً حقيقيّاً في قرص غرفة البيانات ويعيد مساره النسبي */
    protected function putFile(string $ext, string $bytes): string
    {
        $dir = storage_path('app/dataroom');
        is_dir($dir) || @mkdir($dir, 0755, true);
        $rel = 'dataroom/wmtest_' . Str::random(16) . '.' . $ext;
        file_put_contents(storage_path('app/' . $rel), $bytes);
        $this->tmpFiles[] = storage_path('app/' . $rel);

        return $rel;
    }

    /** PDF حقيقيٌّ قابلٌ للاستيراد (مولّدٌ بـ mPDF نفسها) */
    protected function samplePdf(): string
    {
        $tmp = sys_get_temp_dir() . '/wmt_' . Str::random(6);
        @mkdir($tmp);
        $m = new \Mpdf\Mpdf(['tempDir' => $tmp]);
        $m->WriteHTML('<h1>مستند سرّي</h1><p>Confidential deal memo — line one.</p>');
        $bytes = $m->Output('', \Mpdf\Output\Destination::STRING_RETURN);
        @array_map('unlink', (array) glob($tmp . '/*'));
        @rmdir($tmp);

        return $bytes;
    }

    protected function samplePng(): string
    {
        $im = imagecreatetruecolor(640, 400);
        imagefill($im, 0, 0, imagecolorallocate($im, 235, 235, 235));
        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    protected function makeLink(string $ext, string $mime, string $bytes, bool $noDownload): ShareLink
    {
        $this->seedCore();

        return ShareLink::create([
            'token'       => Str::random(48),
            'title'       => 'مستند حسّاس',
            'path'        => $this->putFile($ext, $bytes),
            'mime'        => $mime,
            'no_download' => $noDownload,
            'created_by'  => $this->owner->id,
            'created_at'  => now(),
        ]);
    }

    /** (٥٩) PDF «عرض فقط» يُبَثّ موسوماً لا خاماً */
    public function test_view_only_pdf_is_watermarked_not_raw(): void
    {
        $raw  = $this->samplePdf();
        $link = $this->makeLink('pdf', 'application/pdf', $raw, true);

        $res = $this->get(route('share.file', $link->token));
        $res->assertOk();
        $this->assertSame('application/pdf', strtok((string) $res->headers->get('content-type'), ';'));

        $body = $this->bodyOf($res);
        $this->assertStringStartsWith('%PDF', $body, 'الناتج ليس PDF صالحاً');
        $this->assertNotSame($raw, $body,
            'رابطُ «عرض فقط» بَثّ بايتات الملف الخام — لا علامة مائية محروقة');
    }

    /** (٥٩) صورة «عرض فقط» تُبَثّ موسومةً لا خامة */
    public function test_view_only_image_is_watermarked_not_raw(): void
    {
        $raw  = $this->samplePng();
        $link = $this->makeLink('png', 'image/png', $raw, true);

        $res = $this->get(route('share.file', $link->token));
        $res->assertOk();

        $body = $this->bodyOf($res);
        $this->assertStringStartsWith("\x89PNG", $body, 'الناتج ليس PNG صالحاً');
        $this->assertNotSame($raw, $body,
            'صورةُ «عرض فقط» بُثّت خامةً — لا علامة مائية محروقة');
    }

    /** المسارُ القابل للتنزيل يبقى خاماً — لا نَسِمُ ما يحقّ للمستخدم أخذُه نظيفاً */
    public function test_downloadable_pdf_is_served_raw(): void
    {
        $raw  = $this->samplePdf();
        $link = $this->makeLink('pdf', 'application/pdf', $raw, false);

        $res = $this->get(route('share.file', [$link->token, 'dl' => 1]));
        $res->assertOk();
        $this->assertSame($raw, $this->bodyOf($res),
            'المسارُ القابل للتنزيل غُيِّرت بايتاته — يجب أن يُخدَم الأصل خاماً');
    }
}
