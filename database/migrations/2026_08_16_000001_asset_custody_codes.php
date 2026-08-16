<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **كودُ العهدة — هويّةٌ نملكها نحن، لا رقمٌ يملكه المصنع.**
 *
 * كان في الأصل حقلان للتعريف: `serial` (الرقم التسلسلي من المصنع) و`tag` نصٌّ
 * حرٌّ يكتبه من يتذكّر. وكلاهما لا يصلح مفتاحاً للعهدة:
 *
 *   — **السيريال يملكه غيرنا**: يتغيّر شكله بين مصنّعٍ وآخر، ويتكرّر بين
 *     صنفين، ويُستبدل الجهازُ فيذهب رقمُه ومعه أثرُ العهدة كلِّه. وأصلٌ بلا
 *     سيريال أصلاً (أثاثٌ أو رخصةٌ) يبقى بلا هوية.
 *   — **والـtag نصٌّ حرّ**: يُترك فارغاً، ويُكتب مرتين، وليس فيه ما يقول من أي
 *     صنفٍ هذا الأصل.
 *
 * فالعمود `code` هويّةٌ **يولّدها النظام**: `LYN-{كود الصنف}-{السنة}-{تسلسل}`،
 * فريدٌ على مستوى القاعدة، يُطبَع على الملصق ويُمسح بالـQR، ويبقى مع العهدة
 * حتى لو تبدّل الجهازُ تحته. و`specs` تحمل **المواصفات الداخلية** التي لا
 * يتّسع لها عمودٌ لكل صنف (معالجُ السيرفر، وIMEI الهاتف، ورقم شاصي السيارة).
 *
 * و`asset_custody` سجلُّ الحيازة: `holder_id` يقول **من يحمل الآن**، ولا يقول
 * قطّ **من حمل قبله ومتى سلّم** — فعهدةٌ مرّت على ثلاثة موظفين لا أثر لاثنين.
 * كلُّ تسليمٍ واستردادٍ صارا صفّاً بتاريخه ومن نفّذه. وفيه كذلك **التصاريح**:
 * ورقةُ نقلٍ أو خروجٍ مرقّمةٌ لها موعدُ عودةٍ يُتابَع وطلبُ توقيعٍ يُربَط بها.
 *
 * إضافةٌ محضة: أعمدةٌ nullable وجدولٌ جديد — لا عمودَ قائمٌ يُمَسّ.
 */
return new class extends Migration
{
    /** الكود الأساسي لكل صنف — مطابقٌ لسجل `config/hub_assets.php` (النسخةُ هنا للترحيل وحده) */
    public const CATS = [
        'لابتوب' => 'LT', 'هاتف' => 'PH', 'سيرفر' => 'SV', 'شاشة' => 'SC',
        'سويتش' => 'SW', 'UPS' => 'UPS', 'طابعة' => 'PR', 'أثاث' => 'FN',
        'سيارة' => 'CR', 'رخصة برمجية' => 'LC', 'أخرى' => 'GN',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('assets')) return;

        Schema::table('assets', function (Blueprint $t) {
            if (! Schema::hasColumn('assets', 'code')) {
                // ٦٠ حرفاً تكفي أطولَ صيغةٍ معقولة — والفهرسُ الفريد يحرس التوليد
                // من التصادم تحت التزامن (المولّد يعيد المحاولة عند الخرق).
                $t->string('code', 60)->nullable();
            }
            if (! Schema::hasColumn('assets', 'specs')) {
                $t->json('specs')->nullable();
            }
        });

        // الفهرسُ الفريد منفصلٌ عن إضافة العمود: تعبئةُ الأكواد تسبقه فلا يسقط
        // على قاعدةٍ فيها صفوفٌ قديمة، والتكرارُ محروسٌ بفحص وجوده أوّلاً.
        $this->backfill();

        if (! $this->hasIndex('assets_code_uq')) {
            Schema::table('assets', fn (Blueprint $t) => $t->unique('code', 'assets_code_uq'));
        }

        if (! Schema::hasTable('asset_custody')) {
            Schema::create('asset_custody', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('asset_id')->index();
                $t->uuid('user_id')->nullable()->index();     // من استلم (فارغٌ في الاسترداد للمخزن)
                $t->uuid('company_id')->nullable()->index();
                $t->string('action', 40);                      // تسليم · استرداد · نقل · خروج مؤقت · خروج نهائي
                $t->date('at');
                $t->string('note', 500)->nullable();
                $t->uuid('by_id')->nullable()->index();        // من نفّذ الحركة
                $t->json('meta')->nullable();

                // ── التصريح: خروجُ العهدة من المقرّ أو نقلُها بين الأيدي ──
                // حركةُ الحيازة تُقيَّد بعد وقوعها؛ أما **التصريح** فيسبقها:
                // ورقةٌ مرقّمةٌ تُطبَع وتُوقَّع وتُبرَز عند البوابة، ولها موعدُ
                // عودةٍ يُتابَع. بلا رقمٍ ولا موعدٍ يخرج الجهازُ «للصيانة» فلا
                // يعود ولا يسأل عنه أحدٌ لأن لا ورقةَ مفتوحةً باسمه.
                $t->string('permit_no', 60)->nullable();       // رقم التصريح المولَّد
                $t->string('to_loc', 300)->nullable();         // الجهة/الموقع المنقول إليه
                $t->date('due')->nullable();                   // العودة المتوقّعة (للخروج المؤقت)
                $t->date('returned_at')->nullable();           // العودة الفعلية
                $t->string('status', 40)->nullable();          // ساري · أُعيد · ملغى
                $t->uuid('sign_id')->nullable()->index();      // طلبُ التوقيع الإلكتروني المربوط

                $t->timestamps();
                $t->softDeletes();

                $t->index(['asset_id', 'at']);
                $t->unique('permit_no', 'asset_custody_permit_uq');
                $t->index(['status', 'due']);                  // متابعةُ ما خرج ولم يعد
            });
        }
    }

    /**
     * تعبئةُ الأصول القائمة: كلُّ أصلٍ بلا كودٍ يأخذ واحداً بتسلسل صنفه وسنة
     * إنشائه — فالقاعدةُ القديمة تصير مُرقَّمةً كاملةً بلا إدخالٍ يدويّ.
     * مأمونُ التكرار: على قاعدةٍ مُرقَّمةٍ سلفاً لا يجد ما يُعبّئه.
     */
    protected function backfill(): void
    {
        if (! Schema::hasColumn('assets', 'code')) return;

        // أعلى تسلسلٍ مستعمَلٍ لكل بادئة — البدايةُ من فوقه لا من الواحد.
        // lazyById لا pluck: الترحيل يعمل على استضافةٍ بحدِّ ذاكرةٍ ضيّق.
        $seq = [];
        foreach (DB::table('assets')->whereNotNull('code')
            ->select(['id', 'code'])->orderBy('id')->lazyById(1000) as $row) {
            if (preg_match('/^(.*)-(\d+)$/', (string) $row->code, $m)) {
                $seq[$m[1]] = max($seq[$m[1]] ?? 0, (int) $m[2]);
            }
        }

        // بلا orderBy('created_at') قبل chunkById: أيُّ ترتيبٍ قبل المفتاح يكسر
        // ملاحقةَ الصفحات فتقفز فوق صفوفٍ لا تُرقَّم أبداً (عطلُ ترقيم العقود نفسُه).
        DB::table('assets')->whereNull('code')->orderBy('id')
            ->chunkById(200, function ($rows) use (&$seq) {
                foreach ($rows as $a) {
                    $cat = self::CATS[(string) ($a->type ?? '')] ?? 'GN';
                    $year = substr((string) $a->created_at, 0, 4) ?: date('Y');
                    $prefix = 'LYN-' . $cat . '-' . $year;
                    $seq[$prefix] = ($seq[$prefix] ?? 0) + 1;
                    DB::table('assets')->where('id', $a->id)
                        ->update(['code' => $prefix . '-' . sprintf('%04d', $seq[$prefix])]);
                }
            });
    }

    /** هل الفهرس موجودٌ سلفاً؟ فحصٌ يعمل على المحرّكين (MySQL وSQLite) */
    protected function hasIndex(string $name): bool
    {
        try {
            return Schema::hasIndex('assets', $name);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function down(): void
    {
        // ترقيمٌ لا يُنقَض: الكود مطبوعٌ على ملصقاتٍ ملصوقةٍ على الأجهزة، ونزعُه
        // خسارةُ ربطٍ مادّيّ لا يُستعاد. وسجلُّ الحيازة إثباتُ تسليمٍ يُحتجّ به.
    }
};
