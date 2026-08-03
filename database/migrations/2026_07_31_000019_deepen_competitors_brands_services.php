<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ثلاث وحداتٍ كانت أضيق من عملها — إضافةٌ محضة بأعمدة nullable:
 *
 *  • المنافسون: خانةٌ **واحدة** نصية لكل حساباتهم على منصات التواصل، ولا سعرٌ
 *    مُقارَن ولا تطبيقٌ لهم ولا موعدَ مراجعةٍ فيُنسى المنافس سنةً كاملة.
 *  • العلامات: لا رقمَ تسجيلٍ بالملكية الفكرية ولا فئاتٍ ولا تاريخَ تجديد —
 *    والعلامة تسقط بانقضاء مدتها صمتاً. ولا ربطَ بمشروعٍ ولا نطاقٍ ولا حساب.
 *  • الخدمات: سعرٌ وتكلفة، ولا قيمةٌ ولا مخرجات ولا من ينافسها ولا الأفكار
 *    التي تطوّرها — فالابتكار في وادٍ والخدمة في وادٍ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitors', function (Blueprint $t) {
            // قناةٌ لكل منصة بدل خانةٍ واحدة تُحشر فيها الروابط كلها
            foreach (['instagram', 'tiktok', 'x_url', 'linkedin', 'youtube', 'facebook', 'snapchat'] as $c) {
                $t->string($c, 600)->nullable();
            }
            $t->decimal('followers', 16, 0)->nullable();      // إجمالي متابعيهم (سلسلة زمنية)
            $t->decimal('price_from', 16, 3)->nullable();     // أرخص باقة معلنة
            $t->decimal('price_to', 16, 3)->nullable();       // أغلى باقة معلنة
            $t->string('currency', 10)->nullable();
            $t->string('billing', 60)->nullable();            // شهري/سنوي/مرة واحدة/حسب الاستخدام
            $t->boolean('free_tier')->default(false);         // هل لديهم باقة مجانية؟
            $t->unsignedSmallInteger('trial_days')->nullable();
            $t->string('play', 600)->nullable();              // تطبيقهم على أندرويد
            $t->string('appstore', 600)->nullable();          // وعلى iOS
            $t->decimal('app_downloads', 16, 0)->nullable();
            $t->decimal('app_rating', 3, 2)->nullable();
            $t->string('hq', 200)->nullable();                // مقرّهم
            $t->string('founded', 40)->nullable();            // سنة التأسيس
            $t->string('size', 60)->nullable();               // حجم الفريق التقريبي
            $t->string('funding', 120)->nullable();           // تمويلهم المعلن
            $t->string('positioning', 80)->nullable();        // الأرخص/الأفخم/الأسرع/الأشمل
            $t->text('our_edge')->nullable();                 // ما نتفوّق به عليهم
            $t->text('their_edge')->nullable();               // وما يتفوّقون به علينا
            $t->date('next_review')->nullable()->index();     // موعد المراجعة القادمة
            $t->string('review_cycle', 40)->nullable();       // دورية المراجعة
        });

        Schema::table('brands', function (Blueprint $t) {
            $t->uuid('project_id')->nullable()->index();      // المشروع الذي تخدمه العلامة
            $t->string('name_en', 300)->nullable();
            $t->string('slogan', 300)->nullable();
            $t->string('tm_no', 120)->nullable();             // رقم التسجيل بالملكية الفكرية
            $t->string('tm_status', 60)->nullable()->index(); // غير مسجّلة/قيد الإيداع/مسجّلة/…
            $t->string('tm_classes', 200)->nullable();        // فئات نيس المسجَّلة
            $t->string('tm_countries', 300)->nullable();      // البلدان — الحماية إقليمية لا عالمية
            $t->string('tm_office', 200)->nullable();         // جهة التسجيل
            $t->string('tm_agent', 200)->nullable();          // وكيل الملكية الفكرية
            $t->date('tm_filed_at')->nullable();
            $t->date('tm_reg_at')->nullable();
            $t->date('tm_expiry')->nullable()->index();       // تاريخ التجديد — يصل الرادار
            $t->json('domain_ids')->nullable();               // النطاقات المطابقة للعلامة
            $t->json('social_ids')->nullable();               // حسابات التواصل التابعة لها
            $t->json('app_ids')->nullable();                  // التطبيقات التي تحملها
        });

        Schema::table('services', function (Blueprint $t) {
            $t->uuid('brand_id')->nullable()->index();
            $t->uuid('owner_id')->nullable()->index();        // مالك الخدمة (Service Owner)
            $t->json('competitor_ids')->nullable();           // من ينافسها
            $t->json('idea_ids')->nullable();                 // أفكار تطوّرها — وصلُ الابتكار بالخدمة
            $t->string('unit', 60)->nullable();               // وحدة التسعير: مستخدم/جهاز/ساعة/مشروع
            $t->string('currency', 10)->nullable();
            $t->decimal('min_price', 16, 3)->nullable();      // أدنى سعرٍ يُقبل — سياجُ التفاوض
            $t->decimal('setup_fee', 16, 3)->nullable();
            $t->string('stage', 60)->nullable()->index();     // فكرة/قيد التطوير/مُطلقة/ناضجة/قيد الإيقاف
            $t->date('launch_at')->nullable();
            $t->date('retire_at')->nullable()->index();
            $t->date('price_review')->nullable()->index();    // موعد مراجعة السعر — يصل الرادار
            $t->text('value_prop')->nullable();               // عرض القيمة: لماذا يشتريها العميل
            $t->text('audience')->nullable();                 // لمن هي
            $t->text('features')->nullable();
            $t->text('deliverables')->nullable();             // ما يستلمه العميل بالضبط
            $t->text('requirements')->nullable();             // ما نحتاجه منه قبل البدء
            $t->string('lead_time', 120)->nullable();         // مدة التسليم
            $t->string('warranty', 120)->nullable();
            $t->string('docs_url', 600)->nullable();
        });

        Schema::table('ideas', function (Blueprint $t) {
            $t->uuid('service_id')->nullable()->index();      // الخدمة التي تطوّرها الفكرة
        });
    }

    public function down(): void
    {
        Schema::table('competitors', function (Blueprint $t) {
            $t->dropColumn(['instagram', 'tiktok', 'x_url', 'linkedin', 'youtube', 'facebook', 'snapchat',
                'followers', 'price_from', 'price_to', 'currency', 'billing', 'free_tier', 'trial_days',
                'play', 'appstore', 'app_downloads', 'app_rating', 'hq', 'founded', 'size', 'funding',
                'positioning', 'our_edge', 'their_edge', 'next_review', 'review_cycle']);
        });
        Schema::table('brands', function (Blueprint $t) {
            $t->dropColumn(['project_id', 'name_en', 'slogan', 'tm_no', 'tm_status', 'tm_classes',
                'tm_countries', 'tm_office', 'tm_agent', 'tm_filed_at', 'tm_reg_at', 'tm_expiry',
                'domain_ids', 'social_ids', 'app_ids']);
        });
        Schema::table('services', function (Blueprint $t) {
            $t->dropColumn(['brand_id', 'owner_id', 'competitor_ids', 'idea_ids', 'unit', 'currency',
                'min_price', 'setup_fee', 'stage', 'launch_at', 'retire_at', 'price_review',
                'value_prop', 'audience', 'features', 'deliverables', 'requirements',
                'lead_time', 'warranty', 'docs_url']);
        });
        Schema::table('ideas', function (Blueprint $t) {
            $t->dropColumn(['service_id']);
        });
    }
};
