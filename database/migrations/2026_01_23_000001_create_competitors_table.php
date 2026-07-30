<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** المنافسون — ملف رصد لكل منافس: منتجاته وتسعيره وقوته وضعفه ومقارنته بنا ومستوى تهديده */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('competitors', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 300);                            // اسم المنافس التجاري
            $t->string('website', 600)->nullable();             // الموقع الرسمي
            $t->string('market', 200)->nullable()->index();     // الدولة أو السوق الذي ينشط فيه
            $t->text('products')->nullable();                   // منتجاته وخدماته الأساسية
            $t->text('pricing')->nullable();                    // نموذج التسعير (اشتراك/مرة واحدة/باقات)
            $t->text('highlights')->nullable();                 // أبرز المميزات التي يسوّق بها
            $t->text('strengths')->nullable();                  // نقاط القوة لديه
            $t->text('weaknesses')->nullable();                 // نقاط الضعف التي نستفيد منها
            $t->text('comparison')->nullable();                 // مقارنة مباشرة بمنتجاتنا
            $t->uuid('service_id')->nullable()->index();        // الخدمة لدينا التي ينافسها
            $t->string('threat', 40)->nullable()->index();      // مستوى التهديد: مرتفع/متوسط/منخفض
            $t->date('last_seen')->nullable()->index();         // آخر رصد أو تحديث لمعلوماته
            $t->text('sources')->nullable();                    // مصادر المتابعة (روابط، تقارير، متابعة دورية)
            $t->string('social', 400)->nullable();              // حساباتهم على منصات التواصل
            $t->string('status', 60)->nullable()->index();
            $t->text('notes')->nullable();
            $t->json('tags')->nullable();
            $t->json('custom')->nullable();
            $t->json('meta')->nullable();
            $t->integer('version')->default(1);
            $t->boolean('archived')->default(false)->index();
            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        DB::statement('CREATE INDEX competitors_threat_status_idx ON competitors (threat, status)');
        DB::statement('CREATE INDEX competitors_service_threat_idx ON competitors (service_id, threat)');
        DB::statement('CREATE INDEX competitors_status_seen_idx ON competitors (status, last_seen)');
    }

    public function down(): void
    {
        Schema::dropIfExists('competitors');
    }
};
