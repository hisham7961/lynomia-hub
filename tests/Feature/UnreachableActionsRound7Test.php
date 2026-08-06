<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\SignRequest;
use Tests\TestCase;

/**
 * الموجة السابعة — **فعلٌ مبنيٌّ بتمامه ولا بابَ إليه.**
 *
 * `POST esign/{id}/extend` مسارٌ مسجَّل، ومتحكّمُه مكتوبٌ ومحروسٌ بالصلاحية
 * ومُوثَّقٌ في سجل التدقيق — **ولا يُشار إليه من أيّ قالبٍ ولا جافاسكربت ولا
 * أمر** (فُحص المشروع كلُّه: صفرُ إشارة). ولا اختبارَ واحدٌ يمسّه.
 *
 * فتمديدُ صلاحية رابط التوقيع مستحيلٌ من الواجهة: رابطٌ أوشك على الانتهاء
 * والموقّعُ لم يوقّع بعدُ يُجبر صاحبَ الطلب على **إلغائه وإنشاء طلبٍ جديد** —
 * فتنقطع سلسلةُ الأدلة وتُستأنف من الصفر، ويُطالَب الموقّعُ بالبدء من جديد.
 * وهذا بالضبط ما بُنيت `extend` لتفاديه.
 *
 * وهذا صنفٌ من العيب لا تراه حزمةٌ خضراء ولا مسحُ شاشات: الشيفرةُ سليمة،
 * والمسارُ يعمل إن ناديتَه بـcurl — والمستخدمُ لا يملك سبيلاً إليه.
 */
class UnreachableActionsRound7Test extends TestCase
{
    protected function pending(): SignRequest
    {
        $c = Contract::create(['title' => 'عقدٌ للتوقيع', 'type' => 'عقد عميل',
            'status' => 'ساري', 'date_start' => now()->toDateString()]);

        return SignRequest::create(['contract_id' => $c->id, 'title' => 'وثيقةٌ معلّقة',
            'body' => 'نصٌّ', 'pass' => 'x', 'status' => 'بانتظار التوقيع',
            'token' => 'tk' . random_int(100000, 999999),
            'verify_code' => 'VC' . random_int(100000, 999999),
            'expires_at' => now()->addDays(2)]);
    }

    /** ١) البابُ موجودٌ في الشاشة */
    public function test_the_esign_screen_offers_a_way_to_extend_an_expiring_link(): void
    {
        $this->seedCore();
        $req = $this->pending();

        $res = $this->actingAs($this->owner)->get('/esign');
        $res->assertOk();
        $res->assertSee(route('esign.extend', $req->id), false);
    }

    /** ٢) والبابُ يعمل: التمديد يقع فعلاً ويُوثَّق */
    public function test_extending_actually_moves_the_expiry_and_is_audited(): void
    {
        $this->seedCore();
        $req = $this->pending();
        $before = $req->expires_at;

        $this->actingAs($this->owner)
            ->post("/esign/{$req->id}/extend", ['days' => 10])
            ->assertRedirect();

        $after = $req->fresh()->expires_at;
        $this->assertTrue($after->gt($before), 'لم تتحرّك صلاحيةُ الرابط');
        $this->assertSame(10, (int) round(now()->diffInDays($after)));

        $this->assertDatabaseHas('audits', ['action' => 'تمديد صلاحية طلب توقيع']);
    }

    /** ٣) ومحروسٌ: مَن لا يملك تعديلَ العقود لا يمدّد */
    public function test_extending_needs_the_contracts_edit_permission(): void
    {
        $this->seedCore();
        $req = $this->pending();

        $this->actingAs($this->viewer)
            ->post("/esign/{$req->id}/extend", ['days' => 10])
            ->assertForbidden();

        $this->assertSame((string) $req->expires_at, (string) $req->fresh()->expires_at);
    }

    /**
     * ٤) **والحارسُ العامّ**: لا يبقى في المشروع مسارٌ كاتبٌ بلا أيّ بابٍ إليه.
     *
     * يُفحص كلُّ مسارٍ مسمّى غيرِ قارئ: هل يُشار إليه من قالبٍ أو متحكّمٍ أو
     * جافاسكربت — بـ`route('اسمه')` أو بمساره النصّيّ؟ ما لا مُشيرَ له فعلٌ
     * لا يستطيع أحدٌ استدعاءه من النظام.
     */
    public function test_no_writing_route_is_left_without_a_door(): void
    {
        $allowed = [
            'jslog',            // navigator.sendBeacon بمسارٍ نصّيّ — مفحوصٌ أدناه كذلك
            'logout',           // في الشريط الجانبي بنموذجٍ مباشر
        ];

        $src = '';
        foreach ([base_path('resources/views'), base_path('app'), base_path('public/js')] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $f) {
                if ($f->isFile() && preg_match('/\.(php|js)$/', $f->getFilename())) {
                    $src .= (string) file_get_contents($f->getPathname()) . "\n";
                }
            }
        }

        $orphans = [];
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $r) {
            $n = $r->getName();
            if (! $n || in_array($n, $allowed, true)) continue;
            if (! array_intersect($r->methods(), ['POST', 'PUT', 'PATCH', 'DELETE'])) continue;

            if (str_contains($src, "route('" . $n . "'") || str_contains($src, 'route("' . $n . '"')) continue;

            // إشارةٌ بالمسار النصّيّ (fetch/sendBeacon). ومسارٌ فيه عنصرٌ متغيّر
            // يُطلَب منه **لاحقتُه الثابتة** لا بادئتُه: `/esign` تظهر في كل
            // شاشةٍ فتبتلع `esign/{id}/extend` وتُعمي الحارس.
            $u = $r->uri();
            $needle = str_contains($u, '{')
                ? (($tail = trim((string) preg_replace('/^.*\}/', '', $u), '/')) !== '' ? '/' . $tail : null)
                : '/' . $u;
            if ($needle !== null && str_contains($src, $needle)) continue;

            $orphans[] = $n . ' (' . implode('|', $r->methods()) . ' ' . $r->uri() . ')';
        }

        $this->assertSame([], $orphans,
            'مساراتٌ كاتبةٌ بلا أيّ بابٍ إليها — شيفرةٌ تعمل ولا يستطيع مستخدمٌ '
            . 'استدعاءها: ' . implode(' · ', $orphans));
    }
}
