<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** الاشتراكات والتجديدات — Subscriptions */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('service', 300);
            $t->uuid('company_id')->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->string('type', 300)->nullable();
            $t->string('cycle', 80)->nullable()->index();
            $t->date('date_start')->nullable();
            $t->date('renew')->index();
            $t->decimal('amount', 16, 3)->nullable();
            $t->string('currency', 80)->nullable()->index();
            $t->string('pay', 300)->nullable();
            $t->uuid('owner_id')->nullable()->index();
            $t->boolean('auto_renew')->default(false);
            $t->string('status', 80)->nullable()->index();
            $t->string('card', 300)->nullable();
            $t->date('card_exp')->nullable()->index();
            $t->string('invoice_url', 600)->nullable();
            $t->uuid('att_id')->nullable(); // مرفق في attachments
            $t->string('acc_url', 600)->nullable();
            $t->string('alerts', 300)->nullable();
            $t->text('notes')->nullable();
            $t->json('custom')->nullable();            // الحقول المخصصة
            $t->json('meta')->nullable();              // بيانات إضافية (تاريخ، تكاملات، تتبع)
            $t->integer('version')->default(1);         // القفل التفاؤلي
            $t->boolean('archived')->default(false)->index();
            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        DB::statement("CREATE INDEX subscriptions_proj_created_idx ON subscriptions (project_id, created_at DESC)");
        DB::statement("CREATE INDEX subscriptions_status_proj_idx ON subscriptions (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
