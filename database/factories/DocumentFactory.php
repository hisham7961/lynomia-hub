<?php

namespace Database\Factories;

use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Document> */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'name' => 'وثيقة ' . fake()->words(2, true),
            'cat' => 'تقارير',
            'audience' => 'internal',
        ];
    }
}
