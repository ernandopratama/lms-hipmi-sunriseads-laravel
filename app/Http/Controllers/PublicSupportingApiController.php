<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class PublicSupportingApiController extends Controller
{
    public function certificate(string $code)
    {
        $r = DB::table('certificates')->where('verification_code', $code)->first();
        if (! $r) return response()->json(['valid' => false, 'status' => 'NOT_FOUND']);
        $template = DB::table('certificate_templates')->where('id', $r->template_id)->first();
        $school = DB::table('schools')->where('id', $r->school_id)->first();
        $student = DB::table('students')->where('id', $r->student_id)->first();
        $consent = $r->publication_consent_id ? DB::table('consents')->where('id', $r->publication_consent_id)->first() : null;
        $published = $r->public_visible && $consent && $consent->status === 'ACTIVE'
            && (! $consent->expires_at || $consent->expires_at > now());
        return response()->json(['valid' => $r->status === 'ISSUED', 'status' => $r->status,
            'verificationCode' => $r->verification_code,
            'title' => $published ? $r->title : $template?->name,
            'school' => $school?->name, 'issuedAt' => $r->issued_at,
            ...($published ? ['studentName' => $student?->full_name, 'city' => $school?->city] : [])]);
    }

    public function portfolio(string $slug)
    {
        $r = DB::table('portfolio_items')->where('public_slug', $slug)->where('status', 'PUBLISHED')->first();
        $consent = $r?->publication_consent_id ? DB::table('consents')->where('id', $r->publication_consent_id)->first() : null;
        abort_unless($r && $consent && $consent->status === 'ACTIVE'
            && (! $consent->expires_at || $consent->expires_at > now()), 404, 'Portofolio publik tidak ditemukan');
        $student = DB::table('students')->where('id', $r->student_id)->first();
        $school = DB::table('schools')->where('id', $r->school_id)->first();
        return response()->json(['title' => $r->title, 'description' => $r->description,
            'sourceType' => $r->source_type, 'studentName' => $student?->full_name,
            'school' => $school?->name, 'city' => $school?->city, 'publishedAt' => $r->published_at]);
    }
}
