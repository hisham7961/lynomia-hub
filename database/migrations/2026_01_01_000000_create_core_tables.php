<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {

        Schema::create('companies_root', function (Blueprint $t) { $t->uuid('id')->primary(); }); // placeholder يُحذف
        Schema::dropIfExists('companies_root');

        Schema::create('roles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 120);
            $t->boolean('is_owner')->default(false);
            $t->string('scope', 20)->default('proj');      // all | proj
            $t->json('flags')->nullable();   // secrets, approve, users, audit, monitor, exp, copySec — الافتراضي في الموديل
            $t->json('matrix')->nullable();  // {module: {v,a,e,d}}
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 160);
            $t->string('email', 190)->unique();
            $t->string('phone', 40)->nullable();
            $t->string('job_title', 160)->nullable();
            $t->uuid('role_id')->nullable()->index();
            $t->uuid('company_id')->nullable()->index();
            $t->string('status', 30)->default('نشط')->index();
            $t->string('password')->nullable();               // bcrypt/argon2
            $t->text('totp_secret_cipher')->nullable();       // مشفّر
            $t->json('recovery_codes')->nullable();          // hashed
            $t->boolean('totp_enabled')->default(false);
            $t->smallInteger('failed_attempts')->default(0);
            $t->timestamp('locked_until')->nullable();
            $t->timestamp('password_changed_at')->nullable();
            $t->string('allowed_ips', 400)->nullable();
            $t->json('notify_prefs')->nullable();
            $t->date('expires_at')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->string('last_login_ip', 60)->nullable();
            $t->rememberToken();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('sessions_log', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->index();
            $t->string('device', 200)->nullable();
            $t->string('ip', 60)->nullable();
            $t->string('user_agent', 400)->nullable();
            $t->timestamp('started_at');
            $t->timestamp('last_seen_at')->index();
            $t->boolean('revoked')->default(false);
        });

        Schema::create('audits', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('user_id')->nullable()->index();
            $t->string('action', 120);
            $t->string('module', 60)->nullable()->index();
            $t->uuid('record_id')->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->string('name', 300)->nullable();
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->string('reason', 400)->nullable();
            $t->string('device', 200)->nullable();
            $t->string('ip', 60)->nullable();
            $t->timestamp('created_at')->index();
        });
        DB::statement('CREATE INDEX audits_module_created_idx ON audits (module, created_at DESC)');

        Schema::create('record_versions', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('module', 60)->index();
            $t->uuid('record_id')->index();
            $t->integer('version');
            $t->json('snapshot');
            $t->uuid('changed_by')->nullable();
            $t->timestamp('created_at')->index();
            $t->unique(['module', 'record_id', 'version']);
        });

        Schema::create('record_locks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('module', 60);
            $t->uuid('record_id');
            $t->uuid('user_id')->index();
            $t->string('device', 200)->nullable();
            $t->timestamp('expires_at')->index();
            $t->timestamps();
            $t->unique(['module', 'record_id']);
        });

        Schema::create('attachments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('module', 60)->index();
            $t->uuid('record_id')->nullable()->index();
            $t->string('field', 60)->nullable();
            $t->string('disk', 40)->default('s3');
            $t->string('path', 500);
            $t->string('original_name', 300);
            $t->string('mime', 160)->nullable();
            $t->bigInteger('size')->default(0);
            $t->string('checksum', 80)->nullable()->index();   // sha256
            $t->string('av_status', 20)->default('pending')->index(); // pending|clean|infected|error
            $t->string('av_engine', 60)->nullable();
            $t->timestamp('scanned_at')->nullable();
            $t->string('thumb_path', 500)->nullable();
            $t->integer('version')->default(1);
            $t->uuid('replaces_id')->nullable();
            $t->uuid('uploaded_by')->nullable()->index();
            $t->integer('downloads')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('download_log', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('attachment_id')->index();
            $t->uuid('user_id')->nullable()->index();
            $t->string('ip', 60)->nullable();
            $t->string('device', 200)->nullable();
            $t->timestamp('created_at')->index();
        });

        Schema::create('notifications_hub', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->index();
            $t->string('kind', 40)->index();
            $t->string('text', 600);
            $t->string('module', 60)->nullable();
            $t->uuid('record_id')->nullable();
            $t->boolean('read')->default(false)->index();
            $t->timestamp('created_at')->index();
        });

        Schema::create('outbox', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->nullable()->index();
            $t->string('kind', 40)->index();
            $t->string('channel', 20)->index();     // app|tg|mail|n8n
            $t->string('target', 300)->nullable();
            $t->string('text', 800);
            $t->string('state', 20)->default('queued')->index();
            $t->string('error', 400)->nullable();
            $t->timestamp('created_at')->index();
            $t->timestamp('delivered_at')->nullable();
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->string('key', 120)->primary();
            $t->json('value');
            $t->timestamps();
        });

        Schema::create('imports_log', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('project_id')->nullable()->index();
            $t->string('source', 160);
            $t->integer('added')->default(0);
            $t->integer('updated')->default(0);
            $t->integer('failed')->default(0);
            $t->uuid('by')->nullable();
            $t->timestamp('created_at')->index();
        });

        Schema::create('automation_log', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('rule_id')->nullable()->index();
            $t->string('trigger', 120);
            $t->string('actions', 300)->nullable();
            $t->json('context')->nullable();
            $t->timestamp('created_at')->index();
        });

        Schema::create('kb_reads', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('article_id')->index();
            $t->uuid('user_id')->index();
            $t->timestamp('created_at');
            $t->unique(['article_id', 'user_id']);
        });

        Schema::create('activity_pings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('user_id')->index();
            $t->string('page', 160);
            $t->uuid('project_id')->nullable()->index();
            $t->integer('seconds')->default(0);
            $t->date('day')->index();
            $t->string('device', 200)->nullable();
            $t->timestamp('created_at');
        });
        DB::statement('CREATE INDEX activity_user_day_idx ON activity_pings (user_id, day)');

        Schema::create('work_hours', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('user_id')->index();
            $t->date('day')->index();
            $t->integer('active_sec')->default(0);
            $t->integer('idle_sec')->default(0);
            $t->timestamp('first_seen')->nullable();
            $t->timestamp('last_seen')->nullable();
            $t->unique(['user_id', 'day']);
        });

        Schema::create('screenshots', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->index();
            $t->uuid('attachment_id')->nullable();
            $t->string('page', 160)->nullable();
            $t->string('device', 200)->nullable();
            $t->boolean('consented')->default(true);
            $t->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        foreach (['screenshots','work_hours','activity_pings','kb_reads','automation_log','imports_log','settings',
                  'outbox','notifications_hub','download_log','attachments','record_locks','record_versions',
                  'audits','sessions_log','users','roles'] as $t) { Schema::dropIfExists($t); }
    }
};
