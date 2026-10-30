<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UsersApiController extends Controller
{
    public function __construct(private ApiContext $context) {}

    private function scoped(Request $request, string $action = 'read'): array
    {
        $c = $this->context->resolve($request, 'master-data', $action);
        $q = DB::table('users as u')->where('u.organization_id', $c['organization'])
            ->where(function ($q) use ($c) {
                $q->whereIn('u.id', DB::table('user_roles')->where('school_id', $c['school'])->select('user_id'))
                    ->orWhereIn('u.id', DB::table('students')->where('school_id', $c['school'])->select('user_id'))
                    ->orWhereIn('u.id', DB::table('teachers')->where('school_id', $c['school'])->select('user_id'));
            });
        return [$c, $q];
    }

    private function present(object $u, string $school, bool $detail = false): array
    {
        $roles = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $u->id)->where(fn ($q) => $q->where('ur.school_id', $school)->orWhereNull('ur.school_id'))
            ->get(['ur.school_id', 'r.slug', 'r.name'])->map(fn ($r) => ['schoolId' => $r->school_id, 'role' => ['slug' => $r->slug, 'name' => $r->name]]);
        $result = ['id' => $u->id, 'email' => $u->email, 'username' => $u->username,
            'firstName' => $u->first_name, 'lastName' => $u->last_name, 'status' => $u->source_status ?? strtoupper($u->status),
            'emailVerified' => (bool) $u->email_verified, 'lastLoginAt' => $u->last_login_at,
            'createdAt' => $u->created_at, 'userRoles' => $roles];
        if ($detail) {
            $result += ['phone' => $u->phone, 'updatedAt' => $u->updated_at];
            $result['student'] = DB::table('students')->where('user_id', $u->id)->where('school_id', $school)->first(['id', 'school_id', 'student_number', 'full_name']);
            $result['teacher'] = DB::table('teachers')->where('user_id', $u->id)->where('school_id', $school)->first(['id', 'school_id', 'full_name']);
            $result['parent'] = null;
        }
        return $result;
    }

    public function index(Request $request)
    {
        [$c, $q] = $this->scoped($request);
        $d = $request->validate(['query' => 'nullable|string|max:120', 'status' => 'nullable|in:DRAFT,PENDING_ACTIVATION,ACTIVE,INACTIVE,SUSPENDED,LOCKED,DEACTIVATED,ARCHIVED',
            'page' => 'nullable|integer|min:1', 'pageSize' => 'nullable|integer|min:1|max:100']);
        if (! empty($d['query'])) {
            $needle = addcslashes(trim($d['query']), '%_\\');
            $q->where(fn ($q) => $q->where('u.email', 'like', '%'.$needle.'%')->orWhere('u.first_name', 'like', '%'.$needle.'%')
                ->orWhere('u.last_name', 'like', '%'.$needle.'%')->orWhere('u.username', 'like', '%'.$needle.'%'));
        }
        if (! empty($d['status'])) $q->where('u.source_status', $d['status']);
        $page = $d['page'] ?? 1; $size = $d['pageSize'] ?? 20;
        $total = (clone $q)->count();
        $items = $q->select('u.*')->orderBy('u.first_name')->orderBy('u.last_name')->offset(($page - 1) * $size)->limit($size)
            ->get()->map(fn ($u) => $this->present($u, $c['school']));
        return response()->json(compact('items', 'page', 'total') + ['pageSize' => $size, 'totalPages' => max(1, (int) ceil($total / $size))]);
    }

    public function show(Request $request, string $id)
    {
        [$c, $q] = $this->scoped($request);
        $user = $q->where('u.id', $id)->select('u.*')->first();
        abort_unless($user, 404, 'Pengguna tidak ditemukan');
        return response()->json($this->present($user, $c['school'], true));
    }

    public function updateStatus(Request $request, string $id)
    {
        $c = $this->context->resolve($request, 'master-data', 'update');
        $d = $request->validate(['status' => 'required|in:DRAFT,PENDING_ACTIVATION,ACTIVE,INACTIVE,SUSPENDED,LOCKED,DEACTIVATED,ARCHIVED']);
        abort_if($id === $c['user'] && ! in_array($d['status'], ['ACTIVE', 'PENDING_ACTIVATION'], true), 422, 'Anda tidak dapat menonaktifkan akun sendiri');
        [$scopeContext, $query] = $this->scoped($request, 'update');
        $user = $query->where('u.id', $id)->select('u.*')->first();
        abort_unless($user, 404, 'Pengguna tidak ditemukan');
        return DB::transaction(function () use ($user, $d) {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $revoke = ! in_array($d['status'], ['ACTIVE', 'PENDING_ACTIVATION'], true);
            $status = $d['status'] === 'ACTIVE' ? 'active' : ($d['status'] === 'PENDING_ACTIVATION' ? 'pending_activation' : 'inactive');
            $updates = ['status' => $status, 'source_status' => $d['status'], 'updated_at' => now()];
            if ($d['status'] === 'ACTIVE') $updates += ['locked_until' => null, 'failed_login_count' => 0];
            if ($revoke) $updates['session_version'] = DB::raw('session_version + 1');
            DB::table('users')->where('id', $user->id)->update($updates);
            if ($revoke) DB::table('user_sessions')->where('user_id', $user->id)->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoke_reason' => 'USER_STATUS_CHANGED']);
            return response()->json(['id' => $user->id, 'email' => $user->email, 'status' => $d['status'],
                'updatedAt' => DB::table('users')->where('id', $user->id)->value('updated_at')]);
        });
    }
}
