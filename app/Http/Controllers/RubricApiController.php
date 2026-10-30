<?php
namespace App\Http\Controllers;
use App\Support\{ApiContext, StudentDashboardAccess};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RubricApiController extends Controller
{
    public function __construct(private ApiContext $context, private StudentDashboardAccess $access) {}
    private function scope(Request $r, string $action): array
    {
        $c = $this->context->resolve($r, 'assignments', $action);
        $a = $this->access->resolve($c['organization'], $c['school'], $c['user'], $c['student']?->id);
        abort_unless($a, 503, 'Paket akses belum dimigrasikan');
        abort_unless($this->access->enabled($a, $action === 'read' ? 'assignments.standard.read' : 'assignments.standard.submit'), 403, 'Rubrik tidak termasuk dalam paket aktif');
        return $c + ['access' => $a];
    }
    private function query(array $c)
    {
        $q = DB::table('rubrics')->where('organization_id', $c['organization']);
        if ($c['student']) {
            $modules = $this->access->moduleIds($c['organization'], $c['school'], $c['student']->id, $c['access']);
            $types = $this->access->assignmentTypes($c['access']);
            $q->whereIn('id', DB::table('assignments as a')->join('courses as c', 'c.id', '=', 'a.course_id')
                ->join('course_enrollments as e', 'e.course_id', '=', 'c.id')->where('c.school_id', $c['school'])->where('c.organization_id', $c['organization'])
                ->where('e.student_id', $c['student']->id)->where('e.status', 'ACTIVE')->where('e.school_id', $c['school'])
                ->where('a.is_active', true)->where('a.published_at', '<=', now())->whereIn('a.module_id', $modules)->whereIn('a.type', $types)->select('a.rubric_id'));
        }
        return $q;
    }
    private function encode(object $row, array $fields): array
    {
        $result = []; foreach ($fields as $f) $result[Str::camel($f)] = $row->$f ?? null; return $result;
    }
    private function rubric(object $row, bool $count = true): array
    {
        $result = $this->encode($row, ['id', 'organization_id', 'name', 'description', 'created_at', 'updated_at']);
        $result['criteria'] = DB::table('rubric_criteria')->where('rubric_id', $row->id)->orderBy('position')->get()->map(function ($criterion) {
            return $this->encode($criterion, ['id', 'rubric_id', 'name', 'description', 'weight', 'max_score', 'position']) + ['levels' => $criterion->levels ? json_decode($criterion->levels, true) : null];
        });
        if ($count) $result['_count'] = ['assignments' => DB::table('assignments')->where('rubric_id', $row->id)->count()];
        return $result;
    }
    public function index(Request $r) { $c = $this->scope($r, 'read'); return response()->json($this->query($c)->orderByDesc('created_at')->get()->map(fn ($row) => $this->rubric($row))); }
    public function show(Request $r, string $id) { $c = $this->scope($r, 'read'); $row = $this->query($c)->where('id', $id)->first(); abort_unless($row, 404); return response()->json($this->rubric($row, false)); }
    private function insert(array $c, array $d): object
    {
        $id = (string) Str::ulid();
        DB::table('rubrics')->insert(['id' => $id, 'organization_id' => $c['organization'], 'name' => trim($d['name']), 'description' => $d['description'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($d['criteria'] as $i => $criterion) {
            DB::table('rubric_criteria')->insert(['id' => (string) Str::ulid(), 'rubric_id' => $id, 'name' => trim($criterion['name']), 'description' => $criterion['description'] ?? null, 'weight' => $criterion['weight'], 'max_score' => $criterion['maxScore'], 'position' => $criterion['position'] ?? $i + 1, 'levels' => isset($criterion['levels']) ? json_encode($criterion['levels'], JSON_THROW_ON_ERROR) : null]);
        }
        return DB::table('rubrics')->where('id', $id)->first();
    }
    public function store(Request $r)
    {
        $c = $this->scope($r, 'create');
        $d = $r->validate(['name' => 'required|string|min:3|max:160', 'description' => 'nullable|string|max:2000', 'criteria' => 'required|array|min:1|max:100', 'criteria.*.name' => 'required|string|min:2|max:160', 'criteria.*.description' => 'nullable|string|max:2000', 'criteria.*.weight' => 'required|integer|min:0|max:100', 'criteria.*.maxScore' => 'required|integer|min:1|max:1000', 'criteria.*.position' => 'nullable|integer|min:1', 'criteria.*.levels' => 'nullable|array']);
        abort_unless(array_sum(array_column($d['criteria'], 'weight')) === 100, 422, 'Total bobot kriteria harus tepat 100%');
        return DB::transaction(fn () => response()->json($this->rubric($this->insert($c, $d)), 201));
    }
    public function mgmp(Request $r)
    {
        $c = $this->scope($r, 'create');
        $templates = json_decode(file_get_contents(config_path('mgmp-rubrics.json')), true, 512, JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($templates, $c) {
            DB::table('organizations')->where('id', $c['organization'])->lockForUpdate()->first();
            foreach ($templates as $template) {
                if (DB::table('rubrics')->where('organization_id', $c['organization'])->where('name', $template['name'])->exists()) continue;
                $template['criteria'] = array_map(fn ($criterion) => $criterion + ['weight' => 25, 'maxScore' => 100, 'levels' => ['sangatBaik' => 'Bukti lengkap, tepat, dan konsisten.', 'baik' => 'Bukti cukup tepat dengan perbaikan kecil.', 'cukup' => 'Bukti terbatas atau belum konsisten.', 'perluPerbaikan' => 'Bukti tidak cukup atau tidak relevan.']], $template['criteria']);
                $this->insert($c, $template);
            }
        });
        return response()->json($this->query($c)->orderByDesc('created_at')->get()->map(fn ($row) => $this->rubric($row)));
    }
}
