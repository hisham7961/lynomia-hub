<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * سكّتان مكسورتان كشفتهما جولة التدقيق — كلتاهما تُنتج **حالةً لا يستطيع
 * المستخدم الخروج منها**:
 *
 *   ١) **قراءةٌ إلزامية لا تُقرَأ أبداً**: الصندوق الموحّد يقرأ «من قرأ» من
 *      `kb_reads`، والإقرارُ يكتب في `policy_acks`. فالمقال يُعرض «١٠٠٪ مُقَرّ»
 *      في لوحة السياسات، ويبقى في صندوق المستخدم **إلى الأبد بلا فعلٍ يُزيله**.
 *
 *   ٢) **`files.status` مفتاحٌ لا عمود**: السجل يقول `docStatus` والعمود
 *      `doc_status`. على SQLite يُعاد تفسيرها نصّاً فتُرجع صفراً صامتةً، وعلى
 *      **MySQL** `Unknown column` وخطأ ٥٠٠. وأثرُها أوسع: قائمة الحالات فارغة،
 *      والحدث لا يُطلَق عند التغيير، ووثيقةٌ **ملغاة** تبقى تُنبّه «تنتهي قريباً».
 */
class BrokenRailsTest extends TestCase
{
    /* ── سكّة الإقرار ── */

    public function test_acknowledging_a_must_read_article_clears_it_from_the_inbox(): void
    {
        $this->seedCore();
        $id = (string) Str::uuid();
        DB::table('kb_articles')->insert(['id' => $id, 'title' => 'مقالٌ إلزامي',
            'must_read' => 1, 'ver' => '1', 'status' => 'منشور',
            'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->owner);
        $this->assertContains('مقالٌ إلزامي',
            collect(\App\Support\Inbox::items($this->owner))->pluck('title')->all());

        hub_ack_do('kb', $id);

        $this->assertNotContains('مقالٌ إلزامي',
            collect(\App\Support\Inbox::items($this->owner))->pluck('title')->all(),
            'أقرَّ المستخدم بالقراءة وبقي البند في صندوقه — بلا فعلٍ يُزيله أبداً');
    }

    /** واللوحة والصندوق يتفقان: لا «١٠٠٪ مُقَرّ» بجانب بندٍ معلّق */
    public function test_the_board_and_the_inbox_agree_on_who_has_read(): void
    {
        $this->seedCore();
        $id = (string) Str::uuid();
        DB::table('kb_articles')->insert(['id' => $id, 'title' => 'دليل السلامة',
            'must_read' => 1, 'ver' => '2', 'status' => 'منشور',
            'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->owner);
        hub_ack_do('kb', $id);

        $st = hub_ack_state('kb', $id);
        $this->assertSame(100, (int) ($st['pct'] ?? 0));
        $this->assertNotContains('دليل السلامة',
            collect(\App\Support\Inbox::items($this->owner))->pluck('title')->all());
    }

    /** ونسخةٌ جديدة تُعيد الطلب — الإقرار مرتبطٌ بنصٍّ بعينه */
    public function test_a_new_version_asks_again(): void
    {
        $this->seedCore();
        $id = (string) Str::uuid();
        DB::table('kb_articles')->insert(['id' => $id, 'title' => 'سياسة الاستخدام',
            'must_read' => 1, 'ver' => '1', 'status' => 'منشور',
            'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->owner);
        hub_ack_do('kb', $id);
        DB::table('kb_articles')->where('id', $id)->update(['ver' => '2']);

        $this->assertContains('سياسة الاستخدام',
            collect(\App\Support\Inbox::items($this->owner))->pluck('title')->all(),
            'نصٌّ تغيّر وبقي إقرار النسخة القديمة يُغطّيه');
    }

    /** ومقالٌ مسودةٌ أو مؤرشف لا يُطالَب بقراءته أحد */
    public function test_draft_and_archived_articles_are_not_demanded(): void
    {
        $this->seedCore();
        foreach ([['مسودة', 'مقالٌ مسودة'], ['مؤرشف', 'مقالٌ مؤرشف'], ['منشور', 'مقالٌ منشور']] as [$st, $t]) {
            DB::table('kb_articles')->insert(['id' => (string) Str::uuid(), 'title' => $t,
                'must_read' => 1, 'ver' => '1', 'status' => $st,
                'created_at' => now(), 'updated_at' => now()]);
        }

        $this->actingAs($this->owner);
        $titles = collect(\App\Support\Inbox::items($this->owner))->pluck('title')->all();

        $this->assertContains('مقالٌ منشور', $titles);
        $this->assertNotContains('مقالٌ مسودة', $titles, 'مسودةٌ تُطالِب كل الفريق بقراءتها');
        $this->assertNotContains('مقالٌ مؤرشف', $titles, 'مؤرشفٌ لا يزال يُطلب');
    }

    /** والسياسة كذلك: مسودةٌ لا يُقرّ بها أحد */
    public function test_draft_policies_are_not_demanded(): void
    {
        $this->seedCore();
        DB::table('policies')->insert(['id' => (string) Str::uuid(), 'title' => 'سياسةٌ مسودة',
            'ver' => '1', 'ack_required' => 1, 'status' => 'مسودة',
            'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->owner);
        $this->assertNotContains('سياسةٌ مسودة',
            collect(\App\Support\Inbox::items($this->owner))->pluck('title')->all());
    }

    /* ── حالةُ الوثائق ── */

    /** حالةُ الوحدة تُقرأ عموداً حقيقياً في كل قارئ */
    public function test_every_module_status_resolves_to_a_real_column(): void
    {
        $bad = [];
        foreach (hub_modules() as $mk => $md) {
            $sc = $md['status'] ?? null;
            if (! $sc) continue;
            if (! \Illuminate\Support\Facades\Schema::hasColumn($md['table'], hub_status_col($mk))) {
                $bad[] = "{$mk} → {$md['table']}." . hub_status_col($mk);
            }
        }

        $this->assertSame([], $bad,
            'حالةُ وحدةٍ لا تُقابل عموداً — على MySQL: Unknown column وخطأ ٥٠٠: ' . implode(' · ', $bad));
    }

    /** وترشيحُ الحالة يعمل على وحدة الوثائق كما على غيرها */
    public function test_filtering_documents_by_status_works(): void
    {
        $this->seedCore();
        foreach ([['فعال', 'وثيقةٌ فعّالة'], ['ملغى', 'وثيقةٌ ملغاة']] as [$st, $t]) {
            DB::table('documents')->insert(['id' => (string) Str::uuid(), 'name' => $t,
                'doc_status' => $st, 'created_at' => now(), 'updated_at' => now()]);
        }

        $res = $this->actingAs($this->owner)->get('/m/files?status=' . urlencode('فعال'));
        $res->assertOk()->assertSee('وثيقةٌ فعّالة')->assertDontSee('وثيقةٌ ملغاة');
    }

    /** وقائمةُ الحالات في الشاشة ليست فارغة */
    public function test_the_documents_status_dropdown_is_not_empty(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->get('/m/files')->assertOk()->assertSee('قيد التجديد');
    }

    /** ووثيقةٌ ملغاة لا تُنبّه «تنتهي قريباً» إلى الأبد */
    public function test_a_cancelled_document_stops_nagging_about_expiry(): void
    {
        $this->seedCore();
        DB::table('documents')->insert(['id' => (string) Str::uuid(), 'name' => 'رخصةٌ ملغاة',
            'doc_status' => 'ملغى', 'expiry' => now()->addDays(5)->toDateString(),
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('documents')->insert(['id' => (string) Str::uuid(), 'name' => 'رخصةٌ فعّالة',
            'doc_status' => 'فعال', 'expiry' => now()->addDays(5)->toDateString(),
            'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->owner);
        $titles = collect(hub_expiry(true, $this->owner))->pluck('name')->all();

        $this->assertContains('رخصةٌ فعّالة', $titles);
        $this->assertNotContains('رخصةٌ ملغاة', $titles,
            'وثيقةٌ ملغاة تبقى تُنبّه «تنتهي قريباً» — والتنبيه الذي لا يُصدَّق يُلغى كلُّه');
    }
}
