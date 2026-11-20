<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['features' => 'array', 'limits' => 'array'];
    }
}
