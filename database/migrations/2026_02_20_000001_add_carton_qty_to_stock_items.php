<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تعبئة المنتجات بالكراتين: كل صنف مخزون يعرف «كم وحدة في كرتونته».
 *
 * إضافةٌ صرفة (عمود nullable) — لا يمسّ صنفاً قائماً ولا كمية ولا حركة. حين
 * يُضبط `carton_qty` لمنتج، تُحسب الكراتين تلقائياً على مستندات البيع (عروض
 * الأسعار/الفواتير) والشراء (الاستلام من المورد) وحركات المخزون. كمية أقل من
 * الكرتونة لا تعيق شيئاً — تُعرض ككسر كرتونة (وحدات سائبة) لا كخطأ.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_items')) return;
        if (Schema::hasColumn('stock_items', 'carton_qty')) return;

        Schema::table('stock_items', function (Blueprint $t) {
            // عدد الوحدات في الكرتونة — nullable: المنتج بلا تعبئة معرّفة يبقى صالحاً
            $t->unsignedInteger('carton_qty')->nullable()->after('unit');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('stock_items') && Schema::hasColumn('stock_items', 'carton_qty')) {
            Schema::table('stock_items', function (Blueprint $t) {
                $t->dropColumn('carton_qty');
            });
        }
    }
};
