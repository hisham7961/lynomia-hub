<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Comment;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Role;
use App\Models\StockItem;
use App\Models\StockMove;
use App\Models\User;
use Tests\TestCase;

/**
 * الموجة السادسة — الدفعة ١٠: **مساراتُ كتابةٍ تتجاوز حارساً أو تُفجّر مورداً.**
 *
 *  ١) `ApprovalDecisionController:102` — حسمُ الموافقات بلا تنطيق: معتمِدٌ من
 *     شركةٍ أخرى ينفّذ تعديلاً على سجلٍ لا يراه أصلاً.
 *  ٢) `CommentController:63` — `parent_id` بقاعدة `exists` وحدها بلا ربطٍ
 *     بهدفه: حقنُ ردٍّ في خيطِ سجلٍ لا يملك المستخدم رؤيته.
 *  ٣) `ContractActionsController:23` — التجديد يكتب **حالةَ العقد الأصل**
 *     بصلاحية «إضافة» وحدها: التفافٌ على صلاحية التعديل.
 *  ٤) `StockMove:38` — الحارسُ على `updating` وحده، فـ«مؤكدة» تُكتب مباشرةً
 *     **عند الإنشاء** فتُعلن رصيداً تحرّك ولم يتحرّك.
 *  ٥) `Sheet.php:103` — مرجعُ خليةٍ ملفَّق (`AAAAA1`) يُنتج حلقةَ ملءٍ
 *     بمئات التريليونات: استنزافُ ذاكرةٍ وسقوطُ العامل.
 */
class WritePathIntegrityRound6Test extends TestCase
{
    private function roleWith(array $matrix, array $flags = []): Role
    {
        $none = collect(array_keys(config('hub.modules')))
            ->mapWithKeys(fn ($m) => [$m => ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0]])->all();

        return Role::create(['name' => 'دور ' . \Illuminate\Support\Str::random(5),
            'scope' => 'all', 'flags' => $flags, 'matrix' => array_replace($none, $matrix)]);
    }

    private function userWith(Role $r, ?array $companies = null): User
    {
        return User::create(['name' => 'مستخدم', 'email' => 'w' . \Illuminate\Support\Str::random(6) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $r->id, 'status' => 'نشط',
            'companies' => $companies, 'password_changed_at' => now()]);
    }

    /* ── ١) حسمُ الموافقات منطَّق ── */

    public function test_an_approver_cannot_decide_a_request_outside_their_scope(): void
    {
        $this->seedCore();

        $a = Company::create(['name_ar' => 'شركة ألف']);
        $b = Company::create(['name_ar' => 'شركة باء']);
        $client = \App\Models\Client::create(['name' => 'عميلُ باء', 'company_id' => $b->id]);

        $ap = Approval::create(['mod' => 'clients', 'type' => 'تعديل', 'title' => 'تعديلُ عميل', 'record_id' => $client->id, 'op' => 'e',
            'payload' => ['name' => 'اسمٌ بدّله غريب'], 'status' => 'معلّق',
            'requested_by' => $this->owner->id, 'company_id' => $b->id]);

        $approver = $this->userWith(
            $this->roleWith(['clients' => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]], ['approve' => 1]),
            [$a->id]);

        $this->actingAs($approver)->post('/approvals/' . $ap->id . '/approve');

        $this->assertSame('عميلُ باء', $client->fresh()->name,
            'معتمِدُ شركةِ ألف نفّذ تعديلاً على سجلِ شركةِ باء — حسمٌ بلا تنطيق');
    }

    /* ── ٢) الردُّ يلتصق بخيطه ── */

    public function test_a_reply_cannot_be_injected_into_another_records_thread(): void
    {
        $this->seedCore();

        $mine = \App\Models\Client::create(['name' => 'عميلي']);
        $theirs = \App\Models\Client::create(['name' => 'عميلٌ آخر']);
        $parent = Comment::create(['module' => 'clients', 'record_id' => $theirs->id,
            'user_id' => $this->owner->id, 'body' => 'تعليقٌ في خيطٍ آخر', 'created_at' => now()]);

        $this->actingAs($this->owner)->post('/comments', [
            'module' => 'clients', 'record_id' => $mine->id,
            'parent_id' => $parent->id, 'body' => 'ردٌّ مدسوس',
        ]);

        $injected = Comment::where('body', 'ردٌّ مدسوس')->first();
        $this->assertTrue($injected === null || (string) $injected->parent_id !== (string) $parent->id,
            'رُبط الردُّ بأبٍ في خيطِ سجلٍ آخر — حقنٌ في خيطٍ لا يملك المستخدم رؤيته');
    }

    /* ── ٣) التجديد يشترط صلاحية التعديل ── */

    public function test_renewal_requires_edit_permission_not_just_add(): void
    {
        $this->seedCore();

        $c = Contract::create(['title' => 'عقدٌ ساري', 'type' => 'خدمات', 'status' => 'ساري',
            'date_end' => today()->addDays(10)->toDateString()]);

        $u = $this->userWith($this->roleWith(['contracts' => ['v' => 1, 'a' => 1, 'e' => 0, 'd' => 0]]));
        $this->actingAs($u)->post('/contract/' . $c->id . '/renew');

        $this->assertSame('ساري', $c->fresh()->status,
            'التجديدُ غيّر حالةَ العقد الأصل بصلاحية «إضافة» وحدها — التفافٌ على صلاحية التعديل');
    }

    /* ── ٤) «مؤكدة» لا تُكتب عند الإنشاء ── */

    public function test_a_move_cannot_be_born_confirmed(): void
    {
        $this->seedCore();

        $item = StockItem::create(['name' => 'صنف', 'sku' => 'SKU-9', 'qty' => 10, 'wh' => 'الرئيسي']);

        $this->actingAs($this->owner)->post('/m/stockmv', [
            'no' => 'IN-901', 'kind' => 'استلام', 'itemId' => $item->id, 'qty' => 5,
            'toWh' => 'الرئيسي', 'date' => now()->toDateString(), 'status' => 'مؤكدة',
        ]);

        $born = StockMove::where('doc_no', 'IN-901')->first();
        $this->assertTrue($born === null || (string) $born->status !== 'مؤكدة',
            'حركةٌ وُلدت «مؤكدة» — تُعلن رصيداً تحرّك ولم يتحرّك، والحارسُ على updating وحده');
        $this->assertSame(10.0, (float) $item->fresh()->qty, 'ولم يتحرّك الرصيد فعلاً');
    }

    /* ── ٥) قارئُ xlsx لا يُفجَّر بمرجع خلية ملفَّق ── */

    public function test_a_forged_cell_reference_does_not_explode_the_sheet_reader(): void
    {
        $xlsx = tempnam(sys_get_temp_dir(), 'lyn') . '.xlsx';

        $sheet = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>عنوان</t></is></c>'
            . '<c r="AAAAAAA1" t="inlineStr"><is><t>مفخّخة</t></is></c></row></sheetData></worksheet>';

        $zip = new \ZipArchive;
        $zip->open($xlsx, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst/>');
        $zip->close();

        try {
            $before = memory_get_usage(true);
            $rows = \App\Support\Sheet::read($xlsx, 'xlsx');
            $this->assertLessThan(64 * 1024 * 1024, memory_get_usage(true) - $before,
                'مرجعُ خليةٍ ملفَّق فجّر حلقةَ الملء — استنزافُ ذاكرة');
            $this->assertIsArray($rows);
        } finally {
            @unlink($xlsx);
        }
    }
}
