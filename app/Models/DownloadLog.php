<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DownloadLog extends Model
{
    protected $table = 'download_log';
    protected $guarded = [];
    public $timestamps = false;
    // لا صبّات: أعمدة الجدول (attachment_id, user_id, ip, device, created_at)
    // كلها بسيطة — الصبّات المنسوخة السابقة كانت لأعمدة لا وجود لها

}
