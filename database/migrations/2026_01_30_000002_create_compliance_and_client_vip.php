<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** سجل الامتثال (تراخيص وضرائب واشتراطات بمواعيد وأدلة) + علم VIP على العملاء */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('compliance_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 300);
            $t->string('kind', 80)->nullable()->index();        // ترخيص، ضريبة، تأمينات، حماية بيانات…
            $t->string('authority', 200)->nullable();           // الجهة المُلزِمة
            $t->uuid('company_id')->nullable()->index();
            $t->string('ref_no', 120)->nullable();              // رقم الترخيص/المرجع لدى الجهة
            $t->date('due')->nullable()->index();               // الاستحقاق/التجديد — يغذي رادار الانتهاءات
            $t->date('renewed_at')->nullable();
            $t->decimal('fee', 14, 2)->nullable();              // رسوم التجديد
            $t->uuid('owner_id')->nullable()->index();
            $t->text('req')->nullable();                        // المتطلبات والمستندات اللازمة
            $t->uuid('att_id')->nullable();                     // دليل الامتثال (شهادة/إيصال)
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
            $t->index(['status', 'due']);
        });

        Schema::table('clients', fn (Blueprint $t) => $t->boolean('vip')->default(false)->index());
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_items');
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('vip'));
    }
};
