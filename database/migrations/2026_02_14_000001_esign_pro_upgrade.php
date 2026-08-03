<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ترقية التوقيع الإلكتروني لمستوى عالمي:
 *  - verify_code: رمز تحقق قصير فريد (LYN-XXXX-XXXX) يُطابَق به المستند معنا.
 *  - doc_hash: بصمة SHA-256 لنص الوثيقة لحظة الإنشاء — أي تعديل يكشف نفسه.
 *  - opts: خيارات لكل طلبٍ على حدة (سيلفي تحقق، رقم هوية إلزامي، سماح الرفض…).
 *  - selfie / signer_id_no / declined_reason / expires_at: مخرجات تلك الخيارات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sign_requests', function (Blueprint $t) {
            if (! Schema::hasColumn('sign_requests', 'verify_code')) {
                $t->string('verify_code', 20)->nullable()->unique()->after('token');
                $t->string('doc_hash', 64)->nullable()->after('verify_code');
                $t->text('opts')->nullable()->after('doc_hash');
                $t->longText('selfie')->nullable()->after('signature');
                $t->string('signer_id_no', 60)->nullable()->after('signer_name');
                $t->string('declined_reason', 400)->nullable()->after('status');
                $t->timestamp('expires_at')->nullable()->after('opens');
            }
        });

        // الطلبات القائمة تنال رمزاً وبصمة بأثر رجعي — فالتحقق يعمل عليها أيضاً
        foreach (DB::table('sign_requests')->whereNull('verify_code')->get(['id', 'body']) as $r) {
            DB::table('sign_requests')->where('id', $r->id)->update([
                'verify_code' => 'LYN-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 4))
                               . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 4)),
                'doc_hash'    => hash('sha256', (string) $r->body),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('sign_requests', function (Blueprint $t) {
            $t->dropColumn(['verify_code', 'doc_hash', 'opts', 'selfie',
                            'signer_id_no', 'declined_reason', 'expires_at']);
        });
    }
};
