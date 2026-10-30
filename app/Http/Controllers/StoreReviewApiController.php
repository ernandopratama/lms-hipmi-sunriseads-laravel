<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use App\Support\StudentDashboardAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StoreReviewApiController extends Controller
{
    public function __construct(private ApiContext $context, private StudentDashboardAccess $access) {}

    private function scope(Request $request): array
    {
        $c = $this->context->resolve($request, 'submissions', 'review');
        abort_unless($c['teacher'], 403, 'Profil guru diperlukan');
        $plan = $this->access->resolve($c['organization'], $c['school'], $c['user']);
        abort_unless($plan && $this->access->enabled($plan, 'student_store.submit'), 403,
            'Student Store tidak termasuk dalam paket aktif');
        return $c;
    }

    private function visible(array $c)
    {
        return DB::table('student_store_submissions as ss')->join('assignments as a', 'a.id', '=', 'ss.assignment_id')
            ->where('ss.organization_id', $c['organization'])->where('ss.school_id', $c['school'])
            ->where('a.teacher_id', $c['teacher']->id);
    }

    public function queue(Request $request)
    {
        $c = $this->scope($request);
        return response()->json($this->visible($c)->whereIn('ss.status', ['SUBMITTED', 'RESUBMITTED', 'UNDER_REVIEW'])
            ->orderBy('ss.submitted_at')->get(['ss.*', 'a.title as assignment_title', 'a.end_date'])
            ->map(function ($r) {
                $s = DB::table('students')->where('id', $r->student_id)->first();
                $class = $s?->class_id ? DB::table('classes')->where('id', $s->class_id)->first() : null;
                $store = $r->student_store_id ? DB::table('student_stores')->where('id', $r->student_store_id)->first() : null;
                return ['id' => $r->id, 'status' => $r->status, 'submittedAt' => $r->submitted_at,
                    'currentVersion' => $r->current_version,
                    'assignment' => ['id' => $r->assignment_id, 'title' => $r->assignment_title, 'endDate' => $r->end_date],
                    'student' => $s ? ['id' => $s->id, 'fullName' => $s->full_name,
                        'studentNumber' => $s->student_number, 'class' => $class ? ['name' => $class->name] : null] : null,
                    'studentStore' => $store ? ['platform' => $store->platform, 'storeName' => $store->store_name,
                        'normalizedStoreUrl' => $store->normalized_store_url] : null,
                    '_count' => ['fraudFlags' => DB::table('store_fraud_flags')->where('store_submission_id', $r->id)->count(),
                        'files' => DB::table('student_store_files')->where('store_submission_id', $r->id)->count()]];
            }));
    }

    public function resolveFlag(Request $request, string $id)
    {
        $c = $this->scope($request);
        $d = $request->validate(['status' => 'required|in:DISMISSED,CONFIRMED,RESOLVED',
            'notes' => 'required|string|min:3|max:2000']);
        $flag = DB::table('store_fraud_flags as f')->join('student_store_submissions as ss', 'ss.id', '=', 'f.store_submission_id')
            ->join('assignments as a', 'a.id', '=', 'ss.assignment_id')->where('f.id', $id)
            ->where('ss.organization_id', $c['organization'])->where('ss.school_id', $c['school'])
            ->where('a.teacher_id', $c['teacher']->id)->first(['f.*']);
        abort_unless($flag, 404, 'Flag tidak ditemukan dalam antrean guru');
        DB::table('store_fraud_flags')->where('id', $id)->update(['status' => $d['status'],
            'resolution_notes' => trim($d['notes']), 'resolved_by_id' => $c['user'], 'resolved_at' => now()]);
        $r = DB::table('store_fraud_flags')->where('id', $id)->first();
        return response()->json(collect((array) $r)->mapWithKeys(fn ($v, $k) => [Str::camel($k) =>
            $k === 'evidence' && $v !== null ? json_decode($v, true) : $v])->all());
    }
}
