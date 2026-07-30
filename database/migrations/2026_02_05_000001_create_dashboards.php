<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * لوحات قابلة للبناء: جدولان فقط، على نمط `saved_views` المثبَّت.
 *
 * اللوحة تخصّ مالكها (`owner_id`) أو تُنشَر لدورٍ كامل (`role_id`) — أحدهما لا كلاهما.
 * الودجات صفوفٌ مستقلة بمفتاحها من `WidgetRegistry` وبموضعها وحجمها، فإضافة ودجة
 * جديدة للنظام لا تمسّ هذا المخطط.
 *
 * إضافةٌ محضة: اللوحة القديمة تبقى كما هي لمن لا لوحات له، و`prefs.dash.hidden`
 * يبقى عاملاً عليها كما كان.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboards', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('owner_id')->nullable()->index();   // لوحة شخصية
            $t->uuid('role_id')->nullable()->index();    // أو لوحة دور — لا يجتمعان
            $t->string('name', 80);
            $t->boolean('is_default')->default(false);
            $t->boolean('shared')->default(false);       // يراها زملاء الدور للقراءة
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['owner_id', 'is_default']);
        });

        Schema::create('dashboard_widgets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('dashboard_id')->index();
            $t->string('widget_key', 60);                // مفتاح من WidgetRegistry
            $t->json('config')->nullable();              // إعداد خاص بالودجة (اختياري)
            $t->unsignedSmallInteger('x')->default(0);
            $t->unsignedSmallInteger('y')->default(0);
            $t->unsignedSmallInteger('w')->default(6);
            $t->unsignedSmallInteger('h')->default(2);
            $t->timestamps();
            $t->index(['dashboard_id', 'y', 'x']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_widgets');
        Schema::dropIfExists('dashboards');
    }
};
