<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubmissionVersion extends Model
{
    use HasUlids;

    protected $table = 'submission_versions';

    protected $fillable = ['submission_id', 'revision', 'body', 'status', 'score', 'feedback', 'reviewer_id', 'recorded_at'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'score' => 'decimal:2', 'recorded_at' => 'datetime'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }
}
