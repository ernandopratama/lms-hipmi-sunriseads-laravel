<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    use HasUlids;

    protected $fillable = [
        'school_name', 'education_level', 'city', 'province', 'pic_name', 'pic_title',
        'email', 'phone', 'estimated_students', 'program_of_interest', 'message', 'consent', 'status',
    ];

    protected function casts(): array
    {
        return ['consent' => 'boolean', 'estimated_students' => 'integer'];
    }
}
