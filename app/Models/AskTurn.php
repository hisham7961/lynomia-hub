<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** سؤالٌ وجوابُه في خيط — مشفَّران، ومصادرُ الجواب بلا قيم (`App\Support\Ai\Ask\AskMemory`). */
class AskTurn extends Model
{
    protected $table = 'ask_turns';

    protected $guarded = ['id'];

    protected $casts = [
        'question' => 'encrypted',
        'answer' => 'encrypted',
        'ok' => 'boolean',
        'sources' => 'array',
    ];
}
