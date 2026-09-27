<?php

namespace Database\Factories;

use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<JournalEntry> */
class JournalEntryFactory extends Factory
{
    protected $model = JournalEntry::class;

    public function definition(): array
    {
        return [
            // مسودةٌ لا «مرحّل»: الترحيلُ يشترط سطوراً موزونة (`JournalEntry::booted`)
            'doc_no' => 'JE-F-' . fake()->unique()->numerify('######'),
            'date' => now()->toDateString(),
            'state' => 'مسودة',
        ];
    }
}
