<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **العينُ الخارجيّة للذكاء** (docs/ai-hub/47 §٢ العمود ج — المرحلة ٤).
 *
 *  - `attachment_texts`: نصُّ المرفق المستخرَج (PDF · DOCX · XLSX · PPTX · نصّ) — صفٌّ لكلِّ مرفق، يُعاد
 *    استخراجُه حين تتغيّر بصمتُه (`checksum`) فقط. والحالةُ صادقة: `no_reader` و`scanned` و`too_large`.
 *  - `site_snapshots`: لقطةُ موقع المشروع (`projects.url`) — الصفحاتُ ونصُّها وبصمتُها، فالفرقُ بين لقطتين خبر.
 *
 * إضافيّةٌ فقط (CLAUDE.md) — لا تمسّ جدولاً قائماً.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attachment_texts')) {
            Schema::create('attachment_texts', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('attachment_id')->unique();
                $t->string('checksum', 128)->nullable();
                $t->string('kind', 10)->nullable();                 // pdf · docx · xlsx · pptx · text
                $t->string('status', 16)->default('ok');            // ok · empty · scanned · no_reader · too_large · unsupported · failed
                $t->longText('text')->nullable();
                $t->unsignedInteger('chars')->default(0);
                $t->unsignedInteger('pages')->nullable();
                $t->string('error', 190)->nullable();
                $t->timestamp('extracted_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('site_snapshots')) {
            Schema::create('site_snapshots', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('project_id')->index();
                $t->string('url', 500);
                $t->string('status', 12)->default('ok');            // ok · failed · blocked
                $t->unsignedSmallInteger('http_status')->nullable();
                $t->json('pages')->nullable();                      // [{url, status, title, chars, ms}]
                $t->json('meta')->nullable();                       // {title, description, lang, broken: [...]}
                $t->longText('text')->nullable();
                $t->string('hash', 64)->nullable();
                $t->boolean('changed')->default(false);             // يختلف عن اللقطة السابقة
                $t->string('error', 190)->nullable();
                $t->timestamp('fetched_at')->nullable();
                $t->timestamps();

                $t->index(['project_id', 'fetched_at'], 'site_snapshots_latest_idx');
            });
        }
    }

    public function down(): void
    {
        // إضافيّةٌ فقط (CLAUDE.md) — لا هدمَ في الرجوع
    }
};
