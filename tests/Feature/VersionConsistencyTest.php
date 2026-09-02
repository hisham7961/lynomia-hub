<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * تعريفُ «الانتهاء» يُحرَس لا يُذكَر (QE-08): رقمُ النسخة واحدٌ في `VERSION` وسطرِ README الأول
 * وما تقرؤه الواجهة — انحرافُ أحدها كان يمرّ صامتاً حتى يقرأه أحدٌ في الذيل.
 */
class VersionConsistencyTest extends TestCase
{
    public function test_version_file_readme_and_config_agree(): void
    {
        $version = trim((string) file_get_contents(base_path('VERSION')));
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version, 'VERSION ليست semver');

        $first = trim((string) strtok((string) file_get_contents(base_path('README.md')), "\n"));
        $this->assertSame("# Lynomia Business Hub — v{$version}", $first, 'سطرُ README الأول لا يطابق VERSION');

        $this->assertSame($version, (string) config('hub.version'), 'config(hub.version) لا يقرأ VERSION');

        // ومدخلُ سجل الإصدارات لهذه النسخة موجود
        $this->assertStringContainsString("v{$version}", (string) file_get_contents(base_path('README.md')), 'لا مدخلَ في سجل الإصدارات للنسخة الحالية');
    }
}
