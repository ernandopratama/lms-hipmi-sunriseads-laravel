<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Readiness reports the dependencies Laravel actually uses: MySQL and the private disk. */
class HealthApiController extends Controller
{
    public function ready()
    {
        try {
            DB::select('select 1');
        } catch (\Throwable) {
            return response()->json(['message' => 'Database tidak dapat dijangkau'], 503);
        }
        try {
            $probe = '.health-'.bin2hex(random_bytes(4));
            Storage::disk('local')->put($probe, 'ok');
            Storage::disk('local')->delete($probe);
            $storage = 'reachable';
        } catch (\Throwable) {
            $storage = 'unreachable';
        }
        return response()->json(['status' => $storage === 'reachable' ? 'ok' : 'degraded', 'database' => 'reachable',
            'redis' => 'not-used', 'objectStorage' => $storage, 'timestamp' => now()->utc()->format('Y-m-d\TH:i:s.v\Z')]);
    }
}
