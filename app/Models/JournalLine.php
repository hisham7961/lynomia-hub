<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** سطر قيد مزدوج: مدين أو دائن على حسابٍ من الدليل، بمركز تكلفة اختياري */
class JournalLine extends Model
{
    use HasUuid;

    protected $table = 'journal_lines';
    protected $guarded = ['id'];

    protected $casts = [
        'debit' => 'float',
        'credit' => 'float',
    ];

    public function account()
    {
        return $this->belongsTo(LedgerAccount::class, 'acc_id');
    }

    public function entry()
    {
        return $this->belongsTo(JournalEntry::class, 'entry_id');
    }
}
