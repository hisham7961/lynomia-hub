<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **هجرةٌ آمنةٌ لبوّابةِ الوثائقِ الحسّاسة** (Permissions 360 · وثائق · م5c).
 *
 * أُضيفت صلاحيةٌ دقيقةٌ `docsec` تحرسُ الأنواعَ الحسّاسةَ (هوية/جواز/عقد/صحّة/بنك/سرّية).
 * قبلها كانت هذه الوثائقُ تُتاح لكلِّ من يرى الوحدة؛ فكي **لا يفقدَ أحدٌ وصولاً مشروعاً**،
 * يُمنَح `docsec` لكلِّ دورٍ (غيرِ المالك) يملكُ **رؤيةَ** وحدةٍ ذاتِ أنواعٍ حسّاسة. النتيجة:
 * لا تغييرَ على أحدٍ اليومَ (الإضافةُ لا الكسر)، ويصيرُ للمالكِ رافعةٌ لسحبِ التصريحِ
 * من دورٍ لاحقاً دون سحبِ رؤيةِ الوحدةِ كلِّها — والأدوارُ الجديدةُ لا تنالُه إلا بمنحٍ صريح.
 */
return new class extends Migration
{
    /** الوحداتُ ذاتُ أنواعٍ حسّاسة (config/hub_docs · مفتاح sec) */
    private array $mods = ['hr', 'companies', 'suppliers', 'clients'];

    public function up(): void
    {
        if (! Schema::hasTable('roles')) return;

        foreach (DB::table('roles')->get(['id', 'matrix', 'is_owner']) as $role) {
            if ($role->is_owner) continue;                 // المالكُ يتجاوز أصلاً
            $mx = json_decode((string) ($role->matrix ?? '{}'), true);
            if (! is_array($mx)) continue;

            $changed = false;
            foreach ($this->mods as $m) {
                if (! empty($mx[$m]['v']) && empty($mx[$m]['docsec'])) {
                    $mx[$m]['docsec'] = 1;                  // من يرى الوحدةَ يبقى على وثائقِها الحسّاسة
                    $changed = true;
                }
            }
            if ($changed) {
                DB::table('roles')->where('id', $role->id)
                    ->update(['matrix' => json_encode($mx, JSON_UNESCAPED_UNICODE)]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) return;

        foreach (DB::table('roles')->get(['id', 'matrix']) as $role) {
            $mx = json_decode((string) ($role->matrix ?? '{}'), true);
            if (! is_array($mx)) continue;

            $changed = false;
            foreach ($this->mods as $m) {
                if (isset($mx[$m]['docsec'])) {
                    unset($mx[$m]['docsec']);
                    $changed = true;
                }
            }
            if ($changed) {
                DB::table('roles')->where('id', $role->id)
                    ->update(['matrix' => json_encode($mx, JSON_UNESCAPED_UNICODE)]);
            }
        }
    }
};
