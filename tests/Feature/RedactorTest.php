<?php

namespace Tests\Feature;

use App\Support\ErrorLog;
use App\Support\Redactor;
use App\Support\SecurityRadar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WP-1.3 — المُطهِّر الواحد `Redactor`: كل مفتاحٍ سرّي يُطمَس بالعمق، وكل نمطِ
 * رمزٍ (Bearer وJWT وPEM وlyn_ وسلسلة الاستعلام ورمز البوت) يُطمَس في النص، والطمسُ
 * ثابتٌ (تشغيله مرتين = مرة)، والسبعةُ القائمة تُفوَّض إليه لا تُستبدَل.
 */
class RedactorTest extends TestCase
{
    /** قائمةُ spec كاملةً: كلُّ مفتاحٍ يُطمَس ولو كان في العمق السادس */
    public function test_every_spec_key_is_redacted_in_nested_arrays_at_depth(): void
    {
        $keys = ['password', 'passwd', 'pass', 'secret', 'token', 'access_token', 'refresh_token',
                 'authorization', 'cookie', 'session', 'api_key', 'apikey', 'client_secret',
                 'private_key', 'smtp_password', 'database_password'];

        $leaf = ['name' => 'عميل ظاهر'];
        foreach ($keys as $i => $k) $leaf[$k] = 'SecretValue' . $i . 'xyz';
        // عمقٌ حقيقي: المفاتيح في المستوى الخامس من الشجرة
        $in = ['a' => ['b' => ['c' => ['d' => $leaf]]]];

        $flat = json_encode(Redactor::arr($in), JSON_UNESCAPED_UNICODE);
        foreach ($keys as $i => $k) {
            $this->assertStringNotContainsString('SecretValue' . $i . 'xyz', $flat,
                "قيمةُ المفتاح السرّي `{$k}` نجت من الطمس في العمق");
        }
        $this->assertStringContainsString('عميل ظاهر', $flat, 'قيمةٌ بريئة طُمست — الطمسُ بالمفتاح لا بالجملة');
    }

    /** مفاتيحُ Audit::MASKED وAUDIT_SECRET من النماذج تُطمَس أيضاً */
    public function test_audit_masked_and_model_secret_keys_are_redacted(): void
    {
        $in = ['token_hash' => 'HashValA1', 'value_cipher' => 'CipherValB2', 'secret_cipher' => 'CipherValC3',
               'totp_secret_cipher' => 'TotpValD4', 'recovery_codes' => 'CodesValE5',
               'remember_token' => 'RememberF6', 'password_hash' => 'PwHashG7'];
        $flat = json_encode(Redactor::arr($in), JSON_UNESCAPED_UNICODE);
        foreach (['HashValA1', 'CipherValB2', 'CipherValC3', 'TotpValD4', 'CodesValE5', 'RememberF6', 'PwHashG7'] as $v) {
            $this->assertStringNotContainsString($v, $flat, "قيمةُ عمودٍ سرّي ({$v}) نجت من الطمس");
        }
    }

    /** أنماطُ النصّ: Bearer وJWT وPEM وlyn_* ورمزُ الاستعلام ورمزُ بوت تلجرام */
    public function test_text_redacts_bearer_jwt_pem_lyn_and_query_tokens(): void
    {
        $bearer = 'abcDEF123456789tok';
        $jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.c2lnbmF0dXJlLXNpZ25hdHVyZQ';
        $lyn = 'lyn_' . str_repeat('Ab9', 15);
        $pemBody = 'MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC7VJTUt9Us8cKj';
        $pem = "-----BEGIN PRIVATE KEY-----\n{$pemBody}\n-----END PRIVATE KEY-----";

        $out = Redactor::text("Authorization: Bearer {$bearer} ثم {$jwt} ثم {$pem} ثم {$lyn}"
            . ' ثم https://cb.example/x?token=QueryTok99&page=2 ثم password=DsnPw77 host=db'
            . ' ثم https://api.telegram.org/bot123456:AAH-secretBotToken/sendMessage');

        $this->assertStringNotContainsString($bearer, $out, 'رمزُ Bearer نجا');
        $this->assertStringNotContainsString($jwt, $out, 'رمزُ JWT نجا');
        $this->assertStringNotContainsString($pemBody, $out, 'جسمُ PEM نجا');
        $this->assertStringNotContainsString($lyn, $out, 'رمزُ lyn_* نجا');
        $this->assertStringNotContainsString('QueryTok99', $out, 'رمزُ سلسلة الاستعلام نجا');
        $this->assertStringNotContainsString('DsnPw77', $out, 'كلمةُ مرور DSN نجت');
        $this->assertStringNotContainsString('AAH-secretBotToken', $out, 'رمزُ البوت نجا');
        $this->assertStringContainsString('page=2', $out, 'معاملٌ بريء طُمس — الطمسُ بالمفتاح لا بالجملة');
    }

    /** PEM مبتور (بلا END — أثرٌ مقصوص) يُطمَس جسمُه أيضاً */
    public function test_truncated_pem_block_is_still_redacted(): void
    {
        $pemBody = 'MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC7VJTUt9Us8cKj';
        $out = Redactor::text("trace: -----BEGIN RSA PRIVATE KEY-----\n{$pemBody}");
        $this->assertStringNotContainsString($pemBody, $out, 'جسمُ PEM المبتور نجا');
    }

    /** الطمسُ ثابت: تشغيلُه مرتين يعطي ناتجَ المرة الأولى حرفياً */
    public function test_redaction_is_idempotent(): void
    {
        $s = "Bearer abcDEF123456789tok و /sign/" . Str::random(48) . " و ?token=QueryTok99"
            . ' و eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.c2lnbmF0dXJlLXNpZ25hdHVyZQ'
            . ' و lyn_' . str_repeat('Zx7', 15) . ' و password=DsnPw77';
        $once = Redactor::text($s);
        $this->assertSame($once, Redactor::text($once), 'Redactor::text ليس ثابتاً — التشغيل الثاني غيّر الناتج');

        $a = ['password' => 'P1x', 'ok' => 'نص', 'deep' => ['token' => 'T2y', 'url' => 'https://x/?key=K3z']];
        $onceA = Redactor::arr($a);
        $this->assertSame($onceA, Redactor::arr($onceA), 'Redactor::arr ليس ثابتاً — التشغيل الثاني غيّر الناتج');
    }

    /** البصمةُ بصيغة Auditable::auditRedact نفسِها: sha256:<16hex> */
    public function test_fingerprint_matches_auditable_format(): void
    {
        $this->assertSame('sha256:' . substr(hash('sha256', 'قيمة سرّية'), 0, 16), Redactor::fingerprint('قيمة سرّية'));
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{16}$/', Redactor::fingerprint('x'));
    }

    /** التفويض لا الاستبدال: قواعدُ ErrorLog القائمة تبقى بحرفها */
    public function test_errorlog_delegation_keeps_existing_behaviour(): void
    {
        $tok = str_repeat('Ab3', 16);
        $this->assertStringNotContainsString($tok, ErrorLog::redact("/hook/{$tok}"));
        $this->assertSame('/m/tasks', ErrorLog::redact('/m/tasks'), 'نصٌّ نظيف تغيّر — التفويض بدّل السلوك');
        // وقاعدةُ Bearer الجديدة تسري عبر الواجهة القائمة نفسِها
        $this->assertStringNotContainsString('abcDEF123456789tok', ErrorLog::redact('hdr: Bearer abcDEF123456789tok'));
    }

    /** الرادار يخزّن المسارَ مطموساً لرمزٍ عامٍّ مخمَّن — والتفصيلَ مطموساً */
    public function test_radar_stores_redacted_path_for_guessed_public_token(): void
    {
        $this->seedCore();
        $guess = Str::random(48);

        $this->get('/sign/' . $guess)->assertNotFound();   // زائرٌ يخمّن رمزَ توقيع

        $row = DB::table('access_denials')->where('kind', 'تخمين رابط')->orderByDesc('id')->first();
        $this->assertNotNull($row, 'تخمينُ الرابط لم يُسجَّل أصلاً');
        $this->assertStringNotContainsString($guess, (string) $row->path,
            'الرمزُ المخمَّن خُزّن بنصّه في access_denials.path — بابٌ خلفيّ لقارئ الرادار');
        $this->assertStringContainsString('{رمز}', (string) $row->path);

        // والتفصيلُ يمرّ بالمُطهِّر نفسِه
        $r = \Illuminate\Http\Request::create('/admin/x', 'GET');
        SecurityRadar::record($r, 'وصول مرفوض', 'ترويسة: Bearer abcDEF123456789tok');
        $detail = (string) DB::table('access_denials')->where('kind', 'وصول مرفوض')->orderByDesc('id')->value('detail');
        $this->assertStringNotContainsString('abcDEF123456789tok', $detail, 'التفصيلُ خُزّن بسرّه');
    }

    /** حمولةُ الويبهوك الوارد تُخزَّن مطموسةً بالمفتاح والنمط — والبريءُ يبقى */
    public function test_inbound_hook_payload_is_stored_redacted(): void
    {
        $this->seedCore();
        $hook = \App\Models\InboundHook::create([
            'name' => 'استقبال اختبار', 'token' => Str::random(48), 'secret' => null,
            'event' => 'lead.created', 'enabled' => true, 'created_by' => $this->owner->id,
        ]);
        $body = json_encode(['name' => 'عميل ظاهر', 'password' => 'HookPw11', 'api_key' => 'HookKey22',
                             'note' => 'auth: Bearer abcDEF123456789tok'], JSON_UNESCAPED_UNICODE);

        $this->call('POST', route('hook.receive', $hook->token), [], [], [],
            ['CONTENT_TYPE' => 'application/json'], $body)->assertOk();

        $stored = (string) DB::table('inbound_hook_events')->where('hook_id', $hook->id)->value('payload');
        $this->assertStringNotContainsString('HookPw11', $stored, 'password خُزّنت بنصّها في الحمولة');
        $this->assertStringNotContainsString('HookKey22', $stored, 'api_key خُزّن بنصّه في الحمولة');
        $this->assertStringNotContainsString('abcDEF123456789tok', $stored, 'رمزُ Bearer داخل قيمةٍ نجا');
        $this->assertStringContainsString('عميل ظاهر', $stored, 'قيمةٌ بريئة طُمست أو ضاعت من الحمولة');
    }
}
