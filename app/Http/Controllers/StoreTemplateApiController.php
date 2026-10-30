<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use App\Support\StudentDashboardAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StoreTemplateApiController extends Controller
{
    public function __construct(private ApiContext $context, private StudentDashboardAccess $access) {}

    private function scope(Request $request, string $action): array
    {
        $c = $this->context->resolve($request, 'assignments', $action);
        $plan = $this->access->resolve($c['organization'], $c['school'], $c['user'], $c['student']?->id);
        abort_unless($plan && $this->access->enabled($plan, 'student_store.submit'), 403,
            'Student Store tidak termasuk dalam paket aktif');
        return $c;
    }

    private function present(object $r): array
    {
        $items = DB::table('store_checklist_items')->where('template_id', $r->id)->orderBy('position')->get()
            ->map(fn ($i) => ['id' => $i->id, 'templateId' => $i->template_id, 'code' => $i->code,
                'label' => $i->label, 'description' => $i->description, 'evidenceType' => $i->evidence_type,
                'weight' => $i->weight, 'isRequired' => (bool) $i->is_required, 'position' => $i->position]);
        return ['id' => $r->id, 'organizationId' => $r->organization_id, 'schoolId' => $r->school_id,
            'name' => $r->name, 'platform' => $r->platform, 'minimumProducts' => $r->minimum_products,
            'isActive' => (bool) $r->is_active, 'createdById' => $r->created_by_id,
            'createdAt' => $r->created_at, 'updatedAt' => $r->updated_at, 'items' => $items];
    }

    public function index(Request $request)
    {
        $c = $this->scope($request, 'read');
        return response()->json(DB::table('store_checklist_templates')->where('organization_id', $c['organization'])
            ->where('school_id', $c['school'])->where('is_active', true)->orderBy('name')->get()
            ->map(fn ($r) => $this->present($r)));
    }

    public function store(Request $request)
    {
        $c = $this->scope($request, 'create');
        $d = $request->validate(['name' => 'required|string|min:3|max:160',
            'platform' => 'sometimes|in:SHOPEE,TIKTOK,TOKOPEDIA,LAZADA,INSTAGRAM_SHOP,WHATSAPP_BUSINESS,WEBSITE,OTHER',
            'minimumProducts' => 'required|integer|min:1|max:1000', 'items' => 'required|array|min:1',
            'items.*.code' => 'required|string|min:2|max:60', 'items.*.label' => 'required|string|min:2|max:200',
            'items.*.description' => 'sometimes|nullable|string|max:1000',
            'items.*.evidenceType' => 'sometimes|nullable|string|max:80',
            'items.*.weight' => 'required|integer|min:0|max:100',
            'items.*.isRequired' => 'required|boolean', 'items.*.position' => 'required|integer|min:1']);
        abort_if(array_sum(array_column($d['items'], 'weight')) !== 100, 400,
            'Checklist wajib memiliki item dengan total bobot tepat 100%');
        $codes = array_map(fn ($i) => strtoupper(trim($i['code'])), $d['items']);
        $positions = array_column($d['items'], 'position');
        abort_if(count(array_unique($codes)) !== count($codes) || count(array_unique($positions)) !== count($positions),
            422, 'Kode dan posisi checklist harus unik');
        abort_if(DB::table('store_checklist_templates')->where('school_id', $c['school'])
            ->where('name', trim($d['name']))->exists(), 409, 'Nama checklist sudah digunakan');
        $id = DB::transaction(function () use ($c, $d) {
            $id = (string) Str::ulid();
            DB::table('store_checklist_templates')->insert(['id' => $id, 'organization_id' => $c['organization'],
                'school_id' => $c['school'], 'created_by_id' => $c['user'], 'name' => trim($d['name']),
                'platform' => $d['platform'] ?? null, 'minimum_products' => $d['minimumProducts'],
                'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($d['items'] as $i) DB::table('store_checklist_items')->insert(['id' => (string) Str::ulid(),
                'template_id' => $id, 'code' => strtoupper(trim($i['code'])), 'label' => trim($i['label']),
                'description' => isset($i['description']) ? trim($i['description']) : null,
                'evidence_type' => isset($i['evidenceType']) ? trim($i['evidenceType']) : null,
                'weight' => $i['weight'], 'is_required' => $i['isRequired'], 'position' => $i['position']]);
            return $id;
        });
        return response()->json($this->present(DB::table('store_checklist_templates')->where('id', $id)->first()), 201);
    }
}
