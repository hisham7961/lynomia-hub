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

        User::create([
            'name' => 'غيث', 'email' => 'owner@lynomia.com', 'password' => 'ChangeMe!2026',
            'role_id' => $owner->id, 'status' => 'نشط', 'password_changed_at' => now(),
        ]);

        foreach ([['1010', 'الصندوق', 'أصول'], ['1020', 'البنك', 'أصول'], ['1200', 'الذمم المدينة', 'أصول'],
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
                                   'sales' => '4100', 'tax' => '2200', 'exp' => '5200'],
        ] as $k => $v) {
            DB::table('settings')->insert(['key' => $k, 'value' => json_encode($v, JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
