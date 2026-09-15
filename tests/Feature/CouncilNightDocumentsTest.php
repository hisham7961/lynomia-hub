<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * مجلسُ الخبراء · N-4 — **الحزامُ ناقصُ بابَين** (التحقّقُ المستقلّ الثامن).
 *
 * «حظرُ نقلِ الملفاتِ خارجَ الدوام» يحرسُ `m.export` و`esign.pdf` و`custody.*`
 * — وأوراقُ العهدةِ مدرجةٌ بتعليلٍ صريح: «تُطبع وتُحفظ PDF — نقلُ ملفاتٍ بكلِّ
 * معنى». **وبالتعليلِ نفسِه** كان `quotes.pdf` و`quotes.doc` و`changeorders.pdf`
 * و`purchases.doc` خارجَ القائمة، فيخرج **العرضُ التجاريُّ بأسعارِه** PDF الساعةَ
 * الثالثةَ فجراً بينما يُردّ جدولُ المشتريات CSV ٤٠٣ في الدقيقةِ نفسِها.
 *
 * وأصدقُ دليلٍ على أنّ الإغفالَ سهوٌ لا قرار: `esign.pdf` **محروس** و`esign.doc`
 * — من المتحكّمِ نفسِه وللمستندِ نفسِه — **ليس كذلك**.
 *
 * **والإغلاقُ ليس سطراً في قائمة:** منعٌ بلا استثناءٍ يقابله نزعُ قدرة. فاستثناءُ
 * `exportNight` — المقصورُ اليومَ على `m.export` — صار **خريطةً** تُسمّي وحدةَ كلِّ
 * بابٍ، فيمرّ حاملُ المفتاحِ على وحدتِه ويبقى المنعُ على البقيّة.
 */
class CouncilNightDocumentsTest extends TestCase
{
    private function user(string $email, array $matrix): User
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => 'all', 'flags' => [],
            'matrix' => $matrix, 'companies' => null]);

        return User::create(['name' => 'مستخدم', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    private function night(): void
    {
        Carbon::setTestNow(Carbon::parse(now()->toDateString() . ' 22:30:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ═══════════ 1 — كلُّ بابٍ يبثُّ مستنداً داخلَ الحزام ═══════════ */

    public function test_every_document_route_is_inside_the_night_guard(): void
    {
        $ref = new \ReflectionClass(\App\Http\Middleware\WorkHours::class);
        $guarded = (array) $ref->getConstant('FILE_ROUTES');

        // **الثنائيّةُ تُحرَس**: تُعيد `application/pdf` بـ`Content-Disposition`
        foreach (['quotes.pdf', 'changeorders.pdf', 'esign.pdf'] as $name) {
            $this->assertContains($name, $guarded,
                "المسارُ «{$name}» يبثُّ ملفاً ثنائيّاً وهو خارجَ حظرِ نقلِ الملفات — "
                . 'والتعليلُ الذي أدخل أوراقَ العهدةِ يشملُه حرفاً بحرف');
        }
    }

    /* ═══════════ 1ب — والشاشةُ لا تُحجب باسمِ حظرِ الملفات ═══════════ */

    public function test_printable_screens_are_not_treated_as_file_transfer(): void
    {
        $ref = new \ReflectionClass(\App\Http\Middleware\WorkHours::class);
        $guarded = (array) $ref->getConstant('FILE_ROUTES');

        /*
         * `quotes.doc` و`purchases.doc` و`esign.doc` تُعيد `view(...)` — صفحةَ
         * طباعةٍ بـ`text/html` لا ملفّاً. وحجبُها باسمِ «حظرِ نقلِ الملفات» نزعُ
         * قدرة، وأشدُّها `esign.doc` إذ لا وحدةَ `esign` فلا مفتاحَ استثناءٍ لها.
         */
        foreach (['quotes.doc', 'purchases.doc', 'esign.doc'] as $name) {
            $this->assertNotContains($name, $guarded,
                "«{$name}» شاشةُ طباعةٍ (`view`) لا ملفّاً — حجبُها ليلاً نزعُ قدرةٍ لا إصلاح");
        }
    }

    /* ═══════════ 2 — والاستثناءُ قابلٌ للمنح لكلِّ وحدةٍ محروسة ═══════════ */

    public function test_exportnight_is_grantable_for_every_guarded_module(): void
    {
        $modules = (array) (config('hub_permissions.exportNight.modules') ?? []);

        foreach (['quotes', 'changeorders'] as $m) {
            $this->assertContains($m, $modules,
                "وحدةُ «{$m}» محروسةٌ ليلاً ومفتاحُ `exportNight` غيرُ معروضٍ عليها — "
                . 'منعٌ بلا علاجٍ يُمنح، وهو نزعُ قدرةٍ لا إصلاح');
        }
    }

    /* ═══════════ 3 — الحارسُ يعمل حيّاً: منعٌ ليلاً، ومرورٌ لحاملِ المفتاح ═══════════ */

    public function test_quote_pdf_is_blocked_at_night_and_the_key_opens_it(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_on', '1');
        $this->night();

        $quote = \App\Models\Quote::create([
            'title' => 'عرضُ الليل', 'client_name' => 'عميلٌ ما',
            'status' => 'مسوّدة', 'currency' => 'KWD',
        ]);

        // بلا المفتاح: ممنوعٌ كما يُمنع تصديرُ الوحدة
        $plain = $this->user('q-plain@test.local', ['quotes' => ['v' => 1]]);
        $this->actingAs($plain)->get(route('quotes.pdf', $quote->id))->assertStatus(403);

        // وبالمفتاحِ على وحدتِه: يمرّ — لا قدرةَ تُنتزع
        $keyed = $this->user('q-key@test.local', ['quotes' => ['v' => 1, 'exportNight' => 1]]);
        $this->actingAs($keyed)->get(route('quotes.pdf', $quote->id))->assertOk();

        // والمالكُ مستثنًى كما في سائرِ الضابط
        $this->actingAs($this->owner)->get(route('quotes.pdf', $quote->id))->assertOk();
    }

    public function test_daytime_document_routes_are_untouched(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_on', '1');
        Carbon::setTestNow(Carbon::parse(now()->toDateString() . ' 10:00:00'));

        $quote = \App\Models\Quote::create([
            'title' => 'عرضُ النهار', 'client_name' => 'عميلٌ ما',
            'status' => 'مسوّدة', 'currency' => 'KWD',
        ]);

        $plain = $this->user('q-day@test.local', ['quotes' => ['v' => 1]]);
        $this->actingAs($plain)->get(route('quotes.pdf', $quote->id))->assertOk();
    }
}
