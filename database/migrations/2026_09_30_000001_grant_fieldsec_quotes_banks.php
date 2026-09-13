<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **هجرةٌ آمنةٌ لتوسيعِ بوّابةِ الحقولِ الحسّاسة** (Permissions 360 · 11.5 — نظيرُ
 * grant_fieldsec_to_existing_roles حرفاً على وحدتين جديدتين).
 *
 * أُضيفت `quotes.cost` (التكلفةُ الداخليّةُ التي يُشتقّ منها الهامش) و`banks.iban/balance`
 * (حسابُ البنكِ ورصيدُه) إلى config/hub_field_sec.php — فتُحجبان عمّن لا يحمل مفتاحَ
 * `fieldsec` على وحدتِهما. وكي **لا يفقدَ أحدٌ وصولاً مشروعاً**، يُمنَح المفتاحُ لكلِّ
 * دورٍ (غيرِ المالك) يملكُ رؤيةَ الوحدتين. لا تغييرَ على أحدٍ اليوم، وللمالكِ رافعةُ
 * سحبٍ لاحقة — والأدوارُ الجديدةُ لا تنالُه إلا بمنحٍ صريح.
 */
return new class extends Migration
{
    /** الوحدتان المُضافتان لكتالوج الحقولِ الحسّاسة في هذه الدفعة */
    private array $mods = ['quotes', 'banks'];

    public function up(): void
    {
        if (! Schema::hasTable('roles')) return;

        foreach (DB::table('roles')->get(['id', 'matrix', 'is_owner']) as $role) {
            if ($role->is_owner) continue;                 // المالكُ يتجاوز أصلاً
            $mx = json_decode((string) ($role->matrix ?? '{}'), true);
            if (! is_array($mx)) continue;

            $changed = false;
            foreach ($this->mods as $m) {
                if (! empty($mx[$m]['v']) && empty($mx[$m]['fieldsec'])) {
                    $mx[$m]['fieldsec'] = 1;                // من يرى الوحدةَ يبقى على حقولِها الحسّاسة
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
                if (isset($mx[$m]['fieldsec'])) {
                    unset($mx[$m]['fieldsec']);
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
