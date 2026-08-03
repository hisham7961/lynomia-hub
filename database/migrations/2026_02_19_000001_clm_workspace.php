<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CLM المرحلة ٧ (v2.123) — مساحة عمل العقد:
 *  - جدول التزامات العقود contract_obligations (وحدة سجلٍّ كاملة — بقيدها في
 *    السجل تشتري القوائم والتقويم ورادار الانتهاء وقواعد التنبيه وAPI مجاناً)
 *  - ترحيل نص «الالتزامات الرئيسية» القديم إلى صفوفٍ متتبعة (العمود يبقى)
 *  - عمودا حلّ التعليقات (resolved_at/resolved_by) — الفجوة الوحيدة في نظامها
 * كله إضافي وidempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contract_obligations')) {
            Schema::create('contract_obligations', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('title', 300);
                $t->uuid('contract_id')->nullable()->index();
                $t->date('due')->nullable()->index();            // الاستحقاق — يغذي التقويم والرادار
                $t->decimal('amount', 14, 2)->nullable();
                $t->string('currency', 20)->nullable();
                $t->uuid('company_id')->nullable()->index();
                $t->uuid('project_id')->nullable()->index();
                $t->uuid('owner_id')->nullable()->index();
                $t->string('status', 60)->nullable()->index();   // قائم / مكتمل / متأخر / ملغي
                $t->text('notes')->nullable();
                $t->json('tags')->nullable();
                $t->json('custom')->nullable();
                $t->json('meta')->nullable();
                $t->integer('version')->default(1);
                $t->uuid('created_by')->nullable()->index();
                $t->uuid('updated_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['status', 'due']);
            });
        }

        // نص «الالتزامات الرئيسية» القديم → صفوف متتبعة (سطرٌ = التزام، بحد ٣٠)
        // idempotent: عقدٌ له صفوف التزامات مسبقاً لا يُرحَّل ثانية
        DB::table('contracts')->whereNotNull('obligations')->where('obligations', '!=', '')
            ->orderBy('id')->chunkById(100, function ($rows) {
                foreach ($rows as $c) {
                    if (DB::table('contract_obligations')->where('contract_id', $c->id)->exists()) continue;
                    $lines = array_slice(array_values(array_filter(array_map('trim',
                        preg_split('/[\r\n;؛]+/u', (string) $c->obligations)))), 0, 30);
                    foreach ($lines as $line) {
                        DB::table('contract_obligations')->insert([
                            'id' => (string) \Illuminate\Support\Str::uuid(),
                            'title' => mb_substr($line, 0, 300),
                            'contract_id' => $c->id,
                            'company_id' => $c->company_id, 'project_id' => $c->project_id,
                            'owner_id' => $c->owner_id, 'status' => 'قائم',
                            'created_by' => $c->created_by ?? null,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            });

        // حلّ التعليقات
        Schema::table('comments', function (Blueprint $t) {
            if (! Schema::hasColumn('comments', 'resolved_at')) $t->timestamp('resolved_at')->nullable();
            if (! Schema::hasColumn('comments', 'resolved_by')) $t->uuid('resolved_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_obligations');
        Schema::table('comments', function (Blueprint $t) {
            if (Schema::hasColumn('comments', 'resolved_at')) $t->dropColumn(['resolved_at', 'resolved_by']);
        });
    }
};
