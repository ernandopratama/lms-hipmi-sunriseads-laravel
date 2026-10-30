<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CmsApiController extends Controller
{
    public function __construct(private ApiContext $context) {}

    private function output(object $r): array
    {
        return ['id' => $r->id, 'organizationId' => $r->organization_id, 'slug' => $r->slug,
            'type' => $r->type, 'title' => $r->title, 'excerpt' => $r->excerpt,
            'body' => json_decode($r->body, true), 'imageUrl' => $r->image_url,
            'status' => $r->status, 'position' => $r->position, 'authorId' => $r->author_id,
            'reviewedById' => $r->reviewed_by_id, 'publishedAt' => $r->published_at,
            'createdAt' => $r->created_at, 'updatedAt' => $r->updated_at];
    }

    public function index(Request $request)
    {
        $c = $this->context->resolve($request, 'cms', 'manage');
        return response()->json(DB::table('cms_contents')->where(fn ($q) => $q->whereNull('organization_id')
            ->orWhere('organization_id', $c['organization']))->orderBy('type')->orderBy('position')
            ->get()->map(fn ($r) => $this->output($r)));
    }

    public function store(Request $request)
    {
        $c = $this->context->resolve($request, 'cms', 'manage');
        $d = $request->validate(['slug' => 'required|string|min:2|max:120',
            'type' => 'required|in:FAQ,TESTIMONIAL,CASE_STUDY,PARTNER,PAGE',
            'title' => 'required|string|min:3|max:180', 'excerpt' => 'sometimes|nullable|string|max:500',
            'body' => 'present|array', 'imageUrl' => 'sometimes|nullable|string|max:2048',
            'position' => 'sometimes|integer|min:0|max:10000']);
        $slug = preg_replace('/-+/', '-', preg_replace('/[^a-z0-9-]/', '-', strtolower(trim($d['slug']))));
        abort_if(DB::table('cms_contents')->where('slug', $slug)->exists(), 409, 'Slug sudah digunakan');
        $id = (string) Str::ulid();
        DB::table('cms_contents')->insert(['id' => $id, 'organization_id' => $c['organization'],
            'slug' => $slug, 'type' => $d['type'], 'title' => trim($d['title']),
            'excerpt' => isset($d['excerpt']) ? trim($d['excerpt']) : null,
            'body' => json_encode($d['body']), 'image_url' => $d['imageUrl'] ?? null,
            'position' => $d['position'] ?? 0, 'author_id' => $c['user'],
            'status' => 'DRAFT', 'created_at' => now(), 'updated_at' => now()]);
        return response()->json($this->output(DB::table('cms_contents')->where('id', $id)->first()), 201);
    }

    public function publish(Request $request, string $id)
    {
        $c = $this->context->resolve($request, 'cms', 'manage');
        $query = DB::table('cms_contents')->where('id', $id)->where(fn ($q) => $q->whereNull('organization_id')
            ->orWhere('organization_id', $c['organization']));
        abort_unless($query->exists(), 404, 'Konten tidak ditemukan');
        $query->update(['status' => 'PUBLISHED', 'reviewed_by_id' => $c['user'],
            'published_at' => now(), 'updated_at' => now()]);
        return response()->json($this->output(DB::table('cms_contents')->where('id', $id)->first()));
    }
}
