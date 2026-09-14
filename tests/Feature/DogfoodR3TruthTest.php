<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Employee;
use App\Models\FinDocument;
use App\Models\Project;
use App\Models\Task;
use App\Support\AttentionQueue;
use App\Support\Audit;
use App\Support\Health;
use App\Support\SecurityPosture;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **المنتجُ لا يقول ما لم يتحقّق منه** (تجريبُ الجولة ٣ · V5 + V7).
 *
 * عيبان من صنفٍ واحد، وكلاهما نقيضُ قاعدةِ المستودع «إثبات لا ادّعاء»:
 *
 *  **V5 — فحصُ سلامة السلسلة يفشل مفتوحاً.** `Audit::verifyTail` كان يُغلق جسمَه
 *  كلَّه بـ`catch` يردّ **`ok => true`** — فإن تعذّر الفحصُ لأيِّ سبب (عمودٌ غائب،
 *  قاعدةٌ لا تُجيب، لهجةُ محرّكٍ) يقول المنتجُ «سلسلة سليمة». والسلسلةُ هي آليّةُ
 *  إثباتِ عدمِ العبث — ما يُتّكأ عليه ساعةَ التحقيق في اختراق. فالوسمُ
 *  `'تعذّر الفحص'` في الصفِّ نفسِه يشهد أنّ الكاتبَ يعلم أنّ الفحصَ لم يجرِ.
 *  المطلوبُ حالةٌ ثالثةٌ صريحة: **غيرُ متحقَّق** (`state = unknown`) تُصبَغ
 *  تحذيراً وتُقال، لا صمتاً يُقرأ سلامة.
 *
 *  **V7 — مبدّلُ الشركة يَعِد بتصفيةٍ لا تقع في اللوحات.** تحت كيانٍ فارغٍ تماماً
 *  كانت `/ceo` تقول ٣١ موظّفاً و٨ مشاريع وصافيَ سنةٍ بستّة أرقام، بينما
 *  `/workforce` يقول «٠ موظّفاً» من الجدول نفسِه في الدقيقة نفسِها. رقمٌ يبدو
 *  مُصفّىً وهو ليس كذلك يُفسد قراراً — فإمّا أن تُصفّيَ اللوحةُ فعلاً، وإمّا أن
 *  **تُصرّح** بأنّ أرقامَها أرقامُ المجموعة بوسمٍ ظاهرٍ قربَ الأرقام.
 */
class DogfoodR3TruthTest extends TestCase
{
    /**
     * حيلةُ الإجبار: مستمعُ استعلامٍ يرمي عند أوّل قراءةٍ من جدول `audits` وحدَه.
     * تُحاكي ما يقع في الإنتاج (عمودٌ لم يُرحَّل بعدُ، أو محرّكٌ يرفض اللهجة) بلا
     * عبثٍ بالمخطّط — والاستثناءُ يقع **داخل** `verifyTail` حيث كان يُبتلع.
     */
    private function breakChainRead(): void
    {
        DB::listen(function ($q) {
            if (str_contains($q->sql, 'audits')) {
                throw new \RuntimeException('تعذّرت قراءةُ جدول التدقيق (اختبار)');
            }
        });
    }

    /** قيدٌ مختومٌ حيٌّ كي لا يكون الجوابُ «لا سلسلة بعد» */
    private function seedSealedEntry(): void
    {
        $this->actingAs($this->owner);
        hub_audit('فعلٌ مختوم', null, null, 'بذرةُ سلسلة');
    }

    /* ══════════ V5 — تعذُّرُ الفحص لا يُنتج «سليمة» ══════════ */

    public function test_a_chain_check_that_could_not_run_is_not_reported_as_sound(): void
    {
        $this->seedCore();
        $this->seedSealedEntry();
        $this->breakChainRead();

        $chain = Audit::verifyTail();

        $this->assertSame('unknown', $chain['state'] ?? null,
            'تعذُّرُ الفحص يجب أن يُعلَن حالةً ثالثةً صريحة (unknown) — لا «سليمة» ولا «عبث»');
        $this->assertFalse((bool) $chain['ok'],
            '`ok => true` عند تعذُّر الفحص هو العيبُ بعينه: كلُّ مستهلكٍ يقرأ '
            . 'المفتاحَ وحدَه سيصبغ صفَّه أخضر على فحصٍ لم يجرِ');
        $this->assertNotSame('', (string) $chain['why'],
            'التعذُّرُ معلومةٌ لا سكوت — يجب أن يقول لماذا تعذّر');
    }

    public function test_the_security_posture_warns_instead_of_greening_an_unverified_chain(): void
    {
        $this->seedCore();
        $this->seedSealedEntry();
        $this->breakChainRead();

        $row = collect(SecurityPosture::checks())->firstWhere('key', 'audit_chain');

        $this->assertNotNull($row, 'صفُّ سلسلة التدقيق غاب عن وضعية الأمان');
        $this->assertSame('wn', $row['tone'],
            'صفُّ «سلسلة بصمات التدقيق» يُصبَغ أخضرَ (ok) أو أحمرَ (bad) على فحصٍ '
            . 'لم يجرِ — والحالةُ الصادقةُ تحذيرٌ: مجهولةٌ لا سليمة');
        $this->assertStringNotContainsString('سليمة', (string) ($row['value'] ?? ''),
            'وسمُ الصفّ يقول «سليمة» والفحصُ لم يجرِ');
    }

    public function test_health_does_not_declare_the_chain_sound_when_it_could_not_check(): void
    {
        $this->seedCore();
        $this->seedSealedEntry();
        $this->breakChainRead();

        $sec = Health::check()['components']['security'];

        $this->assertNotSame(Health::HEALTHY, $sec['status'],
            'قسمُ الأمن في نموذج الصحّة يُعلن HEALTHY وفحصُ السلسلة لم يجرِ');
        $this->assertStringNotContainsString('السلسلة سليمة', (string) $sec['why'],
            'الصحّةُ تُصرّح نصّاً «لا حوادث مفتوحة والسلسلة سليمة» بلا أن تفحص السلسلة');
        $this->assertNotTrue($sec['data']['chain_ok'] ?? null,
            '`chain_ok => true` كان مكتوباً حرفيّاً في الصفّ لا مشتقّاً من الفحص');
    }

    public function test_the_attention_queue_raises_an_unverifiable_chain_instead_of_dropping_it(): void
    {
        $this->seedCore();
        $this->seedSealedEntry();
        $this->breakChainRead();

        $items = collect(AttentionQueue::items($this->owner, true))
            ->filter(fn ($i) => ($i['type'] ?? '') === 'audit')->values();

        $this->assertTrue($items->isNotEmpty(),
            'صفُّ الانتباه يُسقط البندَ حين يتعذّر فحصُ السلسلة — فالمالكُ لا يعرف '
            . 'أنّ ضمانَ عدم العبث بلا فاحصٍ يعمل');
        $this->assertStringContainsString('تعذّر', (string) $items[0]['title'],
            'البندُ يجب أن يقول «تعذّر الفحص» لا «مكسورة» — لا ندّعي عبثاً لم نره');
    }

    /**
     * «سليمةٌ بملاحظات» حكمٌ مشروط — وحكمٌ مشروطٌ بلا تفسيرٍ أسوأُ من لا حكم:
     * يفتح سؤالَ المدقّق («ما هذه الملاحظات؟») ولا يُغلقه. فالنصُّ يُقال حيث
     * تُعرض النتيجة: مخرَجُ الأمر (وهو فلاشُ زرِّ مركز التشغيل حرفياً) والصفُّ
     * المخزَّن الذي تقرؤه شاشةُ التدقيق ومركزُ التشغيل.
     */
    public function test_a_verification_that_passed_with_notes_says_what_the_notes_are(): void
    {
        $this->seedCore();

        $co  = Company::create(['name_ar' => 'لينوميا الكويت']);
        $emp = Employee::create(['name' => 'موظفٌ منقول', 'status' => 'نشط', 'company_id' => $co->id]);

        // قيدٌ مختومٌ بلا شركة وسجلُّه اليوم داخل شركة — إحدى ملاحظاتِ الفاحص
        $this->actingAs($this->owner);
        hub_audit('تعديل', 'hr', $emp->id, 'موظفٌ منقول');
        DB::table('audits')->where('module', 'hr')->update(['company_id' => null]);

        $code = \Illuminate\Support\Facades\Artisan::call('hub:audit-verify');
        $out = \Illuminate\Support\Facades\Artisan::output();
        $row = DB::table('audit_verifications')->orderByDesc('id')->first();

        $this->assertSame(0, $code, 'ملاحظةٌ ليست فشلاً — الأمر يخرج ناجحاً: ' . $out);
        $this->assertSame('warn', (string) $row->result, 'الحكمُ يجب أن يكون «سليمة بملاحظات»');
        $this->assertStringContainsString('ملاحظ', $out,
            'مخرَجُ الفاحص لا يُعلن أنّ مع السلامة ملاحظات');
        $this->assertStringContainsString('بلا شركة', $out,
            'مخرَجُ الفاحص (وهو نصُّ الفلاش بعد ضغط زرِّ الفحص) يقول «سليمة بملاحظات» بلا سردِ الملاحظات');
        $this->assertStringContainsString('بلا شركة', (string) $row->message,
            'الصفُّ المخزَّن — وهو كلُّ ما يبقى بعد انقضاء الفلاش — لا يحمل نصَّ الملاحظة، '
            . 'فالشاشتان تعرضان حكماً مشروطاً بلا تفسير');
    }

    /**
     * وتُقال **حيث تُعرض النتيجة**: شاشةُ التدقيق ومركزُ التشغيل يعرضان الوسمَ
     * «⚠️ سليمة بملاحظات» — وكان التفصيلُ مكتوباً في الصفّ نفسِه ولا يُطبع في
     * أيٍّ منهما (جدولُ التاريخ لا يظهر إلا بفحصين فأكثر، فأوّلُ فحصٍ بلا جواب).
     */
    public function test_both_screens_print_the_notes_next_to_the_conditional_verdict(): void
    {
        $this->seedCore();

        $note = 'السلسلة سليمة: 12 سجل متحقق · ملاحظات (1): 3 قيوداً بلا شركة وسجلُّه داخل شركة';
        DB::table('audit_verifications')->insert([
            'mode' => 'manual', 'started_at' => now(), 'finished_at' => now(), 'duration_ms' => 12,
            'result' => 'warn', 'checked_rows' => 12, 'message' => $note,
        ]);

        $this->actingAs($this->owner);

        $this->get(route('audit.index'))->assertOk()->assertSee($note, false);
        $this->get(route('ops.index'))->assertOk()->assertSee($note, false);
    }

    /* ══════════ V7 — الكيانُ المختار: تصفيةٌ أو تصريح ══════════ */

    /** كيانان: واحدٌ فيه كلُّ شيء، وواحدٌ فارغٌ تماماً — كـ«لينوميا قطر» في التجريب */
    private function seedTwoEntities(): array
    {
        $full  = Company::create(['name_ar' => 'لينوميا الكويت']);
        $empty = Company::create(['name_ar' => 'لينوميا قطر']);

        Employee::create(['name' => 'موظفُ الكويت أ', 'status' => 'نشط', 'company_id' => $full->id]);
        Employee::create(['name' => 'موظفُ الكويت ب', 'status' => 'نشط', 'company_id' => $full->id]);
        Project::create(['name' => 'مشروعُ الكويت', 'status' => 'قيد التنفيذ', 'company_id' => $full->id]);
        Client::create(['name' => 'عميلُ الكويت', 'company_id' => $full->id]);
        Task::create(['title' => 'مهمّةُ الكويت', 'status' => 'جديدة', 'company_id' => $full->id]);
        FinDocument::create(['doc_no' => 'INV-KW', 'kind' => 'فاتورة مبيعات', 'total' => 5000,
            'paid' => 0, 'state' => 'مرسلة', 'date' => now()->toDateString(), 'company_id' => $full->id]);

        return [$full, $empty];
    }

    public function test_the_ceo_board_under_an_empty_entity_does_not_show_the_group_numbers(): void
    {
        $this->seedCore();
        [, $empty] = $this->seedTwoEntities();

        $kpi = $this->actingAs($this->owner)->withSession(['hub.company' => $empty->id])
            ->get('/ceo')->assertOk()->viewData('kpi');

        $said = [];
        foreach (['emps' => 'موظفون', 'projects' => 'مشاريع', 'clients' => 'عملاء',
                  'openTasks' => 'مهام مفتوحة', 'unpaid' => 'مستحقات', 'netY' => 'صافي السنة'] as $k => $label) {
            if ((float) $kpi[$k] != 0.0) $said[] = $label . ' = ' . $kpi[$k];
        }

        $this->assertSame([], $said,
            'تحت كيانٍ لا سجلَّ له تعرض لوحةُ القيادة أرقامَ المجموعة: ' . implode(' · ', $said)
            . ' — والمبدّلُ يعد نصّاً بأن «تُصفّى القوائم على الشركة المختارة»');
    }

    /** وليست التصفيةُ تصفيراً أعمى: الكيانُ العامرُ يُظهر سجلاته هو */
    public function test_the_ceo_board_under_a_populated_entity_shows_that_entitys_rows(): void
    {
        $this->seedCore();
        [$full] = $this->seedTwoEntities();

        $kpi = $this->actingAs($this->owner)->withSession(['hub.company' => $full->id])
            ->get('/ceo')->assertOk()->viewData('kpi');

        $this->assertSame(2, (int) $kpi['emps'], 'موظفو الكيان المختار لم يُعدّوا');
        $this->assertSame(1, (int) $kpi['projects'], 'مشاريعُ الكيان المختار لم تُعدّ');
        $this->assertSame(1, (int) $kpi['clients'], 'عملاءُ الكيان المختار لم يُعدّوا');
        $this->assertSame(5000.0, round((float) $kpi['unpaid'], 2), 'مستحقاتُ الكيان المختار لم تُجمع');
    }

    /** وما لا يُصفّى يُقال بوسمٍ ظاهرٍ قربَ الأرقام لا في حاشيةٍ خفيّة */
    public function test_the_ceo_board_declares_the_cards_that_stay_group_wide(): void
    {
        $this->seedCore();
        [, $empty] = $this->seedTwoEntities();

        $html = $this->actingAs($this->owner)->withSession(['hub.company' => $empty->id])
            ->get('/ceo')->assertOk()->getContent();

        $this->assertStringContainsString('أرقام المجموعة', $html,
            'بطاقاتٌ لا تُصفّى على الكيان (صحّةُ الشركة · الإيرادُ المتكرر · طبقةُ القرار) '
            . 'تُعرض تحت رايةِ كيانٍ واحدٍ بلا تصريح');
    }

    /** ولوحةُ القدرات لا تُصفّى — فتقولها صراحةً بدل «٥٬٢٣١ ساعة» لكيانٍ بلا موظّف */
    public function test_the_capacity_board_declares_that_it_is_not_filtered_by_entity(): void
    {
        $this->seedCore();
        [, $empty] = $this->seedTwoEntities();

        $html = $this->actingAs($this->owner)->withSession(['hub.company' => $empty->id])
            ->get('/capacity')->assertOk()->getContent();

        $this->assertStringContainsString('أرقام المجموعة', $html,
            '`/capacity` يقول «٣١ موظّفاً و٥٬٢٣١ ساعةً متاحة» تحت كيانٍ فارغ '
            . 'بينما «فريقي اليوم» يقول ٠ من الجدول نفسِه — بلا أيِّ تصريح');
    }

    /** ولا وسمَ حيث لا كيان: «كلّ الشركات» أرقامُ مجموعةٍ بداهةً فلا ضجيج */
    public function test_no_group_tag_is_shown_when_no_entity_is_selected(): void
    {
        $this->seedCore();
        $this->seedTwoEntities();

        $html = $this->actingAs($this->owner)->get('/ceo')->assertOk()->getContent();

        $this->assertStringNotContainsString('أرقام المجموعة', $html,
            'الوسمُ يظهر بلا كيانٍ مختار — تحذيرٌ دائمٌ يُقرأ ضجيجاً فيُهمَل');
    }
}
