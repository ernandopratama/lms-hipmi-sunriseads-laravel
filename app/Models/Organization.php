<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasUlids;

    protected $fillable = ['name'];

    public function schools(): HasMany
    {
        return $this->hasMany(School::class);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }
}
