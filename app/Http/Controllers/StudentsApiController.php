<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class StudentsApiController extends Controller
{
    public function __construct(private ApiContext $context) {}

    private function present(object $s, bool $detail = false): array
    {
        $result = [];
        foreach (['id', 'user_id', 'school_id', 'class_id', 'student_number', 'nisn', 'full_name', 'status', 'activated_at', 'birth_place', 'birth_date', 'gender', 'address', 'phone', 'social_media', 'affiliate_account', 'photo', 'created_at', 'updated_at'] as $field) {
            $result[Str::camel($field)] = $s->$field;
        }
        $user = DB::table('users')->where('id', $s->user_id)->first();
        $result['user'] = $user ? ['email' => $user->email, 'status' => $user->source_status ?? strtoupper($user->status)] : null;
        $class = $s->class_id ? DB::table('classes')->where('id', $s->class_id)->where('school_id', $s->school_id)->first() : null;
        $result['class'] = $class ? ['name' => $class->name, 'code' => $class->code] : null;
        if ($detail) {
            if ($user) $result['user'] += ['id' => $user->id, 'username' => $user->username,
                'firstName' => $user->first_name, 'lastName' => $user->last_name, 'phone' => $user->phone,
                'emailVerified' => (bool) $user->email_verified, 'lastLoginAt' => $user->last_login_at];
            // The source returns the complete class and school records on the detail view.
            $result['class'] = $class ? $this->camelRow($class) : null;
            $school = DB::table('schools')->where('id', $s->school_id)->first();
            $result['school'] = $school ? $this->camelRow($school) : null;
            $result['studentParents'] = [];
        }
        return $result;
    }

    private function camelRow(object $row): array
    {
        $out = [];
        foreach ((array) $row as $column => $value) $out[Str::camel($column)] = $value;
        return $out;
    }

    private function scope(Request $request): array
    {
        $c = $this->context->resolve($request, 'master-data', 'read');
        abort_if($c['student'], 403, 'Akses administrasi siswa diperlukan');
        return [$c, DB::table('students')->where('school_id', $c['school'])];
    }

    public function index(Request $request)
    {
        [$c, $query] = $this->scope($request);
        $d = $request->validate(['skip' => 'nullable|integer|min:0', 'take' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string|max:120', 'classId' => 'nullable|string|max:191', 'status' => 'nullable|in:DRAFT,PENDING_ACTIVATION,ACTIVE,INACTIVE,TRANSFERRED,GRADUATED,SUSPENDED']);
        if (! empty($d['search'])) $query->where(fn ($q) => $q->where('full_name', 'like', '%'.$d['search'].'%')
            ->orWhere('student_number', 'like', '%'.$d['search'].'%'));
        if (! empty($d['classId'])) $query->where('class_id', $d['classId']);
        if (! empty($d['status'])) $query->where('status', $d['status']);
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->offset($d['skip'] ?? 0)->limit($d['take'] ?? 50)->get();
        return response()->json(['data' => $rows->map(fn ($row) => $this->present($row)), 'total' => $total]);
    }

    public function show(Request $request, string $id)
    {
        [$c, $query] = $this->scope($request);
        $student = $query->where('id', $id)->first();
        abort_unless($student, 404, 'Siswa tidak ditemukan');
        return response()->json($this->present($student, true));
    }

    private function payload(Request $request, bool $create): array
    {
        $required = $create ? 'required' : 'sometimes';
        return $request->validate([
            'userId' => $create ? 'required|string|max:191' : 'prohibited',
            'studentNumber' => $required.'|string|max:50', 'fullName' => $required.'|string|max:200',
            'nisn' => 'sometimes|nullable|regex:/^\d{10}$/', 'classId' => 'nullable|string|max:191',
            'birthPlace' => 'nullable|string|max:120', 'birthDate' => 'nullable|date',
            'gender' => 'nullable|string|max:30', 'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:30', 'socialMedia' => 'nullable|string|max:255',
            'affiliateAccount' => 'nullable|string|max:255', 'photo' => 'nullable|string|max:500',
        ]);
    }

    private function assertClass(?string $id, string $school): void
    {
        if ($id !== null) abort_unless(DB::table('classes')->where('id', $id)->where('school_id', $school)
            ->where('is_active', true)->exists(), 422, 'Kelas tidak tersedia di sekolah ini');
    }

    private function identifier(string $studentId, string $nisn): void
    {
        $blind = hash_hmac('sha256', 'NISN:'.strtoupper(preg_replace('/\s+/', '', $nisn)), config('app.key'));
        abort_if(DB::table('student_identifiers')->where('type', 'NISN')->where('blind_index', $blind)
            ->where('student_id', '!=', $studentId)->exists(), 422, 'NISN sudah digunakan');
        $row = ['value_encrypted' => Crypt::encryptString($nisn), 'blind_index' => $blind,
            'last4' => substr($nisn, -4), 'status' => 'NEEDS_REVIEW', 'updated_at' => now()];
        $existing = DB::table('student_identifiers')->where('student_id', $studentId)->where('type', 'NISN')->first();
        if ($existing) DB::table('student_identifiers')->where('id', $existing->id)->update($row);
        else DB::table('student_identifiers')->insert($row + ['id' => (string) Str::ulid(),
            'student_id' => $studentId, 'type' => 'NISN', 'created_at' => now()]);
    }

    public function store(Request $request)
    {
        $c = $this->context->resolve($request, 'master-data', 'create');
        abort_if($c['student'], 403);
        $d = $this->payload($request, true);
        abort_unless(DB::table('schools')->where('id', $c['school'])->where('is_active', true)->exists(), 404);
        abort_unless(DB::table('users')->where('id', $d['userId'])->where('organization_id', $c['organization'])->exists(), 404, 'Pengguna tidak ditemukan');
        abort_if(DB::table('students')->where('user_id', $d['userId'])->exists(), 422, 'Pengguna sudah memiliki profil siswa');
        $this->assertClass($d['classId'] ?? null, $c['school']);
        abort_if(DB::table('students')->where('school_id', $c['school'])->where('student_number', $d['studentNumber'])->exists(), 422, 'Nomor siswa sudah digunakan');
        return DB::transaction(function () use ($c, $d) {
            $id = (string) Str::ulid();
            $row = ['id' => $id, 'school_id' => $c['school'], 'user_id' => $d['userId'],
                'status' => 'DRAFT', 'created_at' => now(), 'updated_at' => now()];
            foreach ($d as $key => $value) if (! in_array($key, ['nisn', 'userId'], true)) $row[Str::snake($key)] = $value;
            DB::table('students')->insert($row);
            if (! empty($d['nisn'])) $this->identifier($id, $d['nisn']);
            return response()->json($this->present(DB::table('students')->where('id', $id)->first()), 201);
        });
    }

    public function update(Request $request, string $id)
    {
        $c = $this->context->resolve($request, 'master-data', 'update');
        abort_if($c['student'], 403);
        $d = $this->payload($request, false);
        return DB::transaction(function () use ($c, $d, $id) {
            $s = DB::table('students')->where('id', $id)->where('school_id', $c['school'])->lockForUpdate()->first();
            abort_unless($s, 404, 'Siswa tidak ditemukan');
            if (array_key_exists('classId', $d)) $this->assertClass($d['classId'], $c['school']);
            if (isset($d['studentNumber'])) abort_if(DB::table('students')->where('school_id', $c['school'])
                ->where('student_number', $d['studentNumber'])->where('id', '!=', $id)->exists(), 422, 'Nomor siswa sudah digunakan');
            $changes = [];
            foreach ($d as $key => $value) if ($key !== 'nisn') $changes[Str::snake($key)] = $value;
            if ($changes) DB::table('students')->where('id', $id)->update($changes + ['updated_at' => now()]);
            if (! empty($d['nisn'])) $this->identifier($id, $d['nisn']);
            return response()->json($this->present(DB::table('students')->where('id', $id)->first()));
        });
    }

    public function archive(Request $request, string $id)
    {
        $c = $this->context->resolve($request, 'master-data', 'delete');
        abort_if($c['student'], 403);
        return DB::transaction(function () use ($c, $id) {
            $s = DB::table('students')->where('id', $id)->where('school_id', $c['school'])->lockForUpdate()->first();
            abort_unless($s, 404, 'Siswa tidak ditemukan');
            DB::table('students')->where('id', $id)->update(['status' => 'INACTIVE', 'updated_at' => now()]);
            DB::table('class_enrollments')->where('student_id', $id)->where('school_id', $c['school'])
                ->where('status', 'ACTIVE')->update(['status' => 'ARCHIVED', 'effective_until' => now(), 'updated_at' => now()]);
            DB::table('users')->where('id', $s->user_id)->where('organization_id', $c['organization'])
                ->update(['status' => 'inactive', 'source_status' => 'ARCHIVED', 'session_version' => DB::raw('session_version + 1'), 'updated_at' => now()]);
            return response()->json($this->present(DB::table('students')->where('id', $id)->first()));
        });
    }
}
