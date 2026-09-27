<?php

namespace Database\Factories;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<JournalLine> */
class JournalLineFactory extends Factory
{
    protected $model = JournalLine::class;

    public function definition(): array
    {
        return [
            'entry_id' => JournalEntry::factory(),
            'acc_id' => LedgerAccount::factory(),
            'debit' => 100,
            'credit' => 0,
        ];
    }

    public function credit(float $amount = 100): static
    {
        return $this->state(fn () => ['debit' => 0, 'credit' => $amount]);
    }
}
