<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClassApiController extends Controller
{
    public function __construct(private ApiContext $context) {}

    private function present(object $row): array
    {
        return ['id' => $row->id, 'schoolId' => $row->school_id, 'departmentId' => $row->department_id,
            'name' => $row->name, 'code' => $row->code, 'grade' => $row->grade,
            'capacity' => $row->capacity, 'isActive' => (bool) $row->is_active,
            'createdAt' => $row->created_at, 'updatedAt' => $row->updated_at];
    }

    public function index(Request $request)
    {
        $c = $this->context->resolve($request, 'master-data', 'read');
        return response()->json(DB::table('classes')->where('school_id', $c['school'])->get()->map(fn ($row) => $this->present($row)));
    }

    private function validateData(Request $request, bool $create): array
    {
        return $request->validate([
            'name' => ($create ? 'required' : 'sometimes').'|string|max:255',
            'code' => ($create ? 'required' : 'sometimes').'|string|max:255',
            'grade' => 'nullable|integer|min:1', 'capacity' => 'nullable|integer|min:0',
            'departmentId' => 'nullable|string|max:191',
        ]);
    }

    private function department(?string $id, string $school): void
    {
        if ($id !== null) abort_unless(DB::table('departments')->where('id', $id)->where('school_id', $school)->exists(), 422, 'Jurusan tidak tersedia di sekolah ini');
    }

    public function store(Request $request)
    {
        $c = $this->context->resolve($request, 'master-data', 'create');
        $d = $this->validateData($request, true);
        $this->department($d['departmentId'] ?? null, $c['school']);
        abort_if(DB::table('classes')->where('school_id', $c['school'])->where('code', $d['code'])->exists(), 422, 'Kode kelas sudah digunakan');
        $id = (string) Str::ulid();
        DB::table('classes')->insert(['id' => $id, 'school_id' => $c['school'], 'name' => $d['name'], 'code' => $d['code'],
            'department_id' => $d['departmentId'] ?? null, 'grade' => $d['grade'] ?? null, 'capacity' => $d['capacity'] ?? null,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json($this->present(DB::table('classes')->where('id', $id)->first()), 201);
    }

    public function update(Request $request, string $id)
    {
        $c = $this->context->resolve($request, 'master-data', 'update');
        $target = DB::table('classes')->where('id', $id)->where('school_id', $c['school'])->first();
        abort_unless($target, 404, 'Kelas tidak ditemukan');
        $d = $this->validateData($request, false);
        if (array_key_exists('departmentId', $d)) $this->department($d['departmentId'], $c['school']);
        if (isset($d['code'])) abort_if(DB::table('classes')->where('school_id', $c['school'])->where('code', $d['code'])->where('id', '!=', $id)->exists(), 422, 'Kode kelas sudah digunakan');
        $values = [];
        foreach (['name', 'code', 'grade', 'capacity', 'departmentId'] as $field) if (array_key_exists($field, $d)) $values[Str::snake($field)] = $d[$field];
        if ($values) DB::table('classes')->where('id', $id)->where('school_id', $c['school'])->update($values + ['updated_at' => now()]);
        return response()->json($this->present(DB::table('classes')->where('id', $id)->first()));
    }

    public function archive(Request $request, string $id)
    {
        $c = $this->context->resolve($request, 'master-data', 'delete');
        abort_unless(DB::table('classes')->where('id', $id)->where('school_id', $c['school'])->exists(), 404, 'Kelas tidak ditemukan');
        DB::table('classes')->where('id', $id)->where('school_id', $c['school'])->update(['is_active' => false, 'updated_at' => now()]);
        return response()->json($this->present(DB::table('classes')->where('id', $id)->first()));
    }
}
