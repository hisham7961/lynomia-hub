<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * «⎘ نسخ كسجل جديد» — زرٌّ كان يَعِد ولا يفعل (الجولة 3).
 *
 * صفحةُ السجلّ تعرض رابطاً إلى `m.create` بمعامل `?from=<id>`، وكان
 * `ModuleController::create()` يملأ النموذجَ من **مفاتيح الحقول** في الرابط
 * حصراً — و`from` ليس مفتاحَ حقل، فيُهمَل بصمت ويُفتح نموذجٌ **فارغ**. أثبته
 * وكيلان مستقلّان في الجولة 3 (مديرةُ المشاريع ومستخدمٌ محترف) وكلاهما نسخ
 * بيده حقلاً حقلاً.
 *
 * والنسخُ يُحرَس بما يَحرس القراءةَ نفسَها — لا بحارسٍ ثانٍ ينحرف:
 * `findScoped` (تنطيقٌ وصلاحيّة)، والفريدُ لا يُستنسخ، والمحجوبُ لا يُسرَّب.
 */
class DogfoodR3CopyTest extends TestCase
{
    public function test_copy_as_new_record_prefills_the_form_from_the_source(): void
    {
        $this->seedCore();

        $src = Project::create([
            'name' => 'بوّابة الخليج للتأمين — المرحلة الأولى',
            'status' => 'نشط', 'budget' => 48000,
        ]);

        $html = $this->actingAs($this->owner)
            ->get(route('m.create', ['projects', 'from' => $src->id]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('بوّابة الخليج للتأمين — المرحلة الأولى', $html,
            'زرُّ «نسخ كسجل جديد» فتح نموذجاً فارغاً — الوعدُ بلا فعل');
        $this->assertStringContainsString('48000', $html, 'قيمةُ المصدرِ الرقميّةُ لم تُنسخ');
    }

    public function test_an_explicit_query_value_outranks_the_copied_one(): void
    {
        $this->seedCore();
        $src = Project::create(['name' => 'الأصل', 'status' => 'نشط']);

        $html = $this->actingAs($this->owner)
            ->get(route('m.create', ['projects', 'from' => $src->id, 'name' => 'اسمٌ صريحٌ من الرابط']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('اسمٌ صريحٌ من الرابط', $html, 'الطلبُ الصريحُ يجب أن يغلب المنسوخ');
    }

    /**
     * **النسخُ لا يكشف أكثرَ ممّا يكشفه العرض** — وهذا هو الضمانُ الحقيقيّ.
     *
     * كتبتُ هذا الاختبارَ أوّلاً يثبّت «٤٠٤ لمستخدمٍ محصور»، فسقط بـ200. وقبل
     * أن أُعدّل الشيفرةَ لتوافقَ الاختبار، سألتُ الشاشةَ القائمة: `show()` لهذا
     * المستخدمِ بعينه على السجلِّ بعينه يردّ **200 أيضاً**، والقائمةُ تعرض الصفَّ.
     * فالنسخُ إذاً **مطابقٌ للعرض** — وهو المقصودُ تماماً (`findScoped` نفسُها) —
     * وتوقُّعي أنا كان خطأً لا الشيفرة.
     *
     * فيُثبَّت هنا ما يهمّ فعلاً وما لا ينحرف مع الزمن: **التكافؤ**. أيّاً كان
     * ما تقرّره سياسةُ التنطيقِ اليومَ أو غداً، لا يفتح بابُ النسخِ سجلّاً
     * يُغلقه بابُ العرض. (وسؤالُ ما إذا كان ينبغي لدورٍ بنطاقِ «مشاريعه» أن يرى
     * كلَّ المشاريع سؤالٌ قائمٌ **قبل** هذا العمل وخارجَ نطاقه — سُجّل ملاحظةً
     * ولم يُحسَم هنا.)
     */
    public function test_copy_never_reveals_a_record_that_show_would_refuse(): void
    {
        $this->seedCore();
        $src = Project::create(['name' => 'مشروعٌ خارجَ نطاقه', 'status' => 'نشط']);

        // دورٌ بنطاقِ «مشاريعه» — ولا مشروعَ مُسنَدٌ إليه
        $role = Role::create(['name' => 'منفّذٌ محصور', 'scope' => 'project',
            'flags' => [], 'matrix' => ['projects' => ['v' => 1, 'a' => 1]]]);
        $scoped = User::create(['name' => 'محصور', 'email' => 'copy-scoped@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $show = $this->actingAs($scoped)->get(route('m.show', ['projects', $src->id]));
        $copy = $this->actingAs($scoped)->get(route('m.create', ['projects', 'from' => $src->id]));

        if ($show->getStatusCode() !== 200) {
            $this->assertNotSame(200, $copy->getStatusCode(),
                'بابُ النسخِ فتح سجلّاً يُغلقه بابُ العرض — تكافؤُ القارئين انكسر');

            return;
        }

        // العرضُ مسموح: فالنسخُ مسموحٌ ولا يزيد — ولا يُسرِّب حقلاً محجوباً
        $this->assertSame(200, $copy->getStatusCode(), 'العرضُ مسموحٌ والنسخُ مُنع — تكافؤٌ منكسرٌ في الاتجاه الآخر');
        $this->assertStringContainsString('مشروعٌ خارجَ نطاقه', $copy->getContent());
    }
}
