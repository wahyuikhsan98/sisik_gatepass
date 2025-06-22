<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RequestDriver extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function ekspedisi()
    {
        return $this->belongsTo(Ekspedisi::class);
    }
}
