<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **سجل الهوية الموحّد — المنتجُ غير القطعة، والباركود العالمي غير كود العهدة.**
 *
 * كانت هويّة الممتلكات مبعثرة على ثلاثة أعمدة لا يجمعها شيء: `assets.serial`
 * (رقمُ المصنع، نصٌّ حرّ يتكرر بين مصنّعين)، و`assets.code` (كودُ العهدة الذي
 * نملكه)، و`stock_items.barcode` (باركود صنف المخزون). ولا وجودَ لمفهوم
 * «المنتج/الطراز» أصلاً: عشرون لابتوباً متطابقاً تعني عشرين اسماً يُكتب باليد،
 * والباركود العالمي (GTIN) الذي يعرّف الطرازَ كان يُخلط برقم القطعة.
 *
 * ثلاثة جداول تفصل المفاهيم وتوحّد البحث:
 *
 *   — **`products`**: سجل الطُرز (Product Master). «Dell Latitude 5550» صفٌّ
 *     واحد بكوده الدائم `LYN-PRD-XXXXXXXX`، تشير إليه كلُّ قطعةٍ مملوكة.
 *   — **`record_identifiers`**: معرفاتٌ مرنة على سكة (module, record_id)
 *     متعددة الأشكال — كما المرفقات تماماً: GTIN وEAN وUPC وسيريال وMPN
 *     وSKU واسمٌ بديل بعد دمج... صفٌّ لكل معرّف، لا عمودٌ لكل نوع. الفهرس
 *     الفريد (module, kind, norm) يمنع التكرار على مستوى القاعدة —
 *     فمستخدمان يمسحان GTIN مجهولاً معاً لا يخلقان منتجَين.
 *   — **`identity_lookups`**: كاشُ الاستكشاف الخارجي — باركود سُئل عنه مرةً
 *     لا يُسأل عنه المزوّدون ثانيةً بلا داعٍ.
 *
 * و`assets.product_id` يربط القطعةَ بطرازها — عمودٌ nullable مُضاف، فلا
 * مساسَ بأصلٍ قائم ولا بسجل عهدته ولا بتاريخه.
 *
 * إضافةٌ محضة: جداول جديدة وعمودٌ nullable وتعريفٌ رجعيّ للمعرفات القائمة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('code', 60)->nullable();          // LYN-PRD-XXXXXXXX — يولّده النظام
                $t->string('name', 300);
                $t->string('brand', 300)->nullable();
                $t->string('manufacturer', 300)->nullable();
                $t->string('model', 300)->nullable();
                $t->string('type', 80)->nullable()->index(); // نفس مفردات أصناف العهد
                $t->string('barcode', 300)->nullable();      // الباركود العالمي الأساسي (GTIN/EAN/UPC)
                $t->string('mpn', 300)->nullable();          // رقم قطعة المصنع
                $t->string('origin', 300)->nullable();
                $t->text('descr')->nullable();
                $t->json('specs')->nullable();               // بقالب صنفه — كما assets.specs
                $t->string('status', 80)->nullable()->index();
                $t->uuid('supplier_id')->nullable()->index();
                $t->uuid('company_id')->nullable()->index();
                $t->text('notes')->nullable();
                $t->json('tags')->nullable();
                $t->uuid('project_id')->nullable()->index();
                $t->json('custom')->nullable();
                $t->json('meta')->nullable();                // مصادر الاستكشاف والثقة والدمج
                $t->integer('version')->default(1);
                $t->boolean('archived')->default(false)->index();
                $t->uuid('created_by')->nullable()->index();
                $t->uuid('updated_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
            DB::statement('CREATE UNIQUE INDEX products_code_uq ON products (code)');
            DB::statement('CREATE INDEX products_proj_created_idx ON products (project_id, created_at DESC)');
            DB::statement('CREATE INDEX products_status_proj_idx ON products (status, project_id)');
        }

        if (! Schema::hasTable('record_identifiers')) {
            Schema::create('record_identifiers', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('module', 60);
                $t->string('record_id', 36);
                $t->string('kind', 40);                      // gtin/ean/upc/barcode/serial/mpn/sku/tag/lyn/alias…
                $t->string('value', 300);                    // كما أُدخل
                $t->string('norm', 190);                     // مُطبَّع للبحث والتفرّد (أرقامٌ للـGTIN، Upper للباقي)
                $t->string('issuer', 300)->nullable();       // من أصدره (GS1، المصنع، Lynomia…)
                $t->string('source', 300)->nullable();       // من أين جاء (يدوي/هجرة/اسم مزوّد)
                $t->boolean('is_primary')->default(false);
                $t->boolean('verified')->default(false);
                $t->json('meta')->nullable();
                $t->uuid('created_by')->nullable();
                $t->timestamps();

                $t->index(['module', 'record_id'], 'rid_owner_idx');
                $t->index('norm', 'rid_norm_idx');
                // التفرّد على مستوى القاعدة: معرّفٌ واحد بنوعه داخل الوحدة —
                // هذا ما يجعل «مستخدمان يمسحان معاً» تنتهي بمنتجٍ واحد لا اثنين
                $t->unique(['module', 'kind', 'norm'], 'rid_mod_kind_norm_uq');
            });
        }

        if (! Schema::hasTable('identity_lookups')) {
            Schema::create('identity_lookups', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('norm', 190)->unique();
                $t->string('status', 20);                    // found | notfound
                $t->json('result')->nullable();              // الاقتراح المجمَّع المُطبَّع
                $t->json('providers')->nullable();           // من أجاب ومن أخفق — للمساءلة
                $t->unsignedInteger('hits')->default(0);
                $t->timestamp('checked_at')->nullable();
                $t->timestamps();
            });
        }

        if (Schema::hasTable('assets') && ! Schema::hasColumn('assets', 'product_id')) {
            Schema::table('assets', function (Blueprint $t) {
                $t->uuid('product_id')->nullable()->index();
            });
        }

        // ── تعريفٌ رجعيّ: هويّات الموجود تدخل السجل فيجدها المحلّل من اليوم الأول ──
        // chunkById لا orderBy('created_at') — والترتيب هنا ترتيبُ معالجةٍ لا دلالة.
        $now = now();
        $seen = [];
        $put = function (string $module, string $recordId, string $kind, ?string $value) use (&$seen, $now) {
            $value = trim((string) $value);
            if ($value === '') return;
            $norm = \App\Support\Identity::norm($kind, $value);
            if ($norm === '') return;
            $key = $module . '|' . $kind . '|' . $norm;
            if (isset($seen[$key])) return;                  // سيريالٌ مكرر في البيانات القديمة: الأول يحجز
            $seen[$key] = true;
            DB::table('record_identifiers')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'module' => $module, 'record_id' => $recordId,
                'kind' => $kind, 'value' => mb_substr($value, 0, 300), 'norm' => $norm,
                'source' => 'هجرة v2.363', 'is_primary' => $kind === 'lyn',
                'verified' => $kind === 'lyn',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        };

        // الموجود مسبقاً في السجل (تشغيلُ الهجرة مرتين أو صفوفٌ زُرعت): يُحترم
        foreach (DB::table('record_identifiers')->select('module', 'kind', 'norm')->cursor() as $r) {
            $seen[$r->module . '|' . $r->kind . '|' . $r->norm] = true;
        }

        if (Schema::hasTable('assets')) {
            DB::table('assets')->select('id', 'code', 'serial', 'tag')
                ->chunkById(200, function ($rows) use ($put) {
                    foreach ($rows as $a) {
                        $put('assets', $a->id, 'lyn', $a->code);
                        $put('assets', $a->id, 'serial', $a->serial);
                        $put('assets', $a->id, 'tag', $a->tag);
                    }
                });
        }

        if (Schema::hasTable('stock_items')) {
            DB::table('stock_items')->select('id', 'sku', 'barcode')
                ->chunkById(200, function ($rows) use ($put) {
                    foreach ($rows as $s) {
                        $put('stock', $s->id, 'barcode', $s->barcode);
                        $put('stock', $s->id, 'sku', $s->sku);
                    }
                });
        }
    }

    public function down(): void
    {
        // التراجعُ خسارةُ هويّاتٍ قد تكون طُبعت على ملصقات — لا هدمَ آلياً.
    }
};
