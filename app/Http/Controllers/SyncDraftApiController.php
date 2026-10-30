<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncDraftApiController extends Controller
{
    public function __construct(private ApiContext $context) {}

    private function output(?object $row): ?array
    {
        return $row ? ['id' => $row->id, 'organizationId' => $row->organization_id,
            'schoolId' => $row->school_id, 'userId' => $row->user_id, 'draftKey' => $row->draft_key,
            'content' => json_decode($row->content, true), 'version' => $row->version,
            'lastSyncedAt' => $row->last_synced_at, 'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at] : null;
    }

    private function scoped(array $c, string $key)
    {
        return DB::table('sync_drafts')->where('organization_id', $c['organization'])
            ->where('user_id', $c['user'])->where('draft_key', $key);
    }

    public function show(Request $request, string $key)
    {
        $c = $this->context->resolve($request, 'offline-drafts', 'update');
        $row = $this->scoped($c, $key)->first();
        return $row ? response()->json($this->output($row))
            : response('null', 200)->header('Content-Type', 'application/json');
    }

    public function put(Request $request, string $key)
    {
        $c = $this->context->resolve($request, 'offline-drafts', 'update');
        $d = $request->validate(['content' => 'required|array', 'version' => 'sometimes|integer|min:0']);
        return DB::transaction(function () use ($c, $key, $d) {
            $existing = $this->scoped($c, $key)->lockForUpdate()->first();
            if ($existing && isset($d['version']) && (int) $d['version'] !== (int) $existing->version)
                return response()->json(['message' => 'Draf berubah di perangkat lain', 'code' => 'DRAFT_VERSION_CONFLICT',
                    'serverVersion' => $existing->version, 'serverDraft' => json_decode($existing->content, true)], 409);
            $data = ['content' => json_encode($d['content']), 'last_synced_at' => now(), 'updated_at' => now()];
            if ($existing) $this->scoped($c, $key)->update([...$data, 'version' => $existing->version + 1]);
            else DB::table('sync_drafts')->insert([...$data, 'id' => (string) Str::ulid(),
                'organization_id' => $c['organization'], 'school_id' => $c['school'],
                'user_id' => $c['user'], 'draft_key' => $key, 'version' => 1, 'created_at' => now()]);
            return response()->json($this->output($this->scoped($c, $key)->first()));
        });
    }

    public function delete(Request $request, string $key)
    {
        $c = $this->context->resolve($request, 'offline-drafts', 'update');
        $this->scoped($c, $key)->delete();
        return response()->json(['deleted' => true]);
    }
}
