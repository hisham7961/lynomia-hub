<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **متّجهاتُ العقل الثاني** (المرحلة ٤ · `docs/ai-hub/46-ai-roadmap.md` §٦ · خيارُ «أ»).
 *
 * صفٌّ لكلِّ مقطعٍ من حقلٍ نصّيٍّ في سجلّ: `(module, record_id, field, chunk)`. **لا نصَّ هنا** — المتّجهُ
 * وبصمةُ النصّ فقط؛ والنصُّ يُقرأ عند العرض من السجلّ نفسِه **بعين القارئ**. و`field` ليُحجب المقطعُ عمّن
 * حُجب عنه حقلُه، و`company_id` تضييقٌ رخيصٌ قبل الحساب (ليس الحكمَ — الحكمُ `hub_scope` وقتَ الاستعلام).
 * `vector` ثنائيّ (float32 مرصوص) — يعمل على MariaDB 10.11 وSQLite بلا نوع متّجهات؛ والانتقالُ إلى
 * `VECTOR` في 11.8 سائقٌ آخرُ خلف `VectorStore` بلا تغييرٍ في الميزة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_embeddings')) return;

        Schema::create('ai_embeddings', function (Blueprint $t) {
            $t->id();
            $t->string('module', 40);
            $t->uuid('record_id');
            $t->string('field', 60);
            $t->unsignedSmallInteger('chunk')->default(0);
            $t->uuid('company_id')->nullable();
            $t->char('hash', 40);                        // sha1(النموذج|النصّ) — لا يُعاد تضمينُ ما لم يتغيّر
            $t->string('model', 191);
            $t->unsignedSmallInteger('dim');
            $t->binary('vector');
            $t->timestamps();
            $t->unique(['module', 'record_id', 'field', 'chunk']);
            $t->index(['module', 'company_id']);
        });
    }

    public function down(): void
    {
        // إضافيّةٌ فقط (CLAUDE.md)
    }
};
