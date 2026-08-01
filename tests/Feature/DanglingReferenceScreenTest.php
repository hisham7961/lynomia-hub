<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **كل شاشةٍ تنفتح على مرجعٍ معلّق.**
 *
 * بلاغُ «الباقات والتسعير: خطأ غير متوقّع» سببُه صفٌّ واحد: باقةٌ تشير إلى خدمةٍ
 * لا تُرى — حُذفت، أو خارج نطاق القارئ، أو معرّفُها لم يعد موجوداً. والشاشة
 * تقرأ `$svc->cost` فتقع «قراءةُ خاصيةٍ على null»، ويحوّلها Laravel إلى استثناء:
 * **خطأ ٥٠٠ على الشاشة كلّها**.
 *
 * والمرجعُ المعلّق ليس حالةً نادرة، بل **الحالة العادية** في نظامٍ يعمل: يُحذف
 * سجلٌّ ناعماً وتبقى إشاراتُ غيره إليه، أو يُعزل قارئٌ بشركةٍ فتغيب عنه نصفُ
 * المراجع. فالحارسُ هنا يزرع مرجعاً معلّقاً في **كل** وحدةٍ لها حقلٌ مرجعيّ،
 * ثم يفتح كل شاشةٍ محسوبة — وما يسقط يُسمّى.
 */
class DanglingReferenceScreenTest extends TestCase
{
    /** الشاشات المحسوبة: تقرأ وحداتٍ عدة وتربطها، فهي أكثرُ ما يقع فيه هذا */
    private const SCREENS = [
        '/pricing', '/media/center', '/delivery', '/compliance', '/assets/life',
        '/appsprojects', '/ceo', '/team', '/okrs/board', '/policies/board',
        '/staff', '/morning', '/alerts', '/me', '/',
    ];

    /**
     * يزرع في كل جدولٍ صفّاً واحداً تشير كلُّ حقوله المرجعية إلى معرّفٍ لا وجود
     * له — وهو ما يخلّفه الحذف الناعم وتبديلُ الشركات في أي نظامٍ عاش سنة.
     */
    private function seedDangling(): void
    {
        $ghost = (string) Str::uuid();

        foreach (hub_modules() as $mk => $def) {
            $table = (string) ($def['table'] ?? '');
            if ($table === '' || ! Schema::hasTable($table)) continue;

            $row = ['id' => (string) Str::uuid()];
            foreach ($def['fields'] ?? [] as $f) {
                $col = (string) ($f['col'] ?? '');
                if ($col === '' || ! Schema::hasColumn($table, $col)) continue;

                if (($f['type'] ?? '') === 'ref' && empty($f['multi'])) {
                    $row[$col] = $ghost;                       // ← المرجع المعلّق
                } elseif (! empty($f['required'])) {
                    $row[$col] = match ($f['type'] ?? 'text') {
                        'num', 'money' => 1,
                        'date' => now()->toDateString(),
                        'sel'  => (string) (($f['options'][0] ?? 'قيمة')),
                        default => 'قيمة اختبار',
                    };
                }
            }
            foreach (['created_at', 'updated_at'] as $ts) {
                if (Schema::hasColumn($table, $ts)) $row[$ts] = now();
            }

            // جداولُ لها أعمدةٌ إلزامية خارج سجل الوحدة تُتخطّى بهدوء —
            // الغرضُ تغطيةُ ما يُزرع لا إسقاطُ الحارس على ما لا يُزرع
            try { DB::table($table)->insert($row); } catch (\Throwable $e) { /* تُتخطّى */ }
        }
    }

    public function test_every_computed_screen_survives_a_dangling_reference(): void
    {
        $this->seedCore();
        $this->seedDangling();

        $broken = [];
        foreach (self::SCREENS as $url) {
            $code = $this->actingAs($this->owner)->get($url)->getStatusCode();
            if ($code >= 500) $broken[] = "{$url} ({$code})";
        }

        $this->assertSame([], $broken,
            'شاشاتٌ تسقط بمرجعٍ معلّق — وهو الحالة العادية بعد أول حذفٍ ناعم: ' . implode(' · ', $broken));
    }

    /** وتنفتح كذلك لقارئٍ محدود الصلاحية — نصفُ المراجع غائبٌ عنه بالنطاق */
    public function test_every_computed_screen_survives_a_partial_reader(): void
    {
        $this->seedCore();
        $this->seedDangling();

        // يرى الباقات والتطبيقات والمنافسين، ولا يرى الخدمات ولا المشاريع
        $role = \App\Models\Role::create(['name' => 'قارئٌ جزئي', 'scope' => 'all', 'flags' => [],
            'matrix' => ['plans' => ['v' => 1], 'apps' => ['v' => 1], 'competitors' => ['v' => 1],
                         'media' => ['v' => 1], 'feats' => ['v' => 1], 'hr' => ['v' => 1],
                         'assets' => ['v' => 1], 'compliance' => ['v' => 1], 'okrs' => ['v' => 1],
                         'policies' => ['v' => 1]]]);
        $u = \App\Models\User::create(['name' => 'جزئي', 'email' => 'partial@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $broken = [];
        foreach (self::SCREENS as $url) {
            $code = $this->actingAs($u)->get($url)->getStatusCode();
            if ($code >= 500) $broken[] = "{$url} ({$code})";
        }

        $this->assertSame([], $broken,
            'شاشةٌ تنفتح لمن يملك كل شيء وتنهار على من يملك بعضه: ' . implode(' · ', $broken));
    }
}
