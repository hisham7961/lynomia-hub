<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CoreSeeder extends Seeder
{
    public function run(): void
    {
        $modules = array_keys(config('hub.modules'));
        $full = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]])->all();
        $view = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 0, 'e' => 0, 'd' => 0]])->all();

        $owner = Role::create([
            'name' => 'مالك النظام', 'is_owner' => true, 'scope' => 'all',
            'flags' => ['secrets' => 1, 'approve' => 1, 'users' => 1, 'audit' => 1, 'exp' => 1, 'monitor' => 1],
            'matrix' => $full,
        ]);

        Role::create([
            'name' => 'مدير', 'scope' => 'all',
            'flags' => ['approve' => 1, 'audit' => 1, 'exp' => 1, 'monitor' => 1, 'copySec' => 1],
            'matrix' => collect($full)->map(fn ($p, $m) => $m === 'vault'
                ? ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0]
                : ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0])->all(),
        ]);

        Role::create(['name' => 'عضو فريق', 'scope' => 'proj', 'flags' => [], 'matrix' => $view]);

        // (AUDIT-9) بذرُ المالكِ الأوّل بلا كلمةِ مرورٍ متوقّعةٍ في المصدر — البيانةُ من التهيئة،
        // والبذرُ متكافئٌ (idempotent): إن وُجد المالكُ لا يُعاد إنشاؤه ولا تُدهَس كلمتُه.
        $ownerEmail = (string) config('hub.bootstrap.owner_email', 'owner@lynomia.com');
        if (! User::where('email', $ownerEmail)->exists()) {
            User::create([
                'name'                => (string) config('hub.bootstrap.owner_name', 'غيث'),
                'email'               => $ownerEmail,
                'password'            => $this->bootstrapOwnerPassword(),
                'role_id'             => $owner->id,
                'status'              => 'نشط',
                'password_changed_at' => now(),
            ]);
        }

        foreach ([['1010', 'الصندوق', 'أصول'], ['1020', 'البنك', 'أصول'], ['1200', 'الذمم المدينة', 'أصول'],
                  ['1250', 'عُهَد الموظفين', 'أصول'],   // (Work OS · الطور E) حسابُ عهدةِ الموظفين — أصلٌ (ذمّةٌ على الموظف)
                  ['2100', 'الذمم الدائنة', 'خصوم'], ['2200', 'ضريبة القيمة المضافة', 'خصوم'],
                  ['3100', 'رأس المال', 'حقوق ملكية'], ['4100', 'إيرادات الخدمات', 'إيرادات'],
                  ['5100', 'تكلفة المشاريع', 'مصروفات'], ['5200', 'مصروفات تشغيلية', 'مصروفات']] as [$c, $n, $t]) {
            DB::table('ledger_accounts')->insert([
                'id' => (string) Str::uuid(), 'code' => $c, 'name' => $n, 'type' => $t,
                'version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach ([
            'auth.session_min' => 240, 'auth.max_fail' => 5, 'auth.lock_min' => 15, 'auth.pw_min' => 10,
            'files.max_kb' => 1048576,   // ١ غيغابايت — والسقفُ الفعليّ أصغرُه وسقفِ الخادم (hub_upload_cap)
            'notify.quiet' => ['on' => false, 'from' => 22, 'to' => 7],
            'finance.accounts' => ['ar' => '1200', 'ap' => '2100', 'cash' => '1010', 'bank' => '1020',
                                   'sales' => '4100', 'tax' => '2200', 'exp' => '5200', 'custody' => '1250'],
        ] as $k => $v) {
            DB::table('settings')->insert(['key' => $k, 'value' => json_encode($v, JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * كلمةُ مرورِ المالكِ الأوّل — من التهيئة لا ثابتاً في المصدر (AUDIT-9):
     *   · هُيّئت `LYNOMIA_INITIAL_ADMIN_PASSWORD` ⇒ تُستعمَل (أيّ بيئة).
     *   · لم تُهيَّأ وفي الإنتاج ⇒ يفشل البذرُ بوضوح، فلا حسابٌ مميّزٌ بكلمةٍ متوقّعة.
     *   · لم تُهيَّأ وخارجَ الإنتاج (محليّ/اختبار) ⇒ كلمةُ تطويرٍ حتميّةٌ لا يعتمد عليها أمنُ الإنتاج.
     *
     * لا تُطبَع الكلمةُ ولا تُسجَّل ولا تُعاد في مخرجٍ — تُمرَّر مباشرةً لتجزئةِ الموديل.
     */
    protected function bootstrapOwnerPassword(): string
    {
        $configured = (string) config('hub.bootstrap.owner_password', '');
        if ($configured !== '') return $configured;

        if (app()->environment('production')) {
            throw new \RuntimeException(
                'بذرُ مالكِ النظام في الإنتاج يتطلّب بيانةَ اعتمادٍ صريحة: عيّن '
                . 'LYNOMIA_INITIAL_ADMIN_PASSWORD (config hub.bootstrap.owner_password) ثم أعِد '
                . 'التشغيل. لا يُنشأ حسابٌ مميّزٌ بكلمةِ مرورٍ متوقّعة.');
        }

        // خارجَ الإنتاج فقط — بيانةُ تطويرٍ حتميّةٌ معلومةٌ للمطوّر، لا يعتمد عليها أمنُ الإنتاج
        return 'lynomia-dev-owner';
    }
}
