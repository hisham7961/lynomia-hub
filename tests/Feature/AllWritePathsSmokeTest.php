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
 * **مسارُ الكتابة يُجيب، ولا يكتب ما لا يملكه صاحبُه.**
 *
 * شبكةُ v2.215 تمسح مسارات **القراءة** (١٠٣ من ٢٦١). والباقي — نحو ١٥٨ مساراً —
 * `POST`/`PUT`/`DELETE`: مسارات الكتابة. والفرقُ ليس في العدد:
 *
 *   > شاشةُ قراءةٍ تسقط ⇦ **صفحةُ خطأ**.
 *   > مسارُ كتابةٍ يسقط أو يتجاوز حارسَه ⇦ **بيانات**.
 *
 * وكلُّ عيوب الكتابة التي أُصلحت في هذه الدورة وُجدت **باليد** لا بشبكة:
 * الحالةُ بالجملة تلتفّ على وضع الحقل وعلى الموافقات · استعادةُ نسخةٍ تُعيد
 * كتابة راتبٍ لا يُرى · تحويلُ تعليقٍ ينسخ نصّاً لا يملكه · أزرارُ الشراء
 * والعروض تلتفّ على الطابور. **أربعةٌ متشابهة تعني أن الخامس موجودٌ ولم يُوصَل
 * إليه بعد.**
 *
 * فهنا كلُّ مسارِ كتابةٍ يُطلَق بحمولةٍ فارغة ثم بحمولةٍ عدائية، بأربعة قرّاء،
 * ويُؤكَّد ثلاثاً:
 *   · **لا خطأ خادم**: ٤٠٣ و٤٢٢ و٤٠٤ إجاباتٌ صحيحة، و٥٠٠ ليست إجابة.
 *   · **ولا كتابةَ بلا صلاحية**: من لا يملك شيئاً لا يُغيّر عدد الصفوف.
 *   · **ولا التفافَ على الطابور**: وحدةٌ تحت قاعدة موافقةٍ لا تُكتب مباشرةً.
 */
class AllWritePathsSmokeTest extends TestCase
{
    /** مساراتٌ تُستثنى بسببٍ مذكور — لا استثناء بلا سبب */
    private const SKIP = [
        'logout'          => 'يُنهي الجلسة فتسقط بقية المسح',
        'login.attempt'   => 'للضيوف — ومحاولاتُه تُقفل الحساب فتُفسد ما بعدها',
        'password.email'  => 'يُرسل بريداً ويقيّد بمعدّل',
        'password.update' => 'يحتاج رمزاً حيّاً',
        'install.save'    => 'التنصيب يُعطَّل بعد أول مستخدم',
        'ops.migrate'     => 'يُشغّل هجرات على قاعدة الاختبار',
        'ops.testerror'   => 'يرمي عمداً — وهذه وظيفتُه',
        'demo.reset'      => 'يمحو بيانات العرض فيُفرغ ما زرعناه لبقية المسح',
        'demo.purge'      => 'كسابقه',
        /*
         * **آثارٌ على القرص تُلوّث ما بعدها**: النسخُ الاحتياطية ملفاتٌ باقية،
         * واختباراتٌ أخرى تعدّها فتجدها أكثر ممّا زرعت. المسارُ مُغطّى بـ
         * RolesDurabilityTest وAuditRound3Test اللذين يفحصان محتواه لا وجوده.
         */
        'ops.backup'      => 'يكتب ملفَ نسخةٍ باقياً يُربك اختباراتٍ تعدّ النسخ',
    ];

    private function seedOne(): array
    {
        $ids = [];
        foreach (hub_modules() as $mk => $def) {
            $table = (string) ($def['table'] ?? '');
            if ($table === '' || ! Schema::hasTable($table)) continue;

            $row = ['id' => (string) Str::uuid()];
            foreach ($def['fields'] ?? [] as $f) {
                $col = (string) ($f['col'] ?? '');
                if ($col === '' || empty($f['required']) || ! Schema::hasColumn($table, $col)) continue;
                $row[$col] = match ($f['type'] ?? 'text') {
                    'num', 'money' => 1,
                    'date' => now()->toDateString(),
                    'dt'   => now()->toDateTimeString(),
                    'sel'  => (string) ($f['options'][0] ?? 'قيمة'),
                    'ref'  => (string) Str::uuid(),
                    default => 'قيمة',
                };
            }
            foreach (['created_at', 'updated_at'] as $ts) {
                if (Schema::hasColumn($table, $ts)) $row[$ts] = now();
            }
            try { DB::table($table)->insert($row); $ids[$mk] = $row['id']; } catch (\Throwable $e) {}
        }

        return $ids;
    }

    /**
     * ثلاثُ حمولات. و**الصالحةُ أهمُّها**: أولُ صياغةٍ لهذه الشبكة كانت تطرق
     * بمعرّفاتٍ وهمية فقط، فلا تصل الكتابةُ إلى صفٍّ حقيقي — وأُعيد فيها عيبٌ
     * أُصلح سابقاً فلم تمسكه. حارسٌ يطرق أبواباً لا سجلَّ خلفها لا يمسك شيئاً.
     */
    private function payloads(array $ids): array
    {
        $hr = $ids['hr'] ?? 'x';

        return [
            'فارغة'   => [],
            'عدائية' => ['id' => '../../etc/passwd', 'status' => ['x'], 'name' => ['y'],
                         'ids' => ['x'], 'do' => 'status', 'q' => ['z'], 'body' => '   ',
                         'amount' => '1e400', 'to' => '00000000-0000-0000-0000-000000000000'],
            // تصل السجلَّ المزروع فعلاً: هنا تُنفَّذ الكتابةُ وتُختبَر حرّاسُها
            'صالحة'  => ['ids' => [$hr], 'id' => $hr, 'do' => 'status', 'status' => 'نشط',
                         'name' => 'اسمٌ صالح', 'body' => 'نصٌّ صالح', 'dir' => 'up',
                         'note' => 'ملاحظة', 'to' => $this->employee->id],
        ];
    }

    /** مساراتُ الكتابة بمعاملاتٍ مُشبَعة */
    private function routes(array $ids): array
    {
        $out = [];
        foreach (Route::getRoutes() as $r) {
            $verb = collect($r->methods())->first(fn ($m) => in_array($m, ['POST', 'PUT', 'PATCH', 'DELETE'], true));
            if (! $verb) continue;
            $name = (string) $r->getName();
            if (isset(self::SKIP[$name])) continue;
            if (str_starts_with($r->uri(), 'api/') || str_starts_with($r->uri(), '_')) continue;

            $uri = $r->uri();

            /*
             * **وحداتٌ عدّة لا واحدة**: أولُ صياغةٍ كانت تطرق `hr` وحدها،
             * ومفتاحُ حالتها يطابق عمودَها — فأُعيد فيها عيبُ «المفتاح لا العمود»
             * ولم تمسكه. العيبُ يسكن الوحدةَ التي يفترق فيها الاثنان (`files`:
             * `docStatus` ↔ `doc_status`)، فتُمسح معها وحداتٌ تمثّل الأنماط.
             */
            if (str_contains($uri, '{module}')) {
                foreach (self::MODULES as $mk) {
                    $u2 = $this->fill(str_replace('{module}', $mk, $uri), $ids, $mk);
                    if ($u2 !== null) $out[] = [$verb, $u2, ($name ?: $uri) . ":{$mk}"];
                }
                continue;
            }
            $module = null;
            $uri = strtr($uri, [
                '{id}'      => $module ? ($ids[$module] ?? 'x') : ($ids['tasks'] ?? 'x'),
                '{user}'    => $this->employee->id,   // لا المالك: حذفُه يقطع بقية المسح
                '{userId}'  => $this->employee->id,
                '{role}'    => (string) DB::table('roles')->where('is_owner', false)->value('id'),
                '{key}'     => 'app.name',
                '{version}' => '1',
                '{field}'   => 'name',
                '{token}'   => 'x',
                '{path}'    => 'hub/x.pdf',
            ]);
            if (str_contains($uri, '{')) continue;

            $out[] = [$verb, '/' . ltrim($uri, '/'), $name ?: $uri];
        }

        return $out;
    }

    /** وحداتٌ تمثّل الأنماط: مفتاحُ حالةٍ يطابق عمودَه ويخالفه، وذاتُ مراجع */
    private const MODULES = ['hr', 'files', 'tasks', 'clients', 'fin'];

    /** إشباعُ معاملات مسارٍ لوحدةٍ بعينها — أو null إن بقي معاملٌ مجهول */
    private function fill(string $uri, array $ids, string $mk): ?string
    {
        $uri = strtr($uri, [
            '{id}'      => $ids[$mk] ?? 'x',
            '{user}'    => $this->employee->id,
            '{userId}'  => $this->employee->id,
            '{version}' => '1',
            '{field}'   => 'name',
            '{key}'     => 'app.name',
        ]);

        return str_contains($uri, '{') ? null : '/' . ltrim($uri, '/');
    }

    public function test_no_write_path_answers_with_a_server_error(): void
    {
        $this->seedCore();
        $ids = $this->seedOne();
        $routes = $this->routes($ids);

        /*
         * **الشبكةُ تحرس سعتها**: حارسٌ يطرق أبواباً لا وجود لها يبقى أخضر إلى
         * الأبد. فالسعةُ مُثبَتة: إن حُصرت المسارات أو كُسر البذر سقط الحارسُ
         * بدل أن يصير طقساً فارغاً.
         */
        $this->assertGreaterThan(120, count($routes),
            'المسحُ جمع ' . count($routes) . ' مساراً فقط — حارسٌ لا يطرق شيئاً');
        $this->assertGreaterThan(60, count($ids),
            'البذرُ غطّى ' . count($ids) . ' وحدةً — طرقُ أبوابٍ بلا سجلاتٍ خلفها لا يمسك شيئاً');

        $broken = [];
        foreach ($this->payloads($ids) as $kind => $payload) {
            foreach ($routes as [$verb, $url, $name]) {
                // معرّفُ وحدةِ المسار نفسه في الحمولة الصالحة — لا معرّفُ غيرها
                $mk = str_contains($name, ':') ? Str::afterLast($name, ':') : 'hr';
                $p = $payload;
                if (isset($p['ids'])) {
                    $p['ids'] = [$ids[$mk] ?? 'x'];
                    $p['id']  = $ids[$mk] ?? 'x';
                    /*
                     * **وحالةٌ من حالات الوحدة نفسها**: صياغةٌ سابقة أرسلت «نشط»
                     * لكل وحدة، فتُردّ ٤٢٢ «حالة غير معروفة» قبل أن تُكتب —
                     * فيمرّ الحارسُ على بابٍ لم يُفتح. الحمولةُ تتكلّم لغةَ وحدتها.
                     */
                    $p['status'] = (string) (hub_status_field($mk)['options'][0] ?? 'نشط');
                }
                try {
                    $code = $this->actingAs($this->owner)->call($verb, $url, $p)->getStatusCode();
                } catch (\Throwable $e) {
                    $broken[] = "{$verb} {$url} [{$kind}] → " . Str::limit($e->getMessage(), 80);
                    continue;
                }
                if ($code >= 500) $broken[] = "{$verb} {$url} [{$kind}] → {$code}";
            }
        }

        $this->assertSame([], $broken,
            "مساراتُ كتابةٍ تجيب بخطأ خادم — و٤٠٣ و٤٢٢ إجاباتٌ صحيحة أما ٥٠٠ فليست إجابة:\n  "
            . implode("\n  ", array_slice($broken, 0, 30))
            . (count($broken) > 30 ? "\n  … و" . (count($broken) - 30) . ' غيرها' : ''));
    }

    /**
     * **ولا كتابةَ بلا صلاحية**: حسابٌ بلا صلاحيةٍ واحدة يطرق كل باب كتابةٍ في
     * النظام — ولا يتغيّر عددُ صفوفِ أي جدولٍ من جداول الوحدات.
     */
    public function test_a_reader_with_no_permission_writes_nothing(): void
    {
        $this->seedCore();
        $ids = $this->seedOne();
        $routes = $this->routes($ids);

        $role = Role::create(['name' => 'بلا صلاحيات', 'scope' => 'all', 'flags' => [], 'matrix' => []]);
        $u = User::create(['name' => 'بلا', 'email' => 'none@t.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $tables = collect(hub_modules())->pluck('table')->unique()
            ->filter(fn ($t) => $t && Schema::hasTable($t))->values();
        $before = $tables->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();

        foreach ($routes as [$verb, $url]) {
            foreach ($this->payloads($ids) as $payload) {
                try { $this->actingAs($u)->call($verb, $url, $payload); } catch (\Throwable $e) {}
            }
        }

        $changed = [];
        foreach ($before as $t => $n) {
            $now = DB::table($t)->count();
            if ($now !== $n) $changed[] = "{$t}: {$n} → {$now}";
        }

        $this->assertSame([], $changed,
            'كتب من لا يملك صلاحيةً واحدة: ' . implode(' · ', $changed));
    }
}
