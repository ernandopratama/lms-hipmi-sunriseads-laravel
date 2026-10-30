<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasUlids, Notifiable;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'email',
        'password',
        'organization_id',
        'school_id',
        'role',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'mfa_secret',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'plan_rank' => 'integer',
            'mfa_enabled' => 'boolean',
            'mfa_secret' => 'encrypted',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Whether the account may use the application: the legacy admin/teacher/student column, or any active
     * source role (parent, reviewer, support, identity-verifier, program-manager, principal, ...) assigned
     * to the user. Accounts with neither stay blocked.
     */
    public function canUseApplication(): bool
    {
        if (in_array($this->role, ['admin', 'teacher', 'student'], true)) return true;
        return \Illuminate\Support\Facades\DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $this->getKey())->where('r.is_active', true)->exists();
    }
}
