<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * الجولة ٣ — عقدُ خطوةِ المطابقة في الاستيراد (حارسُ الخادم لعطلٍ في المتصفّح).
 *
 * العطلُ المُثبَت كان في `public/js/app.js`: المُستمِعُ العامُّ للرفع كان يفترض
 * أنّ **كلَّ** رفعٍ ناجحٍ ينتهي بتحويلة (POST-Redirect-GET)، فيمضي إلى
 * `xhr.responseURL` بعد كلِّ رد 2xx. لكنّ `ImportController::map()` **لا يُحوِّل**:
 * يردّ مستندَ شاشةِ المطابقة بـ200، فيكون `responseURL` عنوانَ الإرسالِ نفسَه،
 * فيُعاد طلبُه بـGET فتظهر الخطوةُ الأولى ويُرمى المستندُ العائد — بلا رسالةِ خطأ.
 *
 * الإصلاحُ في المتصفّح (يُكتَب المستندُ العائدُ محلَّ الحاليّ حين لا تحويلة)،
 * وهذا الملفُّ يحرس **الطرفَ الخادميَّ من العقد** كي لا ينحرف لاحقاً:
 * خطوةُ المطابقة تردّ 200 بمستندِ HTML كاملٍ فيه عناوينُ الملفّ وعيّنتُه
 * وقوائمُ المطابقة — لا تحويلةً، ولا الخطوةَ الأولى.
 * وهو عقدٌ مشتركٌ بين الوحدات لا شاشةً واحدة، فيُفحص على وحدتين.
 */
class DogfoodR3ImportTest extends TestCase
{
    /** رفعُ ملفٍ صحيحٍ لخطوةِ المطابقة — يُعيد الردَّ كما هو */
    protected function postFile(string $module, string $csv)
    {
        return $this->actingAs($this->owner)->post("/m/{$module}/import", [
            'file' => UploadedFile::fake()->createWithContent('assets-import.csv', $csv),
        ]);
    }

    /** الأصول: الردُّ شاشةُ المطابقة بـ200 بلا تحويلة — لا الخطوةُ الأولى */
    public function test_assets_import_upload_returns_mapping_screen_not_a_redirect(): void
    {
        $this->seedCore();

        $res = $this->postFile('assets', "الأصل,الرقم التسلسلي,الموقع / الراك\n"
            . "لابتوب ديل,SN-1001,مكتب الإدارة\n"
            . "طابعة HP,SN-1002,الطابق الثاني\n");

        // (١) لا تحويلة: هذا بالضبط ما يعتمد عليه الرافعُ في المتصفّح ليقرّر
        //     كتابةَ المستندِ العائد محلَّ الحاليّ بدل إعادةِ طلبِ العنوان
        $res->assertOk();
        $this->assertNull($res->headers->get('Location'),
            'خطوةُ المطابقة حوّلت — الرافعُ في المتصفّح يبني قرارَه على أنّها لا تُحوِّل');

        // (٢) مستندُ HTML كامل: `document.write` يكتب مستنداً لا شذرة
        $this->assertStringContainsString('text/html', (string) $res->headers->get('Content-Type'));
        $html = $res->getContent();
        $this->assertStringContainsString('<!DOCTYPE html', $html);
        $this->assertStringContainsString('</html>', $html);

        // (٣) شاشةُ المطابقة لا الخطوةُ الأولى: نموذجُها يمضي إلى خطوةِ التنفيذ
        $res->assertSee('/m/assets/import/run', false);
        $res->assertSee('مطابقة أعمدة الملف', false);
        $this->assertStringNotContainsString('type="file"', $html,
            'عاد حقلُ رفعِ الملف — أي أنّ المعروضَ هو الخطوةُ الأولى لا المطابقة');

        // (٤) عناوينُ الملفِّ حاضرةٌ كلُّها، ولكلِّ عمودٍ قائمةُ مطابقةٍ باسمِه
        foreach (['الأصل', 'الرقم التسلسلي', 'الموقع / الراك'] as $h) {
            $res->assertSee($h, false);
        }
        foreach ([0, 1, 2] as $i) {
            $res->assertSee('name="map[' . $i . ']"', false);
        }

        // (٥) العيّنةُ حاضرة — بها يتحقّق المستخدم أنّ الملفَّ قُرئ صحيحاً
        $res->assertSee('لابتوب ديل', false);
        $res->assertSee('SN-1001', false);
        $res->assertSee('مكتب الإدارة', false);

        // (٦) عدُّ الصفوفِ تحت سطرِ العناوين
        $res->assertSee('2 صف في الملف', false);
    }

    /** الموظّفون: العقدُ نفسُه — فالعلّةُ كانت مشتركةً بين الوحدات لا محلّيّة */
    public function test_hr_import_upload_returns_mapping_screen_too(): void
    {
        $this->seedCore();

        $res = $this->postFile('hr', "اسم الموظف,المسمى الوظيفي\nسارة الأحمد,محاسبة\n");

        $res->assertOk();
        $this->assertNull($res->headers->get('Location'));
        $res->assertSee('/m/hr/import/run', false);
        $res->assertSee('مطابقة أعمدة الملف', false);
        $res->assertSee('اسم الموظف', false);
        $res->assertSee('سارة الأحمد', false);
        $this->assertStringNotContainsString('type="file"', $res->getContent());
    }

    /** ومسارُ الخطأ يبقى خطأً: ردٌّ غيرُ 2xx كي يُعاد الإرسالُ عادياً فتظهر الرسالة */
    public function test_invalid_upload_still_fails_loudly(): void
    {
        $this->seedCore();

        // امتدادٌ غيرُ مدعوم ⇒ 422، لا شاشةَ مطابقةٍ صامتة
        $this->postFile('assets', 'x')   // ‏.csv باسمه لكنّ محتواه بلا صفوف
            ->assertStatus(422);

        $this->actingAs($this->owner)
            ->post('/m/assets/import', ['file' => UploadedFile::fake()->create('shot.png', 10)])
            ->assertStatus(422);

        // وبلا ملفٍ أصلاً: خطأُ تحقّقٍ (302 مع أخطاء الجلسة) لا 200 صامت
        $this->actingAs($this->owner)->post('/m/assets/import', [])
            ->assertSessionHasErrors('file');
    }
}
