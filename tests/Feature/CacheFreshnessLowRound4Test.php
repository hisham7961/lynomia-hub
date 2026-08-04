<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Employee;
use App\Models\MediaItem;
use App\Models\PricingPlan;
use App\Models\Service;
use App\Support\Inbox;
use App\Support\MediaCenter;
use App\Support\Pricing;
use App\Support\TeamDirectory;
use Tests\TestCase;

/**
 * الموجة الرابعة — نضارة الخبيئة (missing-data-stamp، امتداد دفعة 268):
 * قرّاء `Pricing::all` و`MediaCenter::all` و`TeamDirectory::all` يُخبّئون بمهلةٍ
 * (٥–١٠ دقائق) بلا ختم جداولهم — فتعديلُ سعرٍ أو محتوى إعلاميّ أو ملفِ موظفٍ لا
 * يظهر حتى تنقضي المهلة. وصندوق الوارد `Inbox::TABLES` ينقصه جدول `assets` الذي
 * يقرؤه مصدر الإقرارات، فإقرارُ عهدةٍ جديد لا يُبطل الصندوق. الختم يسبق المهلة:
 * المفتاح يحمل `hub_data_stamp(جداوله)` فيتغيّر ساعةَ تتغيّر البيانات.
 */
class CacheFreshnessLowRound4Test extends TestCase
{
    /** (٨-أ) الباقات تعكس باقةً جديدة بلا fresh قسري */
    public function test_pricing_reflects_new_plan_without_forced_fresh(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $before = count(Pricing::all()['catalog']);   // يملأ الخبيئة

        $svc = Service::create(['name' => 'خدمة جديدة', 'cycle' => 'شهري', 'price' => 100, 'status' => 'نشطة']);
        PricingPlan::create(['service_id' => $svc->id, 'name' => 'باقة أساسية', 'price' => 20,
            'currency' => 'د.ك', 'cycle' => 'شهري', 'status' => 'نشطة', 'free_tier' => false]);

        $this->assertGreaterThan($before, count(Pricing::all()['catalog']),
            'باقةٌ جديدة لم تظهر في مركز التسعير — الخبيئة قديمة بلا ختم بيانات');
    }

    /** (٨-ب) مركز الإعلام يعكس مادةً جديدة بلا fresh قسري */
    public function test_media_center_reflects_new_item_without_forced_fresh(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $before = count(MediaCenter::all()['catalogue']);

        MediaItem::create(['title' => 'ظهورٌ إعلاميٌّ فريد', 'type' => 'مقابلة',
            'date' => now()->toDateString(), 'reach' => 1000]);

        $this->assertGreaterThan($before, count(MediaCenter::all()['catalogue']),
            'مادةٌ إعلاميةٌ جديدة لم تظهر — الخبيئة قديمة بلا ختم بيانات');
    }

    /** (٨-ج) دليل الفريق يعكس موظفاً جديداً بلا fresh قسري */
    public function test_team_directory_reflects_new_employee_without_forced_fresh(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $before = TeamDirectory::all()['n'];

        Employee::create(['name' => 'موظفٌ جديدٌ في الدليل', 'status' => 'نشط']);

        $this->assertGreaterThan($before, TeamDirectory::all()['n'],
            'موظفٌ جديد لم يظهر في دليل الفريق — الخبيئة قديمة بلا ختم بيانات');
    }

    /** (٩) صندوق الوارد يعكس عهدةً جديدة تنتظر إقراري بلا fresh قسري */
    public function test_inbox_reflects_new_asset_custody_without_forced_fresh(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $titles = fn () => collect(Inbox::items($this->owner))->pluck('title')->all();
        $this->assertNotContains('لابتوب العهدة الجديدة', $titles());   // يملأ الخبيئة

        Asset::create(['name' => 'لابتوب العهدة الجديدة', 'holder_id' => $this->owner->id,
            'status' => 'قيد الاستخدام']);

        $this->assertContains('لابتوب العهدة الجديدة', $titles(),
            'عهدةٌ جديدة لم تظهر في صندوق الوارد — assets غائبٌ عن ختم الصندوق');
    }
}
