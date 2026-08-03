<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سحبُ رسالةٍ أُرسلت بالخطأ — ولا يمحو أحدٌ كلام غيره.
 *
 * الحذفُ ناعمٌ لا قاسٍ: مكانُ الرسالة يقول «حُذفت رسالة» بدل أن تختفي بلا تفسير
 * فيقرأ الطرفُ الآخر محادثةً مبتورةً لا يعرف ما نقص منها. والصفُّ يبقى فيبقى
 * الدليلُ إن لزم — الحذفُ من الشاشة لا من التاريخ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dm_messages', function (Blueprint $t) {
            $t->timestamp('deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dm_messages', fn (Blueprint $t) => $t->dropColumn('deleted_at'));
    }
};
