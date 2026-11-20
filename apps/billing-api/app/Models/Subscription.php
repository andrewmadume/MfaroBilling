<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['gateway_token'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'period_start' => 'immutable_datetime', 'period_end' => 'immutable_datetime', 'trial_ends_at' => 'immutable_datetime', 'grace_ends_at' => 'immutable_datetime', 'cancel_at_period_end' => 'boolean'];
    }
}
