<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\FinDocument;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ربط المستند المالي بالعميل: كان مطابقة نصية على اسم الطرف — يكسرها تغيير الاسم
 * ويخلط عميلين متشابهين. صار مرجعاً حقيقياً، والمطابقة النصية احتياطٌ لما لم يُرحَّل.
 */
class ClientInvoiceRefTest extends TestCase
{
    public function test_journey_prefers_the_explicit_reference(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل مرجعي']);

        FinDocument::create(['doc_no' => 'INV-REF', 'kind' => 'فاتورة', 'client_id' => $c->id,
            'partner' => 'اسمٌ مختلف تماماً', 'total' => 100, 'paid' => 100,
            'date' => now()->toDateString(), 'state' => 'مدفوعة']);

        $r = $this->actingAs($this->owner)->get('/journey/' . $c->id)->assertOk();
        $r->assertSee('INV-REF');

        $fin = $r->original->getData()['fin'];
        $this->assertSame(1, $fin['count']);
        $this->assertSame(1, $fin['linked']);
        $this->assertSame(0, $fin['byName']);
    }

    /** تغيير اسم العميل كان يُفقده فواتيره — المرجع يصمد */
    public function test_renaming_the_client_no_longer_loses_linked_invoices(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'الاسم القديم']);
        FinDocument::create(['doc_no' => 'INV-KEEP', 'kind' => 'فاتورة', 'client_id' => $c->id,
            'partner' => 'الاسم القديم', 'total' => 50, 'date' => now()->toDateString()]);

        $c->update(['name' => 'الاسم الجديد']);

        $this->actingAs($this->owner)->get('/journey/' . $c->id)
            ->assertOk()->assertSee('INV-KEEP');
    }

    /** المستند غير المرحَّل يبقى ظاهراً بالمطابقة النصية، ومعدوداً على أنه كذلك */
    public function test_unmigrated_documents_still_match_by_name(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل نصي']);
        FinDocument::create(['doc_no' => 'INV-NAME', 'kind' => 'فاتورة', 'partner' => 'عميل نصي',
            'total' => 70, 'date' => now()->toDateString()]);

        $r = $this->actingAs($this->owner)->get('/journey/' . $c->id)->assertOk();
        $r->assertSee('INV-NAME');

        $fin = $r->original->getData()['fin'];
        $this->assertSame(0, $fin['linked']);
        $this->assertSame(1, $fin['byName']);
    }

    /** لا ازدواج: مستند مرتبط واسمه مطابق يُحسب مرة واحدة */
    public function test_a_linked_document_is_not_counted_twice(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل مزدوج']);
        FinDocument::create(['doc_no' => 'INV-ONE', 'kind' => 'فاتورة', 'client_id' => $c->id,
            'partner' => 'عميل مزدوج', 'total' => 40, 'date' => now()->toDateString()]);

        $fin = $this->actingAs($this->owner)->get('/journey/' . $c->id)
            ->assertOk()->original->getData()['fin'];

        $this->assertSame(1, $fin['count']);
        $this->assertSame(40.0, $fin['total']);
    }

    /** مستند عميل آخر لا يتسلل عبر أي من الطريقين */
    public function test_other_clients_documents_never_appear(): void
    {
        $this->seedCore();
        $mine = Client::create(['name' => 'عميلي']);
        $other = Client::create(['name' => 'عميل آخر']);

        FinDocument::create(['doc_no' => 'INV-OTHER-REF', 'kind' => 'فاتورة',
            'client_id' => $other->id, 'partner' => 'عميلي', 'total' => 900,
            'date' => now()->toDateString()]);
        FinDocument::create(['doc_no' => 'INV-OTHER-NAME', 'kind' => 'فاتورة',
            'partner' => 'عميل آخر', 'total' => 900, 'date' => now()->toDateString()]);

        $this->actingAs($this->owner)->get('/journey/' . $mine->id)
            ->assertOk()
            ->assertDontSee('INV-OTHER-REF')      // مرجعه لعميل آخر رغم تطابق الاسم
            ->assertDontSee('INV-OTHER-NAME');
    }

    /**
     * الترحيل محافظ: الاسم الملتبس لا يُخمَّن.
     * يُحاكى منطق الهجرة على بيانات مزروعة — عميلان بالاسم نفسه ⇒ لا ترحيل.
     */
    public function test_migration_never_guesses_an_ambiguous_name(): void
    {
        $this->seedCore();
        Client::create(['name' => 'اسم مكرر']);
        Client::create(['name' => 'اسم مكرر']);
        $solo = Client::create(['name' => 'اسم فريد']);

        FinDocument::create(['doc_no' => 'INV-AMB', 'kind' => 'فاتورة', 'partner' => 'اسم مكرر',
            'total' => 10, 'date' => now()->toDateString()]);
        FinDocument::create(['doc_no' => 'INV-SOLO', 'kind' => 'فاتورة', 'partner' => 'اسم فريد',
            'total' => 10, 'date' => now()->toDateString()]);
        DB::table('fin_documents')->update(['client_id' => null]);

        // نفس استعلام الهجرة
        $unique = DB::table('clients')->whereNull('deleted_at')
            ->select('name', DB::raw('COUNT(*) as n'), DB::raw('MIN(id) as id'))
            ->groupBy('name')->havingRaw('COUNT(*) = 1')->get();
        foreach ($unique as $u) {
            DB::table('fin_documents')->whereNull('client_id')
                ->where('partner', $u->name)->update(['client_id' => $u->id]);
        }

        $this->assertNull(DB::table('fin_documents')->where('doc_no', 'INV-AMB')->value('client_id'),
            'الاسم الملتبس رُحِّل تخميناً');
        $this->assertSame($solo->id,
            DB::table('fin_documents')->where('doc_no', 'INV-SOLO')->value('client_id'));
    }
}
