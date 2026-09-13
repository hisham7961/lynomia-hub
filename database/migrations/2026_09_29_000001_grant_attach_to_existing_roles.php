<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **هجرةٌ آمنةٌ لمفتاحِ الإرفاق** (Permissions 360 · 13.2 · النمطُ الآمن — نظيرُ fieldsec).
 *
 * صار الإرفاقُ على السجلِّ كتابةً مسمّاة (`e` أو المفتاحُ الدقيقُ `attach`) بعد أن كان
 * يكفيه العرضُ `v` بقرارٍ منتجيٍّ قديم. فكي **لا يفقدَ أحدٌ وصولاً مشروعاً**، يُمنَح
 * `attach` لكلِّ دورٍ (غيرِ المالك) يملكُ **رؤيةَ** أيِّ وحدة (بلا `e` عليها — حاملُ
 * `e` لا يحتاجه). النتيجة: لا تغييرَ على أحدٍ اليوم (الإضافةُ لا الكسر)، وللمالكِ
 * رافعةٌ لسحبِ الإرفاقِ من دورِ قراءةٍ بحتةٍ لاحقاً دون سحبِ الرؤية — والأدوارُ
 * الجديدةُ لا تنالُه إلا بمنحٍ صريحٍ من محرّرِ الأدوار.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) return;

        foreach (DB::table('roles')->get(['id', 'matrix', 'is_owner']) as $role) {
            if ($role->is_owner) continue;                 // المالكُ يتجاوز أصلاً
            $mx = json_decode((string) ($role->matrix ?? '{}'), true);
            if (! is_array($mx)) continue;

            $changed = false;
            foreach ($mx as $m => $row) {
                if (! is_array($row)) continue;
                if (! empty($row['v']) && empty($row['e']) && empty($row['attach'])) {
                    $mx[$m]['attach'] = 1;                 // من كان يرى بقي يُرفق
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
            foreach ($mx as $m => $row) {
                if (is_array($row) && isset($row['attach'])) {
                    unset($mx[$m]['attach']);
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
