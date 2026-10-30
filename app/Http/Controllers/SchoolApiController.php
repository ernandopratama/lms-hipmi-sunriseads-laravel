<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SchoolApiController extends Controller
{
    public function __construct(private ApiContext $context) {}

    private function present(object $s, bool $detail = false): array
    {
        $fields = ['id', 'organization_id', 'name', 'code', 'level', 'npsn', 'address', 'province', 'city', 'district', 'principal', 'pic_name', 'pic_phone', 'pic_email', 'logo', 'status', 'onboarding_step', 'is_active', 'joined_at', 'created_at', 'updated_at'];
        $result = [];
        foreach ($fields as $field) $result[Str::camel($field)] = $s->$field;
        $result['isActive'] = (bool) $s->is_active;
        $result['_count'] = [
            'students' => DB::table('students')->where('school_id', $s->id)->count(),
            'teachers' => DB::table('teachers')->where('school_id', $s->id)->count(),
            'classes' => DB::table('classes')->where('school_id', $s->id)->count(),
        ];
        if ($detail) {
            $result['classes'] = DB::table('classes')->where('school_id', $s->id)->get(['id', 'name', 'code']);
            $result['departments'] = DB::table('departments')->where('school_id', $s->id)->get(['id', 'name', 'code']);
        }
        return $result;
    }

    private function scope(Request $request, string $action): array
    {
        $c = $this->context->resolve($request, 'master-data', $action);
        abort_if($c['student'], 403, 'Akses administrasi sekolah diperlukan');
        return $c;
    }

    public function index(Request $request)
    {
        $c = $this->scope($request, 'read');
        return response()->json(DB::table('schools')->where('organization_id', $c['organization'])->orderBy('name')->get()->map(fn ($s) => $this->present($s)));
    }

    public function show(Request $request, string $id)
    {
        $c = $this->scope($request, 'read');
        $school = DB::table('schools')->where('id', $id)->where('organization_id', $c['organization'])->first();
        abort_unless($school, 404, 'Sekolah tidak ditemukan');
        return response()->json($this->present($school, true));
    }

    public function store(Request $request)
    {
        $c = $this->scope($request, 'create');
        $data = $request->validate(['name' => 'required|string|max:255', 'code' => 'required|string|max:255',
            'level' => 'required|string|max:255', 'npsn' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255', 'province' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255', 'district' => 'nullable|string|max:255',
            'principal' => 'nullable|string|max:255', 'picName' => 'nullable|string|max:255',
            'picPhone' => 'nullable|string|max:255', 'picEmail' => 'nullable|email|max:255']);
        abort_if(DB::table('schools')->where('organization_id', $c['organization'])->where('code', $data['code'])->exists(), 422, 'Kode sekolah sudah digunakan');
        $id = (string) Str::ulid();
        $row = ['id' => $id, 'organization_id' => $c['organization'], 'status' => 'DRAFT',
            'created_at' => now(), 'updated_at' => now()];
        foreach ($data as $field => $value) $row[Str::snake($field)] = $value;
        DB::table('schools')->insert($row);
        return response()->json($this->present(DB::table('schools')->where('id', $id)->first()), 201);
    }

    public function update(Request $request, string $id)
    {
        $c = $this->scope($request, 'update');
        $school = DB::table('schools')->where('id', $id)->where('organization_id', $c['organization'])->first();
        abort_unless($school, 404, 'Sekolah tidak ditemukan');
        $data = $request->validate(['name' => 'sometimes|string|max:255', 'address' => 'nullable|string|max:255',
            'province' => 'nullable|string|max:255', 'city' => 'nullable|string|max:255',
            'principal' => 'nullable|string|max:255', 'picName' => 'nullable|string|max:255',
            'picPhone' => 'nullable|string|max:255', 'picEmail' => 'nullable|email|max:255',
            'status' => 'sometimes|in:DRAFT,PENDING,IN_PROGRESS,ACTIVE,SUSPENDED,INACTIVE']);
        $changes = [];
        foreach ($data as $field => $value) $changes[Str::snake($field)] = $value;
        if ($changes) DB::table('schools')->where('id', $id)->where('organization_id', $c['organization'])->update($changes + ['updated_at' => now()]);
        return response()->json($this->present(DB::table('schools')->where('id', $id)->first()));
    }
}
