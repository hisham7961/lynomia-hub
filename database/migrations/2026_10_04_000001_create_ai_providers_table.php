<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **المزوّدون المتّصلون** — نسخةٌ مُهيَّأةٌ من مدخلِ كتالوج (المرحلة ٢ · W2).
 *
 * **ولا عمودَ سرٍّ فيه البتّة.** قيمُ اعتمادِ المزوّدِ تعيش في خزنةِ LiteLLM
 * مشفَّرةً بالملح، وHub يحمل **اسمَ المرجعِ وحدَه** (`credential_name`).
 * وهذا هو القرار B من دراسةِ المرحلة ٢: نسخةٌ واحدةٌ للسرِّ لا اثنتان.
 *
 * **وعامٌّ للنظامِ بلا `company_id`** — قرارُ المالك (A): بوّابةُ النماذجِ
 * مورِدٌ واحدٌ للمنصّةِ كلِّها، لا مورِدٌ لكلِّ شركة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $t) {
            $t->uuid('id')->primary();

            $t->string('catalog_key', 80);              // مفتاحٌ في config/ai_catalog.php
            $t->string('label', 191);                   // اسمُ العرض («Azure — الإنتاج»)
            $t->boolean('enabled')->default(false);

            // **مرجعٌ لا قيمة** — اسمُ الاعتمادِ عند البوّابة (فريدٌ هناك أيضاً)
            $t->string('credential_name', 191)->unique();

            // الحقولُ **غيرُ السرّيّة** وحدَها — `sends_to = config` في الكتالوج
            $t->json('config')->nullable();

            $t->string('credential_state', 40)->default('missing'); // missing|configured|verified|rejected
            $t->string('health', 40)->default('UNKNOWN');           // مفرداتُ Integrations

            $t->timestamp('last_probe_at')->nullable();
            $t->integer('last_latency_ms')->nullable();
            $t->text('last_error')->nullable();          // بعد Redactor::text()

            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable()->index();
            $t->timestamps();
            $t->softDeletes();                           // ناعمٌ هنا · والاعتمادُ يُحذَف قاطعاً عند البوّابة

            $t->index(['enabled', 'deleted_at']);
            $t->index('catalog_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
