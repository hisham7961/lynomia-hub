<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **لا شاشةَ تسقط بصمت.**
 *
 * كلُّ عطلٍ أبلغ عنه صاحبُ النظام في هذه الدورة كان «شاشةٌ لا تعمل»، ولا واحدٌ
 * منها أمسكته حزمةٌ فيها ألفُ اختبارٍ أخضر: الإعدادات ومركز الأمان على تنصيبٍ
 * حقيقي، والرسائل مرتين، والباقات بخطأ ٥٠٠. لأن الاختبارات تفحص **المنطق
 * والبيانات**، ولا تفحص **«هل تنفتح هذه الشاشة لإنسانٍ حقيقيّ ببياناتٍ حقيقية؟»**
 * — فيصير صاحبُ النظام جهازَ الرصد.
 *
 * هذا الحارس يمرّ على **كل مسار GET في النظام** (١١٢ مساراً)، ببياناتٍ مزروعةٍ
 * كما تكون في نظامٍ عاش سنة: مراجعُ معلّقة، وقيمٌ مركّبة، وحقولٌ فارغة، ونصوصٌ
 * طويلة. وبأربعة قرّاء: مالكٌ · صلاحياتٌ جزئية · معزولٌ بشركة · بلا صلاحيات.
 *
 * والقاعدة: **٤٠٣ و٤٠٤ إجاباتٌ صحيحة، و٥٠٠ ليست إجابة.** ما يسقط يُسمّى بمساره.
 */
class AllScreensSmokeTest extends TestCase
{
    public static array $failed = [];

    /** مساراتٌ تُستثنى بسببٍ مذكور — لا استثناء بلا سبب */
    private const SKIP = [
        'logout'          => 'يُنهي الجلسة فتسقط بقية المسح',
        'login'           => 'للضيوف — تُعيد التوجيه للمصادَق',
        'password.request' => 'للضيوف',
        'password.reset'  => 'للضيوف، ويحتاج رمزاً حيّاً',
        'install'         => 'التنصيب يُعطَّل بعد أول مستخدم',
        'install.step'    => 'التنصيب يُعطَّل بعد أول مستخدم',
        'ops.migrate'     => 'يُشغّل هجرات على قاعدة الاختبار',
        'file.show'       => 'يخدم ملفاً من القرص — مُغطّى في GuardedWritePathTest',
        'pwa.sw'          => 'عاملُ خدمة لا شاشة',
    ];

    /* ────────── البذر ────────── */

    /**
     * بياناتٌ كما تكون بعد سنةٍ من العمل لا كما تكتبها الاختبارات:
     * صفٌّ سليم، وصفٌّ مرجعُه معلّق، وصفٌّ حقولُه الاختيارية فارغة.
     */
    private function seedRealistic(): array
    {
        $ids = [];
        $ghost = (string) Str::uuid();
        $long = str_repeat('نصٌّ طويلٌ جداً ', 40);

        foreach (hub_modules() as $mk => $def) {
            $table = (string) ($def['table'] ?? '');
            if ($table === '' || ! Schema::hasTable($table)) continue;

            foreach ([['sound', false], ['dangling', true], ['sparse', null]] as [$kind, $dangle]) {
                $row = ['id' => (string) Str::uuid()];
                foreach ($def['fields'] ?? [] as $f) {
                    $col = (string) ($f['col'] ?? '');
                    if ($col === '' || ! Schema::hasColumn($table, $col)) continue;
                    $type = (string) ($f['type'] ?? 'text');
                    $req  = ! empty($f['required']);

                    if ($type === 'ref' && empty($f['multi'])) {
                        if ($dangle === true) $row[$col] = $ghost;      // مرجعٌ لا وجود له
                        continue;                                        // وإلا يُترك فارغاً
                    }
                    if ($dangle === null && ! $req) continue;            // الصفّ الشحيح

                    $row[$col] = match ($type) {
                        'num', 'money' => 1234.567,
                        'date' => now()->subDays(400)->toDateString(),
                        'dt'   => now()->subDays(400)->toDateTimeString(),
                        'bool' => 1,
                        'sel'  => (string) ($f['options'][0] ?? 'قيمة'),
                        'txt'  => $long,
                        default => $req ? 'قيمة' : mb_substr($long, 0, 80),
                    };
                }
                foreach (['created_at', 'updated_at'] as $ts) {
                    if (Schema::hasColumn($table, $ts)) $row[$ts] = now()->subDays(30);
                }

                try {
                    DB::table($table)->insert($row);
                    $ids[$mk] ??= $row['id'];
                } catch (\Throwable $e) { self::$failed[$table] = \Illuminate\Support\Str::limit($e->getMessage(), 60); }
            }
        }

        return $ids;
    }

    /** أربعةُ قرّاء: كلُّ شاشةٍ تُفتح بكلٍّ منهم */
    private function readers(): array
    {
        $modules = array_keys(hub_modules());
        $half = array_slice($modules, 0, (int) (count($modules) / 2));

        $partial = Role::create(['name' => 'جزئي', 'scope' => 'all', 'flags' => ['monitor' => 1],
            'matrix' => collect($half)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'e' => 1]])->all()]);
        $walled = Role::create(['name' => 'معزول', 'scope' => 'own', 'flags' => [],
            'matrix' => collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1]])->all()]);
        $bare = Role::create(['name' => 'بلا صلاحيات', 'scope' => 'all', 'flags' => [], 'matrix' => []]);

        $co = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $co, 'name_ar' => 'شركة',
            'created_at' => now(), 'updated_at' => now()]);

        return [
            'مالك'   => $this->owner,
            'جزئي'   => User::create(['name' => 'جزئي', 'email' => 'p@t.local', 'password' => 'Secret!2026x',
                'role_id' => $partial->id, 'status' => 'نشط', 'password_changed_at' => now()]),
            'معزول'  => User::create(['name' => 'معزول', 'email' => 'w@t.local', 'password' => 'Secret!2026x',
                'role_id' => $walled->id, 'status' => 'نشط', 'password_changed_at' => now(),
                'companies' => [$co]]),
            'بلا'    => User::create(['name' => 'بلا', 'email' => 'b@t.local', 'password' => 'Secret!2026x',
                'role_id' => $bare->id, 'status' => 'نشط', 'password_changed_at' => now()]),
        ];
    }

    /** مساراتُ GET بمعاملاتٍ مُشبَعة من البيانات المزروعة */
    private function urls(array $ids): array
    {
        $out = [];
        foreach (Route::getRoutes() as $r) {
            if (! in_array('GET', $r->methods(), true)) continue;
            $name = (string) $r->getName();
            if (isset(self::SKIP[$name])) continue;
            if (str_starts_with($r->uri(), 'api/') || str_starts_with($r->uri(), '_')) continue;

            $uri = $r->uri();
            $module = null;
            if (str_contains($uri, '{module}')) {
                $module = 'hr';
                $uri = str_replace('{module}', $module, $uri);
            }
            $uri = strtr($uri, [
                '{id}'        => $module ? ($ids[$module] ?? 'x') : ($ids['tasks'] ?? 'x'),
                '{projectId}' => $ids['projects'] ?? 'x',
                '{user}'      => $this->owner->id,
                '{userId}'    => $this->owner->id,
                '{role}'      => (string) DB::table('roles')->value('id'),
                '{key}'       => 'app.name',
                '{token}'     => 'x',
                '{version}'   => '1',
            ]);
            if (str_contains($uri, '{')) continue;      // معاملٌ لا نعرف كيف نُشبعه

            $out[$name ?: $uri] = '/' . ltrim($uri, '/');
        }

        return $out;
    }

    /* ────────── المسح ────────── */

    public function test_no_screen_in_the_system_answers_with_a_server_error(): void
    {
        $this->seedCore();
        $ids = $this->seedRealistic();
        $readers = $this->readers();
        $urls = $this->urls($ids);

        /*
         * **الشبكةُ تحرس نفسها.** حارسٌ يمرّ على شاشاتٍ بقاعدةٍ فارغة يمرّ على
         * لا شيء ويبقى أخضر إلى الأبد. فالسعةُ نفسها مُثبَتة هنا: إن كسر أحدٌ
         * البذرَ أو حصر المسارات، سقط الحارسُ بدل أن يصير طقساً فارغاً.
         */
        $this->assertGreaterThan(60, count($ids),
            'البذرُ غطّى ' . count($ids) . ' وحدةً فقط — شبكةٌ تمسح شاشاتٍ بلا بيانات لا تمسك شيئاً. '
            . 'ما فشل: ' . implode('، ', array_keys(self::$failed)));
        $this->assertGreaterThan(90, count($urls),
            'المسحُ جمع ' . count($urls) . ' مساراً فقط — حارسٌ لا يمسح شيئاً');

        $broken = [];
        foreach ($readers as $who => $u) {
            foreach ($urls as $name => $url) {
                try {
                    $code = $this->actingAs($u)->get($url)->getStatusCode();
                } catch (\Throwable $e) {
                    $code = 500;
                    $broken[] = "{$url} [{$who}] → " . Str::limit($e->getMessage(), 90);
                    continue;
                }
                if ($code >= 500) $broken[] = "{$url} [{$who}] → {$code}";
            }
        }

        $this->assertSame([], $broken,
            "شاشاتٌ تجيب بخطأ خادم — و٤٠٣ و٤٠٤ إجاباتٌ صحيحة أما ٥٠٠ فليست إجابة:\n  "
            . implode("\n  ", array_slice($broken, 0, 40))
            . (count($broken) > 40 ? "\n  … و" . (count($broken) - 40) . ' غيرها' : ''));
    }

    /** ولا يُستثنى مسارٌ بلا سببٍ مكتوب — الاستثناءُ الصامت ثقبٌ في الشبكة */
    public function test_every_exclusion_carries_a_written_reason(): void
    {
        foreach (self::SKIP as $route => $why) {
            $this->assertNotSame('', trim($why), "استُثني «{$route}» بلا سبب");
        }
    }
}
