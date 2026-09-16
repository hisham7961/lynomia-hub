<?php

namespace Tests\Feature\Mobile;

use App\Models\Attachment;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **وجهةُ صفِّ صاحبِ الشأنِ تُحسَب في الخادمِ — لا تُترك للتطبيقِ يستنتجها.**
 *
 * صفُّ الرادارِ الذي يخصّ صاحبَه (PROD-05) يُعرَض لمن لا يملك `hr:v` قصداً،
 * فوجهتُه **ملفُّه هو** لا سجلُّ الوحدةِ الذي يردّه ٤٠٣. والويبُ يحسمها في
 * الخادمِ منذ `hub_expiry_url()` — أمّا الجوالُ فكان يُصدّر رايةَ `self`
 * وحدَها ويترك الاستنتاجَ للتطبيق.
 *
 * وسُجّل ذلك في سجلِّ المجلسِ بوصفِه «بقي مُعلَناً: الرابطُ العميقُ في الجوال
 * يردّ ٤٠٣ **حتى يقرأ التطبيقُ رايةَ `self`**» — أي أنّ إغلاقَه مُعلَّقٌ على
 * إصدارِ تطبيقٍ لا على الخادم.
 *
 * **وهو تعليقٌ في غيرِ محلِّه.** القاعدةُ معروفةٌ للخادمِ كاملةً، وكلُّ تطبيقٍ
 * يستنتجها بنفسِه قد يستنتجها خطأً أو ينساها — وثمنُ النسيانِ ٤٠٣ في وجهِ
 * صاحبِ الوثيقةِ التي عليه هو أن يجدّدها. فالخادمُ يُصدّر **الوجهةَ محسوبةً**،
 * والرايةُ تبقى كما هي: **إضافةٌ لا كسرُ عقد.**
 */
class MobileExpiryTargetTest extends TestCase
{
    use InteractsWithMobileAuth;

    /** موظّفةٌ بلا `hr:v` — حالُ من يُنذَر بوثيقتِه ولا يفتح وحدةَ الموارد البشريّة */
    private function staff(): array
    {
        $role = Role::create(['name' => 'موظّفة' . Str::random(4), 'scope' => 'all',
            'flags' => [], 'matrix' => ['tasks' => ['v' => 1]]]);
        $u = User::create(['name' => 'لطيفة السالم', 'email' => Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $e = Employee::create(['name' => 'لطيفة السالم', 'status' => 'نشط', 'user_id' => $u->id,
            'iqama_exp' => now()->addDays(6)->toDateString()]);

        return [$u, $e];
    }

    private function rows(User $u): array
    {
        $tok = $this->mobileLogin($u)['access_token'];
        $res = $this->withHeaders($this->bearer($tok))
            ->getJson('/api/mobile/v1/home')->assertOk();

        $body = $res->json('data') ?? [];

        return $body['expiry'] ?? $body['attention'] ?? [];
    }

    public function test_the_payload_carries_the_computed_target_not_only_the_flag(): void
    {
        $this->seedCore();
        [$u, $e] = $this->staff();

        Storage::disk('local')->put($p = 'hub/' . Str::random(10) . '.pdf', '%PDF اختبار');
        Attachment::create(['module' => 'hr', 'record_id' => $e->id, 'kind' => 'id',
            'disk' => 'local', 'path' => $p, 'original_name' => 'iqama.pdf',
            'mime' => 'application/pdf', 'size' => 12, 'av_status' => 'clean',
            'uploaded_by' => $this->owner->id, 'expires_at' => now()->addDays(6)]);

        $rows = collect($this->rows($u));
        $this->assertNotEmpty($rows, 'لم يُعِد الجوالُ صفَّ رادارٍ واحداً — فالمسحُ لا يقيس شيئاً');

        $mine = $rows->where('self', true);
        $this->assertNotEmpty($mine, 'صفُّ صاحبةِ الشأنِ لم يصلها على الجوالِ أصلاً');

        foreach ($mine as $r) {
            $this->assertArrayHasKey('target', $r,
                'الجوالُ يُصدّر الرايةَ ولا يُصدّر الوجهةَ — فيستنتجها كلُّ تطبيقٍ بنفسِه');
            $this->assertSame('portal.me', $r['target']['route'] ?? null,
                'وجهةُ صفِّ صاحبِ الشأنِ ملفُّه هو — لا سجلُّ الوحدةِ الذي يردّه ٤٠٣');
        }
    }

    /** ولا قدرةَ تتغيّر: الصفُّ العاديُّ وجهتُه سجلُّ وحدتِه كما كانت */
    public function test_an_ordinary_row_still_points_at_its_module_record(): void
    {
        $this->seedCore();
        Employee::create(['name' => 'زميلٌ يراه المالك', 'status' => 'نشط',
            'iqama_exp' => now()->addDays(5)->toDateString()]);

        $rows = collect($this->rows($this->owner))->where('self', false);
        $this->assertNotEmpty($rows, 'لا صفَّ عاديّاً — الاتّجاهُ الآخرُ غيرُ مقيس');

        foreach ($rows as $r) {
            $this->assertSame('m.show', $r['target']['route'] ?? null,
                'الصفُّ العاديُّ وجهتُه سجلُّ وحدتِه');
            $this->assertSame([$r['module'], $r['id']], $r['target']['args'] ?? null,
                'ووسائطُ الوجهةِ وحدتُه ومعرّفُه');
        }
    }
}
