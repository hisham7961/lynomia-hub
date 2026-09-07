<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetCustody;
use App\Models\Company;
use App\Models\Station;
use App\Support\Custody;
use Tests\TestCase;

/**
 * **ترقيةُ الأصل: يُسنَد لمحطةٍ أو موظف، وحالتُه مقفلةٌ تُكتَب بانتقالٍ شرعيّ**
 * (Work OS · الطور F · WP-F.2 · §29–31 · §99 · critic C11).
 *
 * يمتدّ `LedgerAndStockIntegrityTest` (كلُّ كتابةٍ تُحرّك رصيداً أو تُقفل قيداً)
 * و`ColumnFitsItsWriterTest` (العمودُ يسع كاتبه) على قاعدة الأصل:
 *
 *   · الحالةُ حقلٌ **مقفل** — لا تُكتَب من النموذج العامّ ولا من سحب الكانبان، بل
 *     عبر `Custody::transition` وحدَها بانتقالٍ من خريطة الحالات؛ انتقالٌ غير مشروع
 *     يُرفَض ٤٢٢. الحالاتُ الإحدى عشرة تُقبَل، والخمسُ القديمةُ تبقى عاملةً (§86).
 *   · تصنيفُ مفتوح/مغلق صحيحٌ للقديم والجديد (`hub_is_closed`).
 *   · `station_id` عمودٌ **منفصلٌ** عن `holder_id` ومقفلٌ يُكتَب عبر
 *     `Custody::assignStation` وحدَها؛ الأصلُ يُسنَد لمحطةٍ **أو** موظفٍ بلا إجبار.
 *   · رمزُ الأصل (QR/code) ثابتٌ عبر النقل.
 */
class WorkOsAssetUpgradeTest extends TestCase
{
    /* ══════════ الحالة: مقفلةٌ تُكتَب عبر Custody وحدَها (C11) ══════════ */

    /** ١) الحالةُ لا تُكتَب من النموذج العامّ ولا من سحب الكانبان — مقفلة */
    public function test_status_is_locked_against_generic_crud_and_kanban(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'لابتوب مقفول الحالة', 'type' => 'لابتوب', 'status' => 'متاح']);

        // النموذجُ العامّ: PUT يحاول كتابة «صيانة» — يُتجاهَل (الحقلُ locked → ro)
        $this->actingAs($this->owner)->put('/m/assets/' . $a->id, [
            'name' => 'لابتوب مقفول الحالة', 'status' => 'صيانة',
        ])->assertRedirect();
        $this->assertSame('متاح', $a->fresh()->status,
            'الحالةُ كُتبت من النموذج العامّ — وهي حقلٌ مقفلٌ يُكتَب عبر Custody وحدَها');

        // سحبُ الكانبان: POST m.status — يُرفَض ٤٠٣ (الحقلُ غيرُ قابلٍ للكتابة بالسحب)
        $this->actingAs($this->owner)->post('/m/assets/' . $a->id . '/status', ['status' => 'تالف'])
            ->assertStatus(403);
        $this->assertSame('متاح', $a->fresh()->status,
            'سحبُ الكانبان كتب حالةً مقفلةً ملتفّاً على Custody');
    }

    /** ٢) المسارُ الصحيح: تغييرُ الحالة عبر Custody يُكتَب ويُسجَّل صفُّ حركة */
    public function test_status_changes_through_custody_and_writes_a_move_row(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'لابتوب الانتقال', 'type' => 'لابتوب', 'status' => 'متاح']);

        $this->actingAs($this->owner)->post('/custody/' . $a->id . '/status', [
            'status' => 'صيانة', 'at' => now()->toDateString(), 'note' => 'إصلاح المروحة',
        ])->assertRedirect();

        $this->assertSame('صيانة', $a->fresh()->status, 'المسارُ الشرعيّ لم يكتب الحالة');
        $this->assertSame(1, AssetCustody::where('asset_id', $a->id)->where('action', 'تغيير حالة')->count(),
            'تغييرُ الحالة لم يكتب صفَّ حركةٍ في السجل — حالةٌ تبدّلت بلا أثر');

        // تأكيدٌ على القيمتين لا على ترتيب المفاتيح: عمودُ JSON في MySQL 8 يعيد ترتيبَ
        // مفاتيح الكائن عند التخزين (الأقصرُ أولاً: to قبل from) بينما تحفظ SQLite/MariaDB
        // ترتيبَ الإدراج — فـ assertSame على المصفوفة كلِّها قرعةُ محرّكٍ (سقطت CI الـ480 بها).
        $row = AssetCustody::where('asset_id', $a->id)->where('action', 'تغيير حالة')->firstOrFail();
        $meta = (array) $row->meta;
        $this->assertSame('متاح', $meta['from'] ?? null, 'صفُّ الحركة لم يحفظ «من»');
        $this->assertSame('صيانة', $meta['to'] ?? null, 'صفُّ الحركة لم يحفظ «إلى»');
    }

    /** ٣) كلُّ حالةٍ جديدة تُبلَغ بانتقالٍ شرعيّ — لا حالةَ يتيمةٌ لا تُطال */
    public function test_every_new_status_is_reachable_through_valid_transitions(): void
    {
        $this->seedCore();
        $new = ['قيد الطلب', 'محجوز', 'خارج مؤقتاً', 'مفقود', 'مباع', 'مُعاد للمورد'];

        foreach ($new as $target) {
            $a = Asset::create(['name' => 'أصل ' . $target, 'type' => 'أخرى']);   // حالةٌ معدومة (لم تُصنَّف)
            $path = $this->pathTo($target);
            foreach ($path as $step) {
                Custody::transition($a->fresh(), $step, now()->toDateString());
            }
            $this->assertSame($target, $a->fresh()->status,
                "لم تُبلَغ الحالةُ الجديدة «{$target}» بمسارٍ شرعيّ: " . implode(' → ', $path));
        }
    }

    /** مسارٌ شرعيٌّ من الحالة المعدومة إلى كلِّ هدفٍ جديد (خريطةُ الانتقال) */
    private function pathTo(string $target): array
    {
        return match ($target) {
            'قيد الطلب'    => ['قيد الطلب'],                       // حالةُ دخول
            'محجوز'        => ['محجوز'],                           // حالةُ دخول
            'خارج مؤقتاً'   => ['متاح', 'خارج مؤقتاً'],
            'مفقود'        => ['متاح', 'مفقود'],
            'مباع'         => ['متاح', 'تالف', 'مباع'],
            'مُعاد للمورد'  => ['متاح', 'مُعاد للمورد'],
            default        => [$target],
        };
    }

    /** ٤) الحالاتُ الخمس القديمة تبقى عاملةً (aliases · توافق رجعيّ §86) */
    public function test_every_legacy_status_still_works_as_an_alias(): void
    {
        $this->seedCore();

        // كلُّ قيمةٍ قديمةٍ قيمةٌ معياريّةٌ بذاتها — تُحلُّ لنفسها
        foreach (Custody::LEGACY_STATUSES as $legacy) {
            $this->assertContains($legacy, Custody::STATUSES,
                "الحالةُ القديمة «{$legacy}» أُسقِطت من القائمة — كسرُ توافقٍ رجعيّ");
            $this->assertSame($legacy, Custody::canonicalStatus($legacy),
                "الحالةُ القديمة «{$legacy}» لم تُحلَّ لنفسها");
        }

        // ومُرادفاتٌ حرّةٌ قديمةٌ تُحلُّ لقيمها المعياريّة
        $this->assertSame('قيد الاستخدام', Custody::canonicalStatus('مستخدم'));
        $this->assertSame('مستبعد', Custody::canonicalStatus('خارج الخدمة'));

        // وتُكتَب فعلاً عبر المسار الشرعيّ: أصلٌ «قيد الاستخدام» → «صيانة» → «متاح»
        $a = Asset::create(['name' => 'أصلٌ قديمُ الحالة', 'type' => 'لابتوب', 'status' => 'قيد الاستخدام']);
        Custody::transition($a->fresh(), 'صيانة', now()->toDateString());
        $this->assertSame('صيانة', $a->fresh()->status);
        Custody::transition($a->fresh(), 'متاح', now()->toDateString());
        $this->assertSame('متاح', $a->fresh()->status, 'الحالاتُ القديمة لا تنتقل بينها — كسرُ توافق');
    }

    /** ٥) تصنيفُ مفتوح/مغلق صحيحٌ للقديم والجديد (C11) */
    public function test_open_closed_classification_for_old_and_new_states(): void
    {
        $this->seedCore();

        $closed = ['مستبعد', 'مباع', 'مُعاد للمورد'];                 // نهائيّة
        $open   = ['قيد الاستخدام', 'متاح', 'صيانة', 'تالف',          // القديمةُ المفتوحة (تالف مفتوحٌ عمداً)
                   'قيد الطلب', 'محجوز', 'خارج مؤقتاً', 'مفقود'];      // الجديدةُ المفتوحة

        foreach ($closed as $s) {
            $this->assertTrue(hub_is_closed($s), "الحالة «{$s}» يجب أن تُصنَّف مغلقة (نهائيّة)");
        }
        foreach ($open as $s) {
            $this->assertFalse(hub_is_closed($s), "الحالة «{$s}» يجب أن تبقى مفتوحة (بقي عملٌ)");
        }

        // كلُّ الحالات الإحدى عشرة مُصنَّفة (لا حالةٌ ضائعةٌ بين open/closed)
        $this->assertCount(11, Custody::STATUSES);
        $this->assertSame(array_merge($closed, $open), array_merge($closed, $open));  // تغطيةٌ = ١١
        $this->assertSame([], array_diff(Custody::STATUSES, array_merge($closed, $open)),
            'حالةٌ لم تُصنَّف open ولا closed');
    }

    /** ٦) انتقالٌ غير مشروع يُرفَض — لا رجوعَ من حالةٍ نهائيّة */
    public function test_an_invalid_transition_is_rejected(): void
    {
        $this->seedCore();

        // الخريطة: مستبعد نهائيّةٌ بلا مخرج
        $this->assertFalse(Custody::canTransition('مستبعد', 'قيد الاستخدام'),
            'خريطةُ الانتقال تسمح بالرجوع من حالةٍ نهائيّة');
        $this->assertFalse(Custody::canTransition('متاح', 'كلامٌ ليس حالة'),
            'قيمةٌ خارج القائمة قُبلت هدفاً');

        // عبر المتحكّم: أصلٌ «مستبعد» → «قيد الاستخدام» يُرفَض ٤٢٢، والحالةُ تبقى
        $a = Asset::create(['name' => 'أصلٌ مستبعد', 'type' => 'لابتوب', 'status' => 'مستبعد']);
        $this->actingAs($this->owner)->post('/custody/' . $a->id . '/status', [
            'status' => 'قيد الاستخدام', 'at' => now()->toDateString(),
        ])->assertStatus(422);
        $this->assertSame('مستبعد', $a->fresh()->status, 'انتقالٌ غير مشروع مرّ — الحالةُ تبدّلت');
        $this->assertSame(0, AssetCustody::where('asset_id', $a->id)->where('action', 'تغيير حالة')->count(),
            'صفُّ حركةٍ كُتب لانتقالٍ مرفوض — كتابةٌ داخل معاملةٍ لم تُنقَض');
    }

    /* ══════════ المحطة: منفصلةٌ عن الحائز، مقفلةٌ عبر Custody (§30) ══════════ */

    /** ٧) الأصلُ يُسنَد لمحطةٍ أو لموظفٍ — بلا إجبار، وكلاهما مستقلّ */
    public function test_asset_assigns_to_a_station_or_an_employee_without_forcing(): void
    {
        $this->seedCore();

        // (أ) إسنادُ محطةٍ فقط: station_id يُكتَب، holder_id يبقى فارغاً (لا إجبار)
        $onStation = Asset::create(['name' => 'شاشةٌ على مكتب', 'type' => 'شاشة', 'status' => 'متاح']);
        $station = Station::create(['facility' => 'المقرّ', 'desk' => 'D-12', 'type' => 'مكتب']);
        $this->actingAs($this->owner)->post('/custody/' . $onStation->id . '/station', [
            'station_id' => $station->id, 'at' => now()->toDateString(),
        ])->assertRedirect();

        $onStation->refresh();
        $this->assertSame($station->id, $onStation->station_id, 'الإسنادُ لم يكتب station_id');
        $this->assertNull($onStation->holder_id, 'إسنادُ محطةٍ فرض حائزاً — لا إجبار');
        $this->assertSame(1, AssetCustody::where('asset_id', $onStation->id)
            ->where('action', 'إسناد لمحطة')->whereNull('user_id')->count(),
            'صفُّ الإسناد للمحطة لم يُكتَب منفصلاً عن الحائز');

        // (ب) تسليمُ موظفٍ فقط: holder_id يُكتَب، station_id يبقى فارغاً
        $onPerson = Asset::create(['name' => 'لابتوبٌ بيد موظف', 'type' => 'لابتوب', 'status' => 'متاح']);
        $this->actingAs($this->owner)->post('/custody/' . $onPerson->id . '/handover', [
            'userId' => $this->employee->id, 'at' => now()->toDateString(),
        ])->assertRedirect();

        $onPerson->refresh();
        $this->assertSame($this->employee->id, $onPerson->holder_id);
        $this->assertNull($onPerson->station_id, 'تسليمُ موظفٍ فرض محطةً — لا إجبار');

        // (ج) وكلاهما معاً جائز: أصلٌ عند موظفٍ يُسنَد لمحطةٍ أيضاً — عمودان مستقلّان
        $this->actingAs($this->owner)->post('/custody/' . $onPerson->id . '/station', [
            'station_id' => $station->id, 'at' => now()->toDateString(),
        ])->assertRedirect();
        $onPerson->refresh();
        $this->assertSame($this->employee->id, $onPerson->holder_id, 'إسنادُ المحطة داس الحائز');
        $this->assertSame($station->id, $onPerson->station_id);
    }

    /** ٨) station_id مقفلٌ عن النموذج العامّ — يُكتَب عبر Custody وحدَها (نظير holder_id) */
    public function test_station_id_is_locked_against_the_generic_form(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'أصلٌ مقفولُ المحطة', 'type' => 'شاشة', 'status' => 'متاح']);
        $station = Station::create(['facility' => 'المقرّ', 'desk' => 'D-7', 'type' => 'مكتب']);

        $this->actingAs($this->owner)->put('/m/assets/' . $a->id, [
            'name' => 'أصلٌ مقفولُ المحطة', 'stationId' => $station->id,
        ])->assertRedirect();

        $this->assertNull($a->fresh()->station_id, 'station_id كُتب من النموذج العامّ — حقلٌ مقفل');
        $this->assertSame(0, AssetCustody::where('asset_id', $a->id)->count());
    }

    /** ٩) لا تُسنَد محطةُ شركةٍ أجنبية (تنطيق) */
    public function test_a_foreign_company_station_cannot_be_assigned(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
        $this->employee->update(['companies' => [$coA->id]]);
        session(['hub.company' => $coA->id]);

        $a = Asset::create(['name' => 'أصلُ ألف', 'type' => 'شاشة', 'status' => 'متاح', 'company_id' => $coA->id]);
        $foreign = Station::create(['facility' => 'مبنى باء', 'type' => 'مكتب', 'company_id' => $coB->id]);

        $this->actingAs($this->employee)->post('/custody/' . $a->id . '/station', [
            'station_id' => $foreign->id, 'at' => now()->toDateString(),
        ])->assertStatus(302)->assertSessionHasErrors('station_id');

        $this->assertNull($a->fresh()->station_id, 'أُسنِدت محطةُ شركةٍ أجنبية — تلوّثٌ عابرٌ للشركات');
    }

    /* ══════════ الرمز يثبت عبر النقل (§99) ══════════ */

    /** ١٠) رمزُ الأصل (QR/code) ثابتٌ عبر تغيير الحالة والمحطة */
    public function test_the_qr_code_survives_the_move(): void
    {
        $this->seedCore();
        $a = Asset::create(['name' => 'أصلٌ ذو رمز', 'type' => 'لابتوب', 'status' => 'متاح']);
        $code = $a->fresh()->code;
        $this->assertNotEmpty($code, 'الأصلُ وُلِد بلا رمز');

        $station = Station::create(['facility' => 'المقرّ', 'desk' => 'D-1', 'type' => 'مكتب']);
        $this->actingAs($this->owner)->post('/custody/' . $a->id . '/station',
            ['station_id' => $station->id, 'at' => now()->toDateString()]);
        Custody::transition($a->fresh(), 'صيانة', now()->toDateString());

        $this->assertSame($code, $a->fresh()->code, 'الرمزُ تبدّل بالنقل — الملصقُ المطبوعُ يفارق سجلَّه');

        // ومسحُ الملصق (/c/{code}) ما زال يحلّ للأصل نفسِه بعد النقل
        $this->actingAs($this->owner)->get('/c/' . $code)
            ->assertRedirect(route('m.show', ['assets', $a->id]));
    }
}
