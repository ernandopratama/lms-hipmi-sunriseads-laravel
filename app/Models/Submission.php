<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Submission extends Model
{
    use HasUlids;

    protected $fillable = ['assignment_id', 'user_id', 'body', 'status', 'revision', 'score', 'feedback', 'submitted_at', 'reviewed_at', 'reviewer_id'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'score' => 'decimal:2', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SubmissionVersion::class)->orderByDesc('revision');
    }
}
