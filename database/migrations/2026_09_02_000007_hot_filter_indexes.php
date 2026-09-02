<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فهارسُ أعمدةٍ تُرشَّح بها القوائمُ كل يوم بلا فهرس (تدقيق سلامة البيانات v2.399):
 * إشعاراتُ السجل (module, record_id)، وحالاتُ أوامر التغيير وطلبات التوقيع، وشركةُ الوارد.
 * إضافيةٌ ومحروسة: تُتخطّى إن وُجد الفهرسُ أو غاب العمود.
 */
return new class extends Migration
{
    public function up(): void
    {
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
        $add('notifications_hub', ['module', 'record_id'], 'notifications_hub_module_record_idx');
        $add('change_orders', ['status'], 'change_orders_status_idx');
        $add('sign_requests', ['status'], 'sign_requests_status_idx');
        $add('inbox_documents', ['company_id'], 'inbox_documents_company_idx');
    }

    public function down(): void
    {
        // إضافيةٌ فقط
    }
};
