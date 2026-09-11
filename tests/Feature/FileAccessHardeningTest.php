<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **تحصينُ بوابةِ الملفِّ بالمسار** (Permissions 360 · م2 · 05.1 + 13.1).
 *
 * (05.1) حقلُ ملفٍّ وُسم `hide` للدورِ لا يُخدَمُ ملفُّه بالمسارِ التفافاً على قيدِ الحقل.
 * (13.1) ملفُّ صندوقِ الوارد يُعزَل بالشركة: حاملُ صلاحيةِ الوثائقِ المقصورُ على شركةٍ لا
 *        يخدمُ وارِدَ شركةٍ أخرى بمعرفةِ المسار.
 */
class FileAccessHardeningTest extends TestCase
{
    private function userWith(string $email, array $matrix, ?array $fieldRules = null,
        array $companies = [], string $scope = 'all'): User
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => $scope, 'flags' => [],
            'matrix' => $matrix, 'field_rules' => $fieldRules]);

        return User::create(['name' => 'مستخدم', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => $companies ?: null]);
    }

    private function realFile(string $path, string $bytes = 'BYTES'): string
    {
        $abs = storage_path('app/' . $path);
        if (! is_dir(dirname($abs))) @mkdir(dirname($abs), 0777, true);
        file_put_contents($abs, $bytes);

        return $abs;
    }

    /** (05.1) حقلُ الشعارِ (img) المخفيُّ لا يُخدَمُ ملفُّه بالمسار */
    public function test_hidden_field_file_is_not_served_by_path(): void
    {
        $this->seedCore();
        $path = 'hub/test/' . Str::random(10) . '.png';
        $c = Company::create(['name_ar' => 'شركةٌ بشعار']);
        $c->forceFill(['logo_id' => $path])->save();
        $abs = $this->realFile($path);

        try {
            // يرى الشركاتِ بلا قيدِ حقل ⇒ يُخدَمُ الشعار
            $seer = $this->userWith('seer@test.local', ['companies' => ['v' => 1]]);
            $this->actingAs($seer)->get(route('file.show', $path))->assertOk();

            // يرى الشركاتِ لكنّ حقلَ الشعارِ «مخفيّ» لدورِه ⇒ لا يُخدَمُ ملفُّه (٤٠٣)
            $hidden = $this->userWith('hidden@test.local', ['companies' => ['v' => 1]],
                fieldRules: ['companies' => ['logo' => 'hide']]);
            $this->actingAs($hidden)->get(route('file.show', $path))->assertForbidden();

            // والمالكُ يرى كلَّ شيء
            $this->actingAs($this->owner)->get(route('file.show', $path))->assertOk();
        } finally {
            @unlink($abs);
        }
    }

    /** (13.1) وارِدُ شركةٍ لا يُخدَمُ لمستخدمٍ مقصورٍ على شركةٍ أخرى */
    public function test_inbox_file_respects_company_scope(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة أ']);
        $b = Company::create(['name_ar' => 'شركة ب']);
        $path = 'hub/test/' . Str::random(10) . '.pdf';
        DB::table('inbox_documents')->insert([
            'id' => (string) Str::uuid(), 'path' => $path, 'orig' => 'وارد.pdf',
            'company_id' => $b->id, 'created_at' => now(),
        ]);
        $abs = $this->realFile($path);

        try {
            // مقصورٌ على الشركةِ أ (وثائقُ الوارد) ⇒ لا يخدمُ وارِدَ الشركةِ ب
            $scopedA = $this->userWith('a@test.local', ['inboxdocs' => ['v' => 1]],
                companies: [$a->id], scope: 'company');
            $this->actingAs($scopedA)->get(route('file.show', $path))->assertForbidden();

            // نطاقٌ شاملٌ (بلا قيدِ شركة) ⇒ يخدمُه
            $all = $this->userWith('all@test.local', ['inboxdocs' => ['v' => 1]]);
            $this->actingAs($all)->get(route('file.show', $path))->assertOk();
        } finally {
            @unlink($abs);
        }
    }
}
