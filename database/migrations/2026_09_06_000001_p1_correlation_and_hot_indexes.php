<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (WP-1.4) الترابطُ والفهارسُ الساخنة — الطور ١ من مركز التحكّم.
 *
 * أعمدةُ `request_id` كانت على التدقيق والصادر والويبهوك والإشعارات وحدها؛
 * فالمنعُ (access_denials) والحدثُ الوارد (inbound_hook_events) والحادثةُ
 * (incidents) كانت طبقاتٍ بلا خيطٍ يربطها بطلبها — وصفحةُ `system.trace`
 * تجمع الأثرَ كلَّه بالمعرّف الواحد. وعمودُ `tasks.completed_at` يُقدَّم هنا
 * (لا في الطور ٨) لأن «نسبةَ الالتزام» تُحسب اليومَ من `updated_at` فأيُّ
 * تعديلٍ لاحقٍ على مهمةٍ منجزة يُفسد تاريخَ إنجازها.
 *
 * كتلُ الأعمدة **حرفيّةٌ لا حلقة**: `hub_col_widths()` يقرأ مصدرَ الهجرات
 * ويطابق `Schema::table('<اسم>'` — عمودٌ داخل foreach غيرُ مرئيٍّ لحرّاس العرض.
 * إضافيةٌ ومحروسة: تُتخطّى إن وُجد العمود/الفهرس أو غاب الجدول.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── أعمدةُ الترابط (كتلٌ حرفيّة) ──
        if (Schema::hasTable('access_denials') && ! Schema::hasColumn('access_denials', 'request_id')) {
            Schema::table('access_denials', function (Blueprint $t) {
                $t->string('request_id', 40)->nullable()->index('access_denials_request_id_idx');
            });
        }
        if (Schema::hasTable('inbound_hook_events') && ! Schema::hasColumn('inbound_hook_events', 'request_id')) {
            Schema::table('inbound_hook_events', function (Blueprint $t) {
                $t->string('request_id', 40)->nullable()->index('inbound_hook_events_request_id_idx');
            });
        }
        if (Schema::hasTable('incidents') && ! Schema::hasColumn('incidents', 'request_id')) {
            Schema::table('incidents', function (Blueprint $t) {
                $t->string('request_id', 40)->nullable()->index('incidents_request_id_idx');
            });
        }
        // ختمُ إنجاز المهمة — يكتبه ModuleController عند دخول حالة الإنجاز ومغادرتها
        if (Schema::hasTable('tasks') && ! Schema::hasColumn('tasks', 'completed_at')) {
            Schema::table('tasks', function (Blueprint $t) {
                $t->timestamp('completed_at')->nullable()->index('tasks_completed_at_idx');
            });
        }

        // ── الفهارسُ الساخنة (§14.1 + متفرّقات الاكتشاف) — نمطُ 2026_09_02_000007 ──
        $add = function (string $table, array $cols, string $name) {
            if (! Schema::hasTable($table)) return;
            foreach ($cols as $c) if (! Schema::hasColumn($table, $c)) return;
            try {
                if (Schema::hasIndex($table, $name)) return;
            } catch (\Throwable $e) {
            }
            try {
                Schema::table($table, fn (Blueprint $t) => $t->index($cols, $name));
            } catch (\Throwable $e) {
                // فهرسٌ قائمٌ باسمٍ آخر — لا نكسر الترحيل
            }
        };
        $add('error_events', ['request_id'], 'error_events_request_id_idx');          // العمودُ قائمٌ بلا فهرس
        $add('audits', ['user_id', 'created_at'], 'audits_user_created_idx');         // «ماذا فعل فلان مؤخراً؟»
        $add('audits', ['action', 'created_at'], 'audits_action_created_idx');        // «كل عمليات الحذف هذا الأسبوع»
        $add('access_denials', ['ip', 'created_at'], 'access_denials_ip_created_idx'); // تجميعُ العناوين الطارقة
        $add('user_ips', ['ip'], 'user_ips_ip_idx');                                  // «من دخل من هذا العنوان؟» — الفريدُ يقود user_id أولاً
        $add('metric_points', ['at'], 'metric_points_at_idx');                        // تقليمُ الاحتفاظ يرشّح بـat وحده والفهارسُ القائمة تبدأ بغيره
        $add('tasks', ['due'], 'tasks_due_idx');                                      // قوائمُ الاستحقاق والالتزام
    }

    public function down(): void
    {
        // إضافيةٌ فقط
    }
};
