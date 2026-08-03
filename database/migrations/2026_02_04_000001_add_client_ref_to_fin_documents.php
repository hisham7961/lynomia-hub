<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ترقية ربط المستند المالي بالعميل من مطابقة نصية إلى مرجع حقيقي.
 *
 * كانت فواتير العميل تُجمع بـ`where('partner', $client->name)` — يكسرها تغيير اسم
 * العميل، ويخلط عميلين متشابهي الاسم في رحلة واحدة.
 *
 * الترحيل **محافظ عمداً**: يملأ `client_id` فقط حين يطابق الاسمُ عميلاً **واحداً
 * لا لبس فيه**. الأسماء المكرّرة تُترك فارغة لأن اختيار أحدهما تخمينٌ يفسد بياناً
 * ماليّاً — ورحلة العميل تُكمل بالمطابقة النصية لما لم يُرحَّل، وتقول ذلك صراحةً.
 * العمود `partner` يبقى كما هو: لا حذف ولا إعادة تسمية.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fin_documents')) return;

        if (! Schema::hasColumn('fin_documents', 'client_id')) {
            Schema::table('fin_documents', function (Blueprint $t) {
                $t->uuid('client_id')->nullable()->after('partner');
                $t->index('client_id', 'fin_documents_client_id_index');
            });
        }

        if (! Schema::hasTable('clients')) return;

        // الأسماء التي تخصّ عميلاً واحداً بالضبط — وحدها تُرحَّل
        $unique = DB::table('clients')->whereNull('deleted_at')
            ->whereNotNull('name')->where('name', '!=', '')
            ->select('name', DB::raw('COUNT(*) as n'), DB::raw('MIN(id) as id'))
            ->groupBy('name')->havingRaw('COUNT(*) = 1')->get();

        foreach ($unique as $c) {
            DB::table('fin_documents')
                ->whereNull('client_id')->where('partner', $c->name)
                ->update(['client_id' => $c->id]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('fin_documents') || ! Schema::hasColumn('fin_documents', 'client_id')) return;

        Schema::table('fin_documents', function (Blueprint $t) {
            $t->dropIndex('fin_documents_client_id_index');
            $t->dropColumn('client_id');
        });
    }
};
