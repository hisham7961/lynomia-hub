<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **هجرةٌ آمنةٌ لبوّابةِ أسطولِ النقاط الطرفيّة** (Permissions 360 · 12.1 · النمطُ الآمن).
 *
 * صار بابُ الوحدةِ العامّ `/m/endpoints` يشترط ما يشترطه مركزُ النقاطِ نفسُه
 * (مالك أو `secOps`/`monitor`) — كان `endpoints:v` في المصفوفةِ يكفي وحدَه فيلتفُّ
 * على بوّابةِ المركز. فكي **لا يفقدَ دورٌ قائمٌ وصولاً مشروعاً**، تُمنَح رايةُ
 * `secOps` لكلِّ دورٍ (غيرِ المالك) يملكُ `endpoints:v` وليست له رايةُ مراقبةٍ
 * تغطّيه. لا تغييرَ على أحدٍ اليوم، وسحبُ الرايةِ لاحقاً من محرّرِ الأدوار يسحبُ
 * البابين (الوحدةَ والمركزَ) معاً — بوّابةٌ واحدةٌ لا بوّابتان.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) return;

        foreach (DB::table('roles')->get(['id', 'matrix', 'flags', 'is_owner']) as $role) {
            if ($role->is_owner) continue;                 // المالكُ يتجاوز أصلاً
            $mx = json_decode((string) ($role->matrix ?? '{}'), true);
            if (! is_array($mx) || empty($mx['endpoints']['v'])) continue;

            $fl = json_decode((string) ($role->flags ?? '{}'), true);
            if (! is_array($fl)) $fl = [];
            if (! empty($fl['monitor']) || ! empty($fl['secOps'])) continue;   // مغطًّى أصلاً

            $fl['secOps'] = 1;                             // من كان يرى الأسطولَ بقي يراه
            DB::table('roles')->where('id', $role->id)
                ->update(['flags' => json_encode($fl, JSON_UNESCAPED_UNICODE)]);
        }
    }

    /** لا تراجعَ آليّاً: الرايةُ الممنوحةُ هنا لا تُميَّز عن ممنوحةٍ يدويّاً — تُدار من محرّرِ الأدوار */
    public function down(): void
    {
    }
};
