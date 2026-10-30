<?php

namespace App\Http\Controllers;

use App\Support\StudentDashboardAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EffectiveAccessApiController extends Controller
{
    public function __invoke(Request $request, StudentDashboardAccess $resolver): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->organization_id, 403, 'Konteks organisasi diperlukan');
        $schoolId = $request->session()->get('selected_school_id', $user->school_id);
        if ($schoolId) {
            abort_unless(DB::table('schools')->where('id', $schoolId)
                ->where('organization_id', $user->organization_id)->exists(), 403);
            if ($user->role !== 'admin') abort_unless($schoolId === $user->school_id, 403);
        }

        $studentId = null;
        if ($user->role === 'student') {
            abort_unless($schoolId, 403);
            $studentId = DB::table('students')->where('user_id', $user->id)
                ->where('school_id', $schoolId)->value('id');
            abort_unless($studentId, 403, 'Akun tidak memiliki profil siswa yang valid');
        }
        $access = $resolver->resolve($user->organization_id, $schoolId, $user->id, $studentId);
        if ($access === null) {
            return response()->json(['message' => 'Paket akses sumber belum dimigrasikan'], 503)
                ->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'plan' => $access['plan'], 'grant' => $access['grant'],
            'source' => $access['source'], 'entitlements' => $access['entitlements'],
            'resolvedAt' => $access['resolvedAt'],
        ])->header('Cache-Control', 'no-store');
    }
}
