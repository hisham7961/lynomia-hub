<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\VaultSecret;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الموجة السابعة — **الترقيةُ الصامتة تكتب السرَّ الصريح في سجلٍّ مختوم.**
 *
 * `VaultSecret::booted` تُرقّي أيَّ سرٍّ قديمٍ غيرِ مشفَّر عند أوّل حفظ:
 * `$m->secret_cipher = $raw` فيمرّ بالكاست ويُشفَّر. وهنا يقع العطل:
 *
 *   · `getDirty()['secret_cipher']` = النصُّ المشفَّر الجديد (لا بأس)
 *   · `getOriginal()['secret_cipher']` = **النصُّ الصريح القديم**
 *
 * و`Auditable` يكتب الاثنين في `audits.before/after`. فالإجراءُ الذي بُني
 * ليُخفي السرَّ يَنسخه صريحاً إلى سجلٍّ يقرؤه كلُّ صاحبِ صلاحية تدقيق، ويدخل
 * كلَّ نسخةٍ احتياطية.
 *
 * **ولا يُعالَج بالمسح**: `before`/`after` من أعمدة البصمة (`AuditEntry::SEALED`)،
 * فتعديلُهما يكسر سلسلةَ التدقيق نفسَها — أي يُتلف الضمانَ لإخفاء أثرِ خرقه.
 * فالعلاجُ **منعٌ عند المصدر**: عمودُ السرّ يُستبدَل ببصمةٍ قصيرة قبل الكتابة —
 * فيبقى «تغيَّر/لم يتغيّر» مقروءاً ولا تُكتب القيمةُ أبداً. وما سبق يُبلَّغ
 * ليُدوَّر لا ليُمحى: سرٌّ ظهر في سجلٍّ يُبدَّل، لا يُمسح أثرُه.
 */
class SecretsNeverInAuditRound7Test extends TestCase
{
    public function test_the_silent_upgrade_does_not_copy_the_secret_into_the_audit_trail(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $plain = 'PLAINTEXT-' . Str::random(12);

        // سرٌّ قديم: نصٌّ صريح مكتوبٌ قبل إضافة الكاست (يُكتب خاماً بلا Eloquent)
        $id = (string) Str::uuid();
        DB::table('vault_secrets')->insert(['id' => $id, 'title' => 'سرّ قديم',
            'secret_cipher' => $plain, 'created_at' => now(), 'updated_at' => now()]);

        // أوّلُ حفظٍ لأي حقل يُطلق الترقيةَ الصامتة
        $m = VaultSecret::findOrFail($id);
        $m->title = 'سرّ قديم (مُحدَّث)';
        $m->save();

        $this->assertNotSame($plain, (string) DB::table('vault_secrets')->where('id', $id)->value('secret_cipher'),
            'الترقيةُ لم تقع أصلاً — الاختبارُ يحرس فراغاً');

        $trail = (string) DB::table('audits')->where('record_id', $id)
            ->selectRaw("COALESCE(`before`,'') b, COALESCE(`after`,'') a")->get()
            ->map(fn ($r) => $r->b . $r->a)->implode('|');

        $this->assertStringNotContainsString($plain, $trail,
            'السرُّ الصريح نُسخ إلى `audits` بيدِ الإجراء الذي يُخفيه — وسجلُّ '
            . 'التدقيق مختومٌ فلا يُمحى منه، ويُقرأ بصلاحية تدقيقٍ ويدخل كلَّ نسخة');
    }

    /** والقيدُ يبقى مفيداً: تغيُّرُ السرّ مرئيٌّ بلا كشفِ قيمته */
    public function test_a_secret_change_is_still_visible_as_a_fingerprint(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $s = VaultSecret::create(['title' => 'سرّ', 'secret_cipher' => 'first-value']);
        $s->secret_cipher = 'second-value';
        $s->save();

        $row = AuditEntry::where('record_id', $s->id)->where('action', 'تعديل')
            ->orderByDesc('id')->first();
        $this->assertNotNull($row, 'التعديلُ لم يُسجَّل أصلاً');

        $before = (array) ($row->before ?? []);
        $after  = (array) ($row->after ?? []);
        $this->assertArrayHasKey('secret_cipher', $after, 'تغيُّرُ السرّ يجب أن يبقى مرئيّاً');
        $this->assertStringStartsWith('sha256:', (string) $after['secret_cipher']);
        $this->assertNotSame($before['secret_cipher'] ?? null, $after['secret_cipher'],
            'البصمتان متطابقتان رغم تغيُّر القيمة — البصمةُ لا تصف شيئاً');
        $this->assertStringNotContainsString('second-value', json_encode([$before, $after], JSON_UNESCAPED_UNICODE));
    }

    /** و`hub:encrypt-vault` يُبلّغ عن الأثر القديم بدل أن يكسر السلسلة لإخفائه */
    public function test_the_command_reports_legacy_plaintext_in_the_sealed_trail(): void
    {
        $this->seedCore();

        $plain = 'OLD-LEAK-' . Str::random(10);
        DB::table('audits')->insert(['action' => 'تعديل',
            'module' => 'vault', 'record_id' => (string) Str::uuid(), 'name' => 'سرّ',
            'before' => json_encode(['secret_cipher' => $plain]), 'after' => json_encode(['title' => 'x']),
            'created_at' => now()]);

        $this->artisan('hub:encrypt-vault')
            ->expectsOutputToContain('قيد تدقيق')
            ->assertExitCode(0);
    }
}
