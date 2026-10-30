<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Port of apps/web/src/app/api/materials/[id]/route.ts: material info, slide previews and
 * downloads. Access is decided by learning/materials/{id}/access (enrolment, plan, tenant).
 * Files live under MATERIALS_PATH (default storage/app/private/materials), never in public/.
 */
class MaterialFileController extends Controller
{
    private function root(): string
    {
        return rtrim(env('MATERIALS_PATH') ?: storage_path('app/private/materials'), '/');
    }

    private function headers(string $type, ?string $disposition = null): array
    {
        return ['Cache-Control' => 'private, no-store', 'Content-Type' => $type, 'X-Content-Type-Options' => 'nosniff'] + ($disposition ? ['Content-Disposition' => $disposition] : []);
    }

    private function file(string $path, string $type, ?string $disposition = null)
    {
        $real = realpath($path);
        $root = realpath($this->root());
        abort_unless($real && $root && str_starts_with($real, $root.DIRECTORY_SEPARATOR) && is_file($real), 404, 'File materi belum tersedia');
        return response(file_get_contents($real), 200, $this->headers($type, $disposition));
    }

    public function show(Request $request, string $id)
    {
        $material = app(LearningApiController::class)->materialAccess($request, $id)->getData(true);
        $name = (string) $material['originalFileName'];
        abort_if(basename($name) !== $name || ! str_ends_with(strtolower($name), '.pptx'), 400, 'Nama file materi tidak valid');
        $root = $this->root();
        $count = (int) ($material['previewSlideCount'] ?? 0);

        if ($request->query('slide') !== null) {
            $slide = $request->query('slide');
            abort_unless(preg_match('/^\d+$/', (string) $slide) && (int) $slide >= 1 && (int) $slide <= $count, 404, 'Slide materi tidak tersedia');
            return $this->file($root.'/previews/'.pathinfo($name, PATHINFO_FILENAME).'/slide-'.(int) $slide.'.png', 'image/png');
        }
        if ($request->query('download') === '1') {
            return $this->file($root.'/'.$name, $material['mimeType'] ?: 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'attachment; filename="'.$name.'"');
        }
        return response()->json(['id' => $material['id'], 'title' => $material['title'], 'slideCount' => $count,
            'downloadUrl' => '/api/materials/'.rawurlencode($material['id']).'?download=1'], 200, ['Cache-Control' => 'private, no-store']);
    }
}
