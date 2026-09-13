<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **هجرةٌ آمنةٌ لمجموعاتِ كتابةِ المشروع** (Permissions 360 · 07.4 · النمطُ الآمن).
 *
 * تفكّكت سلطةُ `projects:e` الواحدةُ إلى ثلاثِ مجموعاتِ كتابةٍ مسمّاة (كتالوج
 * hub_permissions): `projTeam` (المدير/الأعضاء) و`projFin` (ميزانيّة/تكلفة/إيراد)
 * و`projTech` (روابطُ الإنتاج/التجريب/المستودع) — حقلُ مجموعةٍ يصير قراءةً فقط لمن
 * لا يحمل مفتاحَها (hub_field_mode). فكي **لا يفقدَ أحدٌ قدرةً قائمة**، تُمنَح
 * المفاتيحُ الثلاثةُ لكلِّ دورٍ (غيرِ المالك) يملكُ `projects:e`. لا تغييرَ على
 * أحدٍ اليوم، وسحبُ مجموعةٍ من محرّرِ الأدوار صار ممكناً دون سحبِ التعديلِ كلِّه.
 */
return new class extends Migration
{
    private array $keys = ['projTeam', 'projFin', 'projTech'];

    public function up(): void
    {
        if (! Schema::hasTable('roles')) return;

        foreach (DB::table('roles')->get(['id', 'matrix', 'is_owner']) as $role) {
            if ($role->is_owner) continue;                 // المالكُ يتجاوز أصلاً
            $mx = json_decode((string) ($role->matrix ?? '{}'), true);
            if (! is_array($mx) || empty($mx['projects']['e'])) continue;

            $changed = false;
            foreach ($this->keys as $k) {
                if (empty($mx['projects'][$k])) {
                    $mx['projects'][$k] = 1;               // حاملُ التعديلِ يبقى على سلطتِه كاملةً
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
            foreach ($this->keys as $k) {
                if (isset($mx['projects'][$k])) {
                    unset($mx['projects'][$k]);
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
