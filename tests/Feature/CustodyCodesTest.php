<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetCustody;
use App\Models\Role;
use App\Models\User;
use App\Support\Custody;
use Tests\TestCase;

/**
 * **قسمُ العهد: كودٌ يولّده النظام، وملصقٌ يُطبَع، وتصريحٌ يُوقَّع.**
 *
 * ما يحرسه هذا الملف:
 *  1) كودُ العهدة يُولَّد لكل أصل بتسلسل **صنفه** وسنته، ولا يُكتب من CRUD.
 *  2) الكتالوج يعرض الأصنافَ بكودها الأساسي وأعدادها، والصنفُ يعرض ما فيه وحده.
 *  3) الملصق ٤٠×٣٠ مم يحمل الكود ورمزَ QR، وورقةُ A5 تحمل المواصفات الداخلية.
 *  4) الحيازة تُربط بمستخدم بقيدٍ في سجلٍّ لا يُمحى، والاستردادُ يُعيدها متاحة.
 *  5) التصاريح: نقلٌ ينقل الحائز، وخروجٌ مؤقتٌ بلا موعدِ عودةٍ يُرفض، ونهائيٌّ
 *     يُستبعد الأصل — ويُربط التصريحُ بطلب التوقيع الإلكتروني.
 *  6) البوّابات: من يملك العرضَ وحده لا يكتب، ومن لا يملك الوحدةَ لا يقرأ،
 *     وتصريحُ أصلٍ لا يُفتح من رابط أصلٍ آخر.
 */
class CustodyCodesTest extends TestCase
{
    /* ────────── ١) الكود ────────── */

    public function test_custody_code_is_generated_per_category_sequence(): void
    {
        $this->seedCore();
        $y = now()->format('Y');

        $sv1 = Asset::create(['name' => 'خادم الملفات', 'type' => 'سيرفر']);
        $sv2 = Asset::create(['name' => 'خادم النسخ', 'type' => 'سيرفر']);
        $lt1 = Asset::create(['name' => 'لابتوب المحاسبة', 'type' => 'لابتوب']);

        $this->assertSame("LYN-SV-{$y}-0001", $sv1->code);
        $this->assertSame("LYN-SV-{$y}-0002", $sv2->code, 'التسلسل داخل الصنف الواحد يتقدّم');
        $this->assertSame("LYN-LT-{$y}-0001", $lt1->code,
            'تسلسلُ صنفٍ لا يستهلك تسلسلَ صنفٍ آخر — وإلا صارت الأكواد بلا معنى');
    }

    public function test_asset_without_a_category_still_gets_a_code(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'شيءٌ بلا صنف']);

        $this->assertSame('LYN-GN-' . now()->format('Y') . '-0001', $a->code,
            'أصلٌ بلا صنف كان يبقى بلا هويّة — يأخذ كودَ الاحتياط');
    }

    public function test_code_is_locked_against_crud_and_api_writes(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/m/assets', [
            'name' => 'جهازٌ بكودٍ مُلفَّق', 'type' => 'لابتوب', 'code' => 'LYN-ZZ-1999-9999',
        ]);

        $a = Asset::where('name', 'جهازٌ بكودٍ مُلفَّق')->firstOrFail();
        $this->assertSame('LYN-LT-' . now()->format('Y') . '-0001', $a->code,
            'الكودُ المطبوعُ على الملصق لا يُكتب بيدٍ من نموذجٍ ولا API');
    }

    public function test_emptied_code_is_regenerated_on_save(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'جهاز', 'type' => 'هاتف']);
        $a->code = null;
        $a->save();

        $this->assertNotNull($a->fresh()->code, 'كودٌ فُرِّغ لاحقاً يُملأ من جديد — لا أصلَ بلا هويّة');
    }

    /* ────────── ٢) الكتالوج ────────── */

    public function test_catalog_lists_categories_with_base_code_and_counts(): void
    {
        $this->seedCore();
        Asset::create(['name' => 'خادم أ', 'type' => 'سيرفر', 'holder_id' => $this->employee->id]);
        Asset::create(['name' => 'خادم ب', 'type' => 'سيرفر']);
        Asset::create(['name' => 'هاتف أ', 'type' => 'هاتف']);

        $this->actingAs($this->owner)->get('/custody')
            ->assertOk()
            ->assertSee('كتالوج العهد')
            ->assertSee('سيرفر')
            ->assertSee('SV')
            ->assertSee('هاتف');
    }

    public function test_category_screen_shows_only_its_own_items(): void
    {
        $this->seedCore();
        Asset::create(['name' => 'خادم الويب', 'type' => 'سيرفر']);
        Asset::create(['name' => 'جوال المدير', 'type' => 'هاتف']);

        $this->actingAs($this->owner)->get('/custody/cat/SV')
            ->assertOk()
            ->assertSee('خادم الويب')
            ->assertDontSee('جوال المدير');
    }

    public function test_fallback_category_gathers_unregistered_and_empty_types(): void
    {
        $this->seedCore();
        Asset::create(['name' => 'صنفٌ خارج السجل', 'type' => 'دراجة هوائية']);
        Asset::create(['name' => 'بلا صنف أصلاً']);

        $this->actingAs($this->owner)->get('/custody/cat/GN')
            ->assertOk()
            ->assertSee('صنفٌ خارج السجل')
            ->assertSee('بلا صنف أصلاً');
    }

    public function test_unknown_category_code_is_not_found(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/custody/cat/ZZZ')->assertNotFound();
    }

    /* ────────── ٣) الطباعة ────────── */

    public function test_label_page_is_40x30_millimetres_with_code_and_qr(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'خادم قواعد البيانات', 'type' => 'سيرفر', 'serial' => 'SN-77X']);

        $res = $this->actingAs($this->owner)->get('/custody/' . $a->id . '/label');
        $res->assertOk()
            ->assertSee('size: 40mm 30mm', false)
            ->assertSee($a->code)
            ->assertSee('SN-77X')
            ->assertSee('<svg', false);
    }

    public function test_label_copies_are_capped_and_repeated(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'شاشة', 'type' => 'شاشة']);

        $html = $this->actingAs($this->owner)->get('/custody/' . $a->id . '/label?copies=3')
            ->assertOk()->getContent();
        $this->assertSame(3, substr_count($html, 'class="lbl"'), 'عددُ النسخ المطلوب يُطبع كما هو');

        $huge = $this->actingAs($this->owner)->get('/custody/' . $a->id . '/label?copies=9999')
            ->assertOk()->getContent();
        $this->assertSame(\App\Http\Controllers\Web\CustodyController::LABEL_MAX,
            substr_count($huge, 'class="lbl"'),
            'طلبُ آلافِ الملصقات يُحدّ — لا يُفتح بابُ إغراقٍ بالذاكرة من معامل رابط');
    }

    public function test_scanning_the_label_code_opens_its_asset(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'جهازٌ يُمسح ملصقه', 'type' => 'لابتوب']);

        // ورمزُ الملصق يبقى **منخفضَ الكثافة**: المسارُ القصير يُبقي المصفوفة
        // صغيرةً فتُمسح على ٤٠×٣٠ مم؛ ورابطٌ فيه معرّفٌ بستٍّ وثلاثين خانة
        // يضاعف الوحدات فيصير الرمزُ حبراً لا يقرؤه ماسحٌ ولا هاتف.
        $html = $this->actingAs($this->owner)->get('/custody/' . $a->id . '/label')
            ->assertOk()->getContent();
        $this->assertSame(1, preg_match('/viewBox="0 0 (\d+) /', $html, $m));
        $this->assertLessThanOrEqual(45, (int) $m[1],
            'كثافةُ رمز الملصق ارتفعت — راجع طول الرابط المُرمَّز فيه');

        $this->actingAs($this->owner)->get('/c/' . $a->code)
            ->assertRedirect('/m/assets/' . $a->id);

        $this->actingAs($this->owner)->get('/c/LYN-XX-1999-0001')->assertNotFound();
    }

    public function test_spec_sheet_prints_internal_specs_on_a5(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'خادم التطبيقات', 'type' => 'سيرفر']);

        $this->actingAs($this->owner)->post('/custody/' . $a->id . '/specs', [
            'specs' => ['cpu' => 'Xeon Gold 6338', 'ram' => '128GB', 'rack' => 'R3-U12'],
        ])->assertRedirect();

        $this->actingAs($this->owner)->get('/custody/' . $a->id . '/spec')
            ->assertOk()
            ->assertSee('size: A5', false)
            ->assertSee('المعالج')
            ->assertSee('Xeon Gold 6338')
            ->assertSee('R3-U12')
            ->assertSee($a->code);
    }

    public function test_specs_accept_template_keys_only(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'جوال المبيعات', 'type' => 'هاتف']);

        $this->actingAs($this->owner)->post('/custody/' . $a->id . '/specs', [
            'specs' => ['imei' => '356938035643809', 'hacked' => 'قيمةٌ مدسوسة',
                        'cpu' => 'ليس في قالب الهاتف'],
        ])->assertRedirect();

        $specs = (array) $a->fresh()->specs;
        $this->assertSame('356938035643809', $specs['imei'] ?? null);
        $this->assertArrayNotHasKey('hacked', $specs, 'مفتاحٌ من خارج القالب لا يُحقن في عمود JSON');
        $this->assertArrayNotHasKey('cpu', $specs, 'مفتاحُ قالبٍ آخر ليس مفتاحَ هذا الصنف');
    }

    /* ────────── ٤) الحيازة ────────── */

    public function test_handover_links_custody_to_a_user_and_logs_it(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'لابتوب التصميم', 'type' => 'لابتوب', 'status' => 'متاح']);

        $this->actingAs($this->owner)->post('/custody/' . $a->id . '/handover', [
            'userId' => $this->employee->id, 'at' => now()->toDateString(), 'note' => 'مع الشاحن',
        ])->assertRedirect();

        $a->refresh();
        $this->assertSame($this->employee->id, $a->holder_id, 'العهدة رُبطت بالمستخدم');
        $this->assertSame('قيد الاستخدام', $a->status, '«متاح» بيد موظف تناقضٌ — تنقلب تلقائياً');

        $log = AssetCustody::where('asset_id', $a->id)->firstOrFail();
        $this->assertSame('تسليم', $log->action);
        $this->assertSame($this->employee->id, $log->user_id);
        $this->assertSame('مع الشاحن', $log->note);

        $this->assertTrue(\App\Models\HubNotification::where('user_id', $this->employee->id)
            ->where('module', 'assets')->exists(), 'المستلمُ يُخبَر بعهدةٍ سُجّلت باسمه');
    }

    public function test_recovery_returns_the_asset_to_the_store(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'جوال شركة', 'type' => 'هاتف',
            'holder_id' => $this->employee->id, 'status' => 'قيد الاستخدام']);

        $this->actingAs($this->owner)->post('/custody/' . $a->id . '/recover', [
            'at' => now()->toDateString(),
        ])->assertRedirect();

        $a->refresh();
        $this->assertNull($a->holder_id);
        $this->assertSame('متاح', $a->status, 'أصلٌ رُدَّ للمخزن ويبقى «قيد الاستخدام» يُحسَب مستعمَلاً وهو رفّ');
        $this->assertSame('استرداد', AssetCustody::where('asset_id', $a->id)->firstOrFail()->action);
    }

    public function test_history_keeps_every_holder_not_just_the_current_one(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'لابتوب متنقّل', 'type' => 'لابتوب']);

        $this->actingAs($this->owner)->post('/custody/' . $a->id . '/handover',
            ['userId' => $this->employee->id, 'at' => now()->subDays(30)->toDateString()]);
        $this->actingAs($this->owner)->post('/custody/' . $a->id . '/recover',
            ['at' => now()->subDays(10)->toDateString()]);
        $this->actingAs($this->owner)->post('/custody/' . $a->id . '/handover',
            ['userId' => $this->viewer->id, 'at' => now()->toDateString()]);

        $this->assertSame(3, AssetCustody::where('asset_id', $a->id)->count(),
            'كلُّ حركةٍ صفٌّ باقٍ — لا يُعاد كتابةُ الأثر');
        $this->assertSame($this->viewer->id, $a->fresh()->holder_id);

        $this->actingAs($this->owner)->get('/m/assets/' . $a->id)
            ->assertOk()->assertSee('سجل الحيازة')->assertSee('موظفة');
    }

    /* ────────── ٥) التصاريح ────────── */

    public function test_transfer_permit_moves_the_holder_and_gets_a_number(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'لابتوب منقول', 'type' => 'لابتوب',
            'holder_id' => $this->employee->id, 'status' => 'قيد الاستخدام']);

        $this->actingAs($this->owner)->post('/custody/' . $a->id . '/permit', [
            'kind' => 'نقل', 'at' => now()->toDateString(), 'userId' => $this->viewer->id,
            'note' => 'انتقال للقسم الآخر',
        ])->assertRedirect();

        $p = AssetCustody::where('asset_id', $a->id)->firstOrFail();
        $this->assertSame('نقل', $p->action);
        $this->assertSame('PRM-' . now()->format('Y') . '-0001', $p->permit_no);
        $this->assertSame('ساري', $p->status);
        $this->assertSame($this->viewer->id, $a->fresh()->holder_id, 'النقلُ ينقل الحائز فعلاً');
    }

    public function test_transfer_without_a_recipient_is_refused(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'جهاز', 'type' => 'لابتوب', 'holder_id' => $this->employee->id]);

        $this->actingAs($this->owner)->post('/custody/' . $a->id . '/permit',
            ['kind' => 'نقل', 'at' => now()->toDateString()])->assertStatus(422);

        $this->assertSame($this->employee->id, $a->fresh()->holder_id,
            'نقلٌ بلا منقولٍ إليه كان يُفرّغ الحائز صامتاً');
    }

    public function test_temporary_exit_requires_a_return_date(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'طابعة', 'type' => 'طابعة']);

        $this->actingAs($this->owner)->post('/custody/' . $a->id . '/permit', [
            'kind' => 'خروج مؤقت', 'at' => now()->toDateString(), 'to' => 'ورشة الصيانة',
        ])->assertStatus(422);

        $this->assertSame(0, AssetCustody::where('asset_id', $a->id)->count());
    }

    public function test_return_date_before_exit_date_is_refused(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'طابعة ب', 'type' => 'طابعة']);

        $this->actingAs($this->owner)->post('/custody/' . $a->id . '/permit', [
            'kind' => 'خروج مؤقت', 'at' => now()->toDateString(),
            'due' => now()->subDay()->toDateString(), 'to' => 'ورشة',
        ])->assertStatus(422);
    }

    public function test_final_exit_disposes_the_asset(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'خادم قديم', 'type' => 'سيرفر',
            'holder_id' => $this->employee->id, 'status' => 'قيد الاستخدام']);

        $this->actingAs($this->owner)->post('/custody/' . $a->id . '/permit', [
            'kind' => 'خروج نهائي', 'at' => now()->toDateString(), 'to' => 'بيع للمورد',
        ])->assertRedirect();

        $a->refresh();
        $this->assertSame('مستبعد', $a->status);
        $this->assertNull($a->holder_id);
        $this->assertSame(now()->toDateString(), $a->disposal?->toDateString(),
            '«تالف/مستبعد» بلا تاريخ استبعاد يبقى في الجرد أصلاً قائماً');
    }

    public function test_overdue_permits_surface_on_the_catalog(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'شاشة خرجت للمعرض', 'type' => 'شاشة']);

        $this->actingAs($this->owner)->post('/custody/' . $a->id . '/permit', [
            'kind' => 'خروج مؤقت', 'at' => now()->subDays(20)->toDateString(),
            'due' => now()->subDays(5)->toDateString(), 'to' => 'معرض الكويت',
        ])->assertRedirect();

        $this->actingAs($this->owner)->get('/custody?fresh=1')
            ->assertOk()
            ->assertSee('خرجت بتصريحٍ ولم تعد')
            ->assertSee('شاشة خرجت للمعرض')
            ->assertSee('متأخرة 5 يوماً');
    }

    public function test_registering_the_return_closes_the_permit(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'جهاز للصيانة', 'type' => 'لابتوب']);
        $this->actingAs($this->owner)->post('/custody/' . $a->id . '/permit', [
            'kind' => 'خروج مؤقت', 'at' => now()->subDays(3)->toDateString(),
            'due' => now()->toDateString(), 'to' => 'ورشة الصيانة',
        ]);
        $p = AssetCustody::where('asset_id', $a->id)->firstOrFail();

        $this->actingAs($this->owner)
            ->post("/custody/{$a->id}/permit/{$p->id}/return", ['at' => now()->toDateString()])
            ->assertRedirect();

        $p->refresh();
        $this->assertSame('أُعيد', $p->status);
        $this->assertSame(now()->toDateString(), $p->returned_at?->toDateString());

        // ولا يُغلق مرّتين
        $this->actingAs($this->owner)
            ->post("/custody/{$a->id}/permit/{$p->id}/return", ['at' => now()->toDateString()])
            ->assertStatus(422);
    }

    public function test_permit_document_prints_the_permit(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'سيرفر معار', 'type' => 'سيرفر', 'serial' => 'SRV-991']);
        $this->actingAs($this->owner)->post('/custody/' . $a->id . '/permit', [
            'kind' => 'خروج مؤقت', 'at' => now()->toDateString(),
            'due' => now()->addDays(7)->toDateString(), 'to' => 'مركز البيانات',
            'note' => 'ترقية الذاكرة',
        ]);
        $p = AssetCustody::where('asset_id', $a->id)->firstOrFail();

        $this->actingAs($this->owner)->get("/custody/{$a->id}/permit/{$p->id}")
            ->assertOk()
            ->assertSee($p->permit_no)
            ->assertSee('تصريح عهدة')
            ->assertSee('خروج مؤقت')
            ->assertSee('مركز البيانات')
            ->assertSee('ترقية الذاكرة')
            ->assertSee($a->code)
            ->assertSee('SRV-991');
    }

    public function test_a_permit_of_another_asset_is_not_readable_from_a_guessed_link(): void
    {
        $this->seedCore();
        $mine = Asset::create(['name' => 'أصلي', 'type' => 'لابتوب']);
        $other = Asset::create(['name' => 'أصلٌ آخر', 'type' => 'لابتوب']);
        $this->actingAs($this->owner)->post('/custody/' . $other->id . '/permit', [
            'kind' => 'خروج مؤقت', 'at' => now()->toDateString(),
            'due' => now()->addDay()->toDateString(), 'to' => 'جهة',
        ]);
        $p = AssetCustody::where('asset_id', $other->id)->firstOrFail();

        $this->actingAs($this->owner)->get("/custody/{$mine->id}/permit/{$p->id}")->assertNotFound();
    }

    /* ────────── ٦) الربط بالتوقيع الإلكتروني ────────── */

    public function test_permit_can_be_sent_to_esign_and_is_linked_back(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'لابتوب خارجٌ للعمل', 'type' => 'لابتوب']);
        $this->actingAs($this->owner)->post('/custody/' . $a->id . '/permit', [
            'kind' => 'خروج مؤقت', 'at' => now()->toDateString(),
            'due' => now()->addDays(30)->toDateString(), 'to' => 'موقع العميل',
        ]);
        $p = AssetCustody::where('asset_id', $a->id)->firstOrFail();

        // ورقةُ التصريح تعرض زرّ الإرسال للتوقيع
        $this->actingAs($this->owner)->get("/custody/{$a->id}/permit/{$p->id}")
            ->assertOk()->assertSee('أرسله للتوقيع الإلكتروني');

        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'تصريح خروج عهدة ' . $p->permit_no,
            'free_body' => 'أقرّ باستلام العهدة وإعادتها في موعدها.',
            'pass' => 'p1234', 'link_module' => 'assets', 'link_id' => $a->id, 'permit' => $p->id,
        ])->assertRedirect();

        $req = \App\Models\SignRequest::where('link_module', 'assets')->where('link_id', $a->id)->firstOrFail();
        $this->assertSame($req->id, $p->fresh()->sign_id,
            'التصريحُ يعرف طلبَ توقيعه — والأصلُ قد تتعدّد تصاريحُه فلا يكفي الربطُ به');

        $this->actingAs($this->owner)->get("/custody/{$a->id}/permit/{$p->id}")
            ->assertOk()->assertSee('مربوطٌ بطلب توقيعٍ إلكتروني');
    }

    public function test_a_permit_of_another_asset_is_not_attachable_to_a_sign_request(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'أصلٌ أ', 'type' => 'لابتوب']);
        $b = Asset::create(['name' => 'أصلٌ ب', 'type' => 'لابتوب']);
        $this->actingAs($this->owner)->post('/custody/' . $b->id . '/permit', [
            'kind' => 'خروج مؤقت', 'at' => now()->toDateString(),
            'due' => now()->addDay()->toDateString(), 'to' => 'جهة',
        ]);
        $p = AssetCustody::where('asset_id', $b->id)->firstOrFail();

        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'طلبٌ على الأصل أ', 'free_body' => 'نص', 'pass' => 'p1234',
            'link_module' => 'assets', 'link_id' => $a->id, 'permit' => $p->id,
        ]);

        $this->assertNull($p->fresh()->sign_id,
            'تصريحُ أصلٍ لا يُعلَّق عليه توقيعُ طلبٍ مربوطٍ بأصلٍ آخر');
    }

    /* ────────── ٧) البوّابات ────────── */

    public function test_view_only_reader_cannot_write_custody(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'عهدة محروسة', 'type' => 'لابتوب']);

        $this->actingAs($this->viewer)->get('/custody')->assertOk();
        $this->actingAs($this->viewer)->get('/custody/' . $a->id . '/label')->assertOk();

        $this->actingAs($this->viewer)->post('/custody/' . $a->id . '/handover',
            ['userId' => $this->employee->id, 'at' => now()->toDateString()])->assertForbidden();
        $this->actingAs($this->viewer)->post('/custody/' . $a->id . '/specs',
            ['specs' => ['cpu' => 'x']])->assertForbidden();
        $this->actingAs($this->viewer)->post('/custody/' . $a->id . '/permit',
            ['kind' => 'نقل', 'at' => now()->toDateString(), 'userId' => $this->employee->id])
            ->assertForbidden();

        $this->assertNull($a->fresh()->holder_id);
    }

    public function test_a_user_without_the_assets_module_reads_nothing(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'عهدةٌ لا يراها', 'type' => 'لابتوب']);

        $role = Role::create(['name' => 'بلا أصول', 'scope' => 'all', 'flags' => [], 'matrix' => []]);
        $u = User::create(['name' => 'أجنبي', 'email' => 'nope@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $this->actingAs($u)->get('/custody')->assertForbidden();
        $this->actingAs($u)->get('/custody/cat/LT')->assertForbidden();
        $this->actingAs($u)->get('/custody/' . $a->id . '/label')->assertForbidden();
        $this->actingAs($u)->get('/custody/' . $a->id . '/spec')->assertForbidden();
    }

    public function test_catalog_link_appears_for_asset_readers_only(): void
    {
        $this->seedCore();
        $this->assertTrue(collect(hub_top_links($this->owner))->contains('key', 'custody'));

        $role = Role::create(['name' => 'بلا أصول٢', 'scope' => 'all', 'flags' => [], 'matrix' => []]);
        $u = User::create(['name' => 'أجنبي٢', 'email' => 'nope2@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $this->assertFalse(collect(hub_top_links($u))->contains('key', 'custody'));
    }

    /* ────────── ٨) سجل الأصناف ────────── */

    public function test_every_registered_category_has_a_unique_base_code(): void
    {
        $codes = collect(Custody::cats())->pluck('code');

        $this->assertSame($codes->count(), $codes->unique()->count(),
            'كودان متطابقان لصنفين يخلطان تسلسليهما ويجعلان رابط الصنف غامضاً');
        $this->assertTrue(collect(config('hub.modules.assets.search'))->contains('code'),
            'الكودُ يُبحث به — وإلا طُبع على الملصق ولم يُوجَد بمسحه');
    }
}
