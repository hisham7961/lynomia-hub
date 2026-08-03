<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CLM المرحلة ٢ (v2.118) — أساس بيانات دورة حياة العقود. إضافيةٌ بالكامل:
 *  - contracts: ترقيم رسمي doc_no + سلسلة الملاحق/التجديد (parent_id, kind)
 *  - sign_requests: أعمدة النطاق (company/project) وحالة الإرسال والإلغاء وسلة
 *  - contract_signers: موقّعون متعددون برموزهم — الطلب القائم يُرحَّل موقّعاً
 *    واحداً برمز الطلب نفسه فتبقى الروابط القديمة صالحة
 *  - contract_events: سجل أدلة قانوني append-only (يغذي الخط الزمني والشهادة)
 *  - sign_templates: إصداراتٌ ولغة واتجاه وأرشفة + جدول لقطات الإصدارات
 * الترحيلات مجزأة chunkById فلا تخنق قاعدة كبيرة.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── العقود: الترقيم وسلسلة الملاحق ──
        Schema::table('contracts', function (Blueprint $t) {
            if (! Schema::hasColumn('contracts', 'doc_no')) $t->string('doc_no', 60)->nullable()->unique();
            if (! Schema::hasColumn('contracts', 'parent_id')) $t->uuid('parent_id')->nullable()->index();
            if (! Schema::hasColumn('contracts', 'kind')) $t->string('kind', 40)->nullable()->index();
        });

        // ترقيم قائم: CTR-{YEAR}-{SEQ} بتسلسل سنوي بترتيب الإنشاء
        $seq = [];
        DB::table('contracts')->whereNull('doc_no')->orderBy('created_at')->orderBy('id')
            ->chunkById(200, function ($rows) use (&$seq) {
                foreach ($rows as $c) {
                    $year = substr((string) $c->created_at, 0, 4) ?: date('Y');
                    $seq[$year] = ($seq[$year] ?? 0) + 1;
                    DB::table('contracts')->where('id', $c->id)->update([
                        'doc_no' => sprintf('CTR-%s-%04d', $year, $seq[$year]),
                        'kind' => $c->kind ?? 'أصلي',
                    ]);
                }
            });

        // ── طلبات التوقيع: النطاق والدورة ──
        Schema::table('sign_requests', function (Blueprint $t) {
            if (! Schema::hasColumn('sign_requests', 'company_id')) $t->uuid('company_id')->nullable()->index();
            if (! Schema::hasColumn('sign_requests', 'project_id')) $t->uuid('project_id')->nullable()->index();
            if (! Schema::hasColumn('sign_requests', 'sent_at')) $t->timestamp('sent_at')->nullable();
            if (! Schema::hasColumn('sign_requests', 'cancelled_at')) $t->timestamp('cancelled_at')->nullable();
            if (! Schema::hasColumn('sign_requests', 'mode')) $t->string('mode', 20)->default('مفرد');
            if (! Schema::hasColumn('sign_requests', 'deleted_at')) $t->softDeletes();
        });

        // نطاق الطلب القائم يُشتق من عقده المربوط
        DB::table('sign_requests')->whereNull('company_id')->whereNotNull('contract_id')
            ->orderBy('id')->chunkById(200, function ($rows) {
                foreach ($rows as $r) {
                    $c = DB::table('contracts')->where('id', $r->contract_id)
                        ->first(['company_id', 'project_id']);
                    if ($c && ($c->company_id || $c->project_id)) {
                        DB::table('sign_requests')->where('id', $r->id)
                            ->update(['company_id' => $c->company_id, 'project_id' => $c->project_id]);
                    }
                }
            });

        // ── الموقّعون المتعددون ──
        if (! Schema::hasTable('contract_signers')) {
            Schema::create('contract_signers', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('request_id')->index();
                $t->unsignedInteger('order')->default(1);
                $t->string('role', 40)->default('موقّع');          // موقّع / شاهد / مستلم نسخة
                $t->string('name', 160);
                $t->string('email', 190)->nullable();
                $t->string('phone', 40)->nullable();
                $t->string('company', 160)->nullable();
                $t->string('title', 120)->nullable();
                $t->string('token', 64)->unique();
                $t->string('otp_code', 80)->nullable();             // مجزأ لا خام
                $t->timestamp('otp_expires_at')->nullable();
                $t->string('channel', 20)->default('يدوي');         // يدوي / بريد
                $t->string('status', 40)->default('بانتظار التوقيع');
                $t->longText('signature')->nullable();
                $t->longText('selfie')->nullable();
                $t->string('id_no', 60)->nullable();
                $t->timestamp('signed_at')->nullable();
                $t->string('ip', 45)->nullable();
                $t->string('agent', 250)->nullable();
                $t->string('locale', 60)->nullable();
                $t->string('decline_reason', 400)->nullable();
                $t->timestamps();
            });
        }

        // الطلب القائم = موقّع واحد برمز الطلب نفسه (فالرابط القديم يفتح موقّعه)
        DB::table('sign_requests')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $r) {
                if (DB::table('contract_signers')->where('request_id', $r->id)->exists()) continue;
                DB::table('contract_signers')->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'request_id' => $r->id, 'order' => 1, 'role' => 'موقّع',
                    'name' => $r->signer_name ?: 'الطرف الثاني',
                    'token' => $r->token,
                    'status' => $r->status,
                    'signature' => $r->signature, 'selfie' => $r->selfie,
                    'id_no' => $r->signer_id_no,
                    'signed_at' => $r->signed_at, 'ip' => $r->signed_ip,
                    'agent' => $r->signed_agent, 'locale' => $r->signed_locale,
                    'decline_reason' => $r->declined_reason,
                    'created_at' => $r->created_at, 'updated_at' => $r->updated_at,
                ]);
            }
        });

        // ── سجل الأدلة append-only ──
        if (! Schema::hasTable('contract_events')) {
            Schema::create('contract_events', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('request_id')->nullable()->index();
                $t->uuid('contract_id')->nullable()->index();
                $t->uuid('signer_id')->nullable();
                $t->string('event', 40);        // created/sent/opened/otp_sent/otp_ok/signed/declined/voided/downloaded/reminded
                $t->uuid('actor_id')->nullable();
                $t->string('ip', 45)->nullable();
                $t->string('agent', 250)->nullable();
                $t->text('meta')->nullable();
                $t->timestamp('created_at');
                $t->index(['request_id', 'created_at']);
            });
        }

        // تاريخ خشن للطلبات القائمة: إنشاء/فتح/توقيع/رفض — فشهادات القديم لا تولد فارغة
        DB::table('sign_requests')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $r) {
                if (DB::table('contract_events')->where('request_id', $r->id)->exists()) continue;
                $mk = fn ($event, $at, $ip = null) => [
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'request_id' => $r->id, 'contract_id' => $r->contract_id,
                    'event' => $event, 'ip' => $ip, 'created_at' => $at,
                ];
                $ev = [$mk('created', $r->created_at)];
                if ($r->opened_at) $ev[] = $mk('opened', $r->opened_at);
                if ($r->signed_at) $ev[] = $mk('signed', $r->signed_at, $r->signed_ip);
                if ($r->status === 'رُفض') $ev[] = $mk('declined', $r->updated_at);
                DB::table('contract_events')->insert($ev);
            }
        });

        // ── القوالب: إصدارات ولغة وأرشفة ──
        Schema::table('sign_templates', function (Blueprint $t) {
            if (! Schema::hasColumn('sign_templates', 'version')) $t->unsignedInteger('version')->default(1);
            if (! Schema::hasColumn('sign_templates', 'archived_at')) $t->timestamp('archived_at')->nullable();
            if (! Schema::hasColumn('sign_templates', 'locale')) $t->string('locale', 10)->default('ar');
            if (! Schema::hasColumn('sign_templates', 'dir')) $t->string('dir', 3)->default('rtl');
        });
        if (! Schema::hasTable('sign_template_versions')) {
            Schema::create('sign_template_versions', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('template_id')->index();
                $t->unsignedInteger('version');
                $t->string('name', 160);
                $t->longText('body');
                $t->string('note', 300)->nullable();
                $t->uuid('editor_id')->nullable();
                $t->timestamp('created_at');
                $t->unique(['template_id', 'version']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sign_template_versions');
        Schema::dropIfExists('contract_events');
        Schema::dropIfExists('contract_signers');
        // أعمدة الجداول القائمة تبقى — لا هجرة مدمرة
    }
};
