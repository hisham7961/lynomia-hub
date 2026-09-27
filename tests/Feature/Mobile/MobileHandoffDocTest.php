<?php

namespace Tests\Feature\Mobile;

use App\Support\Mobile\MobileHandoffDoc;
use Tests\TestCase;

/**
 * **خريطةُ النقاط في وثيقة التسليم لا تنحرف** (خطّةُ التطبيق · 2.5): كانت تقول ٧٥ والمسجَّلُ ٩٥.
 * القسمُ مولَّدٌ من المسارات الحيّة؛ وهذا الحارسُ يُسقط الحزمةَ إن أُضيف مسارٌ ولم يُعَد التوليد.
 */
class MobileHandoffDocTest extends TestCase
{
    public function test_handoff_route_map_matches_live_routes(): void
    {
        $this->seedCore();
        $doc = (string) file_get_contents(base_path(MobileHandoffDoc::PATH));
        $synced = MobileHandoffDoc::sync($doc);

        $this->assertNotNull($synced, 'علامتا القسم المولَّد غائبتان عن الوثيقة');
        $this->assertSame($synced, $doc, 'خريطةُ النقاط منحرفة — شغّل: php artisan hub:mobile-handoff --write');
        $this->assertStringContainsString('`GET attendance/today`', $doc);
    }
}
