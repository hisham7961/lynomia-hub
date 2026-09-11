<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **قواعدُ الوصولِ للوثائق على مستوى المورد** (Permissions 360 · وثائق · المستوى 5/6).
 *
 * إضافةٌ لا كسر: طبقةُ استثناءٍ صريحةٍ فوقَ توثيقِ السجلِّ الأمّ القائم (المرفقُ يُتاح
 * بصلاحيةِ رؤيةِ سجلِّه ونطاقِه). هنا يمنحُ المالكُ/المديرُ **سماحاً أو منعاً لوثيقةٍ بعينها**
 * لدورٍ أو مستخدمٍ — مربوطاً **بمعرِّفِ الموردِ المستقرّ** (لا باسم الملف الذي يتغيّر).
 *
 * الأسبقيّةُ الحتميّة (الأخصُّ يعلو، والمنعُ يعلو في المستوى الواحد):
 *   حدُّ العميل/النطاق (guardRecord) ← المالك ← منعُ المستخدم ← سماحُ المستخدم ←
 *   منعُ الدور ← سماحُ الدور ← الافتراض (السجلُّ الأمُّ مرئيٌّ ⇒ سماح).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('document_access_rules')) return;

        Schema::create('document_access_rules', function (Blueprint $t) {
            $t->bigIncrements('id');
            // الموردُ: نوعُه ومعرِّفُه المستقرّ (attachment الآن؛ document/fin_document لاحقاً)
            $t->string('resource_type', 30)->default('attachment');
            $t->uuid('resource_id')->index();
            // الطرفُ صاحبُ القاعدة: دورٌ أو مستخدم
            $t->string('principal_type', 10);         // 'role' | 'user'
            $t->uuid('principal_id');
            $t->string('effect', 6);                  // 'allow' | 'deny'
            $t->string('action', 16)->default('*');   // '*' | 'download' | 'preview' | 'view'
            $t->string('note', 300)->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();

            $t->index(['resource_type', 'resource_id'], 'dar_resource_idx');
            $t->index(['principal_type', 'principal_id'], 'dar_principal_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_access_rules');
    }
};
