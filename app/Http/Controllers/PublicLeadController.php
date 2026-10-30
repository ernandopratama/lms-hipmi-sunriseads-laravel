<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicLeadController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'schoolName' => ['required', 'string', 'max:255'],
            'educationLevel' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:255'],
            'province' => ['required', 'string', 'max:255'],
            'picName' => ['required', 'string', 'max:255'],
            'picTitle' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'min:9', 'max:40'],
            'estimatedStudents' => ['required', 'integer', 'min:1'],
            'programOfInterest' => ['required', 'string', 'max:255'],
            'message' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'consent' => ['required', 'boolean'],
            'website' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ]);

        if (! empty($data['website'])) {
            return response()->json(['message' => 'Registration submitted successfully'], 201);
        }
        if (! $data['consent']) {
            return response()->json(['message' => 'Consent to privacy policy is required'], 400);
        }

        $lead = Lead::create([
            'school_name' => $data['schoolName'],
            'education_level' => $data['educationLevel'],
            'city' => $data['city'],
            'province' => $data['province'],
            'pic_name' => $data['picName'],
            'pic_title' => $data['picTitle'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'estimated_students' => $data['estimatedStudents'],
            'program_of_interest' => $data['programOfInterest'],
            'message' => $data['message'] ?? null,
            'consent' => true,
            'status' => 'PENDING',
        ]);

        return response()->json([
            'id' => $lead->id,
            'schoolName' => $lead->school_name,
            'educationLevel' => $lead->education_level,
            'city' => $lead->city,
            'province' => $lead->province,
            'picName' => $lead->pic_name,
            'picTitle' => $lead->pic_title,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'estimatedStudents' => $lead->estimated_students,
            'programOfInterest' => $lead->program_of_interest,
            'message' => $lead->message,
            'consent' => $lead->consent,
            'status' => $lead->status,
            'createdAt' => $lead->created_at->toISOString(),
            'updatedAt' => $lead->updated_at->toISOString(),
        ], 201);
    }
}
