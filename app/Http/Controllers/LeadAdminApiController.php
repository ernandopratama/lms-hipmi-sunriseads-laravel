<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeadAdminApiController extends Controller
{
    public function __construct(private ApiContext $context) {}

    private function output(object $r): array
    {
        return ['id' => $r->id, 'schoolName' => $r->school_name, 'educationLevel' => $r->education_level,
            'city' => $r->city, 'province' => $r->province, 'picName' => $r->pic_name,
            'picTitle' => $r->pic_title, 'email' => $r->email, 'phone' => $r->phone,
            'estimatedStudents' => $r->estimated_students, 'programOfInterest' => $r->program_of_interest,
            'message' => $r->message, 'consent' => (bool) $r->consent, 'status' => $r->status,
            'createdAt' => $r->created_at, 'updatedAt' => $r->updated_at];
    }

    public function index(Request $request)
    {
        $this->context->resolve($request, 'leads', 'read');
        return response()->json(DB::table('leads')->orderByDesc('created_at')->get()->map(fn ($r) => $this->output($r)));
    }

    public function updateStatus(Request $request, string $id)
    {
        $this->context->resolve($request, 'leads', 'update');
        $d = $request->validate(['status' => 'required|in:PENDING,CONTACTED,APPROVED,REJECTED']);
        $lead = DB::table('leads')->where('id', $id);
        abort_unless($lead->exists(), 404, 'Lead not found');
        $lead->update(['status' => $d['status'], 'updated_at' => now()]);
        return response()->json($this->output(DB::table('leads')->where('id', $id)->first()));
    }
}
