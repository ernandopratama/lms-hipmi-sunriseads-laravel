<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class PublicContentController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $type = $request->query('type');
        if ($type !== null && (! is_string($type) || strlen($type) > 50)) {
            return response()->json(['message' => 'Jenis konten tidak valid'], 400);
        }
        $items = DB::table('cms_contents')->where('status', 'PUBLISHED')
            ->when($type, fn ($query) => $query->where('type', $type))
            ->orderBy('type')->orderBy('position')
            ->get(['slug', 'type', 'title', 'excerpt', 'body', 'image_url', 'published_at']);
        return response()->json($items->map(fn ($item) => [
            'slug' => $item->slug,
            'type' => $item->type,
            'title' => $item->title,
            'excerpt' => $item->excerpt,
            'body' => json_decode($item->body, true),
            'imageUrl' => $item->image_url,
            'publishedAt' => $item->published_at ? Carbon::parse($item->published_at, 'UTC')->toISOString() : null,
        ]))->header('Cache-Control', 'no-store');
    }
}
