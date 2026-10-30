<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AcademicYearApiController extends Controller
{
    public function __construct(private ApiContext $context) {}

    private function year(object $row, bool $includeSemesters = false): array
    {
        $result = ['id' => $row->id, 'schoolId' => $row->school_id, 'name' => $row->name,
            'startDate' => $row->start_date, 'endDate' => $row->end_date, 'isActive' => (bool) $row->is_active,
            'createdAt' => $row->created_at, 'updatedAt' => $row->updated_at];
        if ($includeSemesters) $result['semesters'] = DB::table('semesters')->where('academic_year_id', $row->id)->get()->map(fn ($s) =>
            ['id' => $s->id, 'academicYearId' => $s->academic_year_id, 'name' => $s->name,
                'startDate' => $s->start_date, 'endDate' => $s->end_date, 'isActive' => (bool) $s->is_active]);
        return $result;
    }

    public function index(Request $request)
    {
        $c = $this->context->resolve($request, 'master-data', 'read');
        return response()->json(DB::table('academic_years')->where('school_id', $c['school'])->orderByDesc('start_date')->get()
            ->map(fn ($y) => $this->year($y, true)));
    }

    public function store(Request $request)
    {
        $c = $this->context->resolve($request, 'master-data', 'create');
        $data = $request->validate(['name' => 'required|string|max:255', 'startDate' => 'required|date', 'endDate' => 'required|date|after:startDate']);
        $id = (string) Str::ulid();
        DB::table('academic_years')->insert(['id' => $id, 'school_id' => $c['school'], 'name' => $data['name'],
            'start_date' => Carbon::parse($data['startDate']), 'end_date' => Carbon::parse($data['endDate']),
            'is_active' => false, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json($this->year(DB::table('academic_years')->where('id', $id)->first()), 201);
    }

    public function activate(Request $request, string $id)
    {
        $c = $this->context->resolve($request, 'master-data', 'update');
        $year = DB::transaction(function () use ($id, $c) {
            DB::table('schools')->where('id', $c['school'])->lockForUpdate()->first();
            $year = DB::table('academic_years')->where('id', $id)->where('school_id', $c['school'])->first();
            abort_unless($year, 404, 'Tahun ajaran tidak ditemukan');
            DB::table('academic_years')->where('school_id', $c['school'])->where('is_active', true)->update(['is_active' => false, 'updated_at' => now()]);
            DB::table('academic_years')->where('id', $id)->update(['is_active' => true, 'updated_at' => now()]);
            return DB::table('academic_years')->where('id', $id)->first();
        });
        return response()->json($this->year($year));
    }

    public function semester(Request $request, string $id)
    {
        $c = $this->context->resolve($request, 'master-data', 'create');
        $data = $request->validate(['name' => 'required|string|max:255', 'startDate' => 'required|date', 'endDate' => 'required|date|after:startDate']);
        $year = DB::table('academic_years')->where('id', $id)->where('school_id', $c['school'])->first();
        abort_unless($year, 404, 'Tahun ajaran tidak ditemukan');
        abort_unless(Carbon::parse($data['startDate'])->gte(Carbon::parse($year->start_date)) && Carbon::parse($data['endDate'])->lte(Carbon::parse($year->end_date)), 422, 'Semester harus berada dalam tahun ajaran');
        $semesterId = (string) Str::ulid();
        DB::table('semesters')->insert(['id' => $semesterId, 'academic_year_id' => $id, 'name' => $data['name'],
            'start_date' => Carbon::parse($data['startDate']), 'end_date' => Carbon::parse($data['endDate']),
            'is_active' => false, 'created_at' => now(), 'updated_at' => now()]);
        $s = DB::table('semesters')->where('id', $semesterId)->first();
        return response()->json(['id' => $s->id, 'academicYearId' => $s->academic_year_id, 'name' => $s->name,
            'startDate' => $s->start_date, 'endDate' => $s->end_date, 'isActive' => (bool) $s->is_active], 201);
    }
}
