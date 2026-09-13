<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **هجرةٌ آمنةٌ لبوّابةِ الحقولِ الحسّاسة** (Permissions 360 · 09.2/07.2 · النمطُ الآمن).
 *
 * أُضيف مفتاحٌ دقيقٌ `fieldsec` يحرسُ الحقولَ المصنَّفةَ حسّاسةً (config/hub_field_sec:
 * راتب/رقم مدنيّ/IBAN/إقامة/جواز في الموارد، وميزانيّة/تكلفة في المشاريع). قبلها كانت
 * مرئيّةً لكلِّ من يرى الوحدة؛ فكي **لا يفقدَ أحدٌ وصولاً مشروعاً**، يُمنَح `fieldsec`
 * لكلِّ دورٍ (غيرِ المالك) يملكُ **رؤيةَ** وحدةٍ ذاتِ حقولٍ حسّاسة. النتيجة: لا تغييرَ
 * على أحدٍ اليوم (الإضافةُ لا الكسر)، وللمالكِ رافعةٌ لسحبِ التصريحِ من دورٍ لاحقاً
 * دون سحبِ رؤيةِ الوحدة — والأدوارُ الجديدةُ لا تنالُه إلا بمنحٍ صريح.
 * (قواعدُ الحقول field_rules تبقى فوقَه: دورٌ يُخفي الراتبَ صراحةً يبقى مُخفياً.)
 */
return new class extends Migration
{
    /** الوحداتُ ذاتُ حقولٍ حسّاسة (مفاتيحُ config/hub_field_sec.php) */
    private array $mods = ['hr', 'projects'];

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
