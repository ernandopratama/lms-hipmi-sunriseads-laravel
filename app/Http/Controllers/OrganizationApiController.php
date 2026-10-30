<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrganizationApiController extends Controller
{
    public function __construct(private ApiContext $context) {}

    private function present(object $org, bool $detail = false): array
    {
        $result = ['id' => $org->id, 'name' => $org->name, 'code' => $org->code,
            'description' => $org->description, 'logo' => $org->logo, 'isActive' => (bool) $org->is_active,
            'createdAt' => $org->created_at, 'updatedAt' => $org->updated_at,
            '_count' => ['schools' => DB::table('schools')->where('organization_id', $org->id)->count(),
                'users' => DB::table('users')->where('organization_id', $org->id)->count()]];
        if ($detail) $result['schools'] = DB::table('schools')->where('organization_id', $org->id)->get(['id', 'name', 'status']);
        return $result;
    }

    public function index(Request $request)
    {
        $c = $this->context->resolve($request, 'master-data', 'read');
        return response()->json(DB::table('organizations')->where('id', $c['organization'])->get()->map(fn ($o) => $this->present($o)));
    }

    public function show(Request $request, string $id)
    {
        $c = $this->context->resolve($request, 'master-data', 'read');
        $org = DB::table('organizations')->where('id', $id)->where('id', $c['organization'])->first();
        abort_unless($org, 404, 'Organisasi tidak ditemukan');
        return response()->json($this->present($org, true));
    }

    public function store(Request $request)
    {
        $this->context->resolve($request, 'master-data', 'create');
        $d = $request->validate(['name' => 'required|string|max:255', 'code' => 'required|string|max:191',
            'description' => 'sometimes|nullable|string|max:10000', 'logo' => 'sometimes|nullable|string|max:2048']);
        abort_if(DB::table('organizations')->where('code', $d['code'])->exists(), 409, 'Kode organisasi sudah digunakan');
        $id = (string) Str::ulid();
        DB::table('organizations')->insert(['id' => $id, 'name' => $d['name'], 'code' => $d['code'],
            'description' => $d['description'] ?? null, 'logo' => $d['logo'] ?? null,
            'created_at' => now(), 'updated_at' => now()]);
        return response()->json($this->present(DB::table('organizations')->where('id', $id)->first()), 201);
    }

    public function update(Request $request, string $id)
    {
        $c = $this->context->resolve($request, 'master-data', 'update');
        abort_unless($id === $c['organization'] && DB::table('organizations')->where('id', $id)->exists(), 404, 'Organisasi tidak ditemukan');
        $data = $request->validate(['name' => 'sometimes|string|max:255', 'description' => 'nullable|string|max:10000',
            'logo' => 'nullable|string|max:2048', 'isActive' => 'sometimes|boolean']);
        $updates = [];
        foreach ($data as $field => $value) $updates[\Illuminate\Support\Str::snake($field)] = $value;
        if ($updates) DB::table('organizations')->where('id', $id)->update($updates + ['updated_at' => now()]);
        return response()->json($this->present(DB::table('organizations')->where('id', $id)->first()));
    }
}
