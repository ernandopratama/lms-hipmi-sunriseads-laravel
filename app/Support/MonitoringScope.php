<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Student, assignment and lesson scope for monitoring; port of MonitoringService.resolveScope. */
class MonitoringScope
{
    public function audience(array $roles): string
    {
        return match (true) {
            in_array('student', $roles, true) => 'STUDENT',
            in_array('parent', $roles, true) => 'PARENT',
            in_array('teacher', $roles, true) => 'TEACHER',
            (bool) array_intersect($roles, ['school-admin', 'principal']) => 'SCHOOL',
            default => 'PROGRAM',
        };
    }

    /** @return array{studentIds: Collection, schoolIds: array, courseIds: array, kind: string} */
    public function resolve(array $c, ?string $classId): array
    {
        $now = now();
        $students = DB::table('students')->where('school_id', $c['school']);
        $courseIds = [];
        $kind = 'school';
        if ($c['student']) {
            $students->where('id', $c['student']->id);
            $kind = 'student';
        } elseif (in_array('parent', $c['roles'], true)) {
            $parent = DB::table('parents')->where('user_id', $c['user'])->where('is_active', true)->value('id');
            $students = DB::table('students')->whereIn('id', DB::table('student_parent_links')->where('parent_id', $parent ?? '')->where('verification_status', 'VERIFIED')
                ->whereNull('revoked_at')->where('effective_from', '<=', $now)->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $now))->select('student_id'))
                ->whereIn('school_id', DB::table('schools')->where('organization_id', $c['organization'])->select('id'));
            $kind = 'parent';
        } elseif (in_array('teacher', $c['roles'], true)) {
            abort_unless($c['teacher'], 403, 'Konteks guru dan sekolah diperlukan');
            $teaching = DB::table('teacher_assignments')->where('teacher_id', $c['teacher']->id)->where('school_id', $c['school'])->where('is_active', true)
                ->where('effective_from', '<=', $now)->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $now))->get(['class_id', 'course_id']);
            $owned = DB::table('assignments as a')->join('courses as co', 'co.id', '=', 'a.course_id')->where('a.teacher_id', $c['teacher']->id)->where('co.school_id', $c['school'])->pluck('a.course_id');
            $classIds = $teaching->pluck('class_id')->unique()->values();
            $courseIds = $teaching->pluck('course_id')->filter()->merge($owned)->unique()->values()->all();
            $students->where(fn ($q) => $q
                ->when($classIds->isNotEmpty(), fn ($q) => $q->orWhereIn('id', DB::table('class_enrollments')->whereIn('class_id', $classIds)->where('status', 'ACTIVE')->select('student_id')))
                ->when($courseIds, fn ($q) => $q->orWhereIn('id', DB::table('course_enrollments')->whereIn('course_id', $courseIds)->where('status', 'ACTIVE')->select('student_id')))
                ->when($classIds->isEmpty() && ! $courseIds, fn ($q) => $q->whereRaw('1 = 0')));
            $kind = 'teacher';
        }
        if ($classId) {
            $students->where(fn ($q) => $q->where('class_id', $classId)->orWhereIn('id', DB::table('class_enrollments')->where('class_id', $classId)->where('status', 'ACTIVE')->select('student_id')));
        }
        $ids = $students->pluck('id');
        if ($classId && $ids->isEmpty()) {
            $allowed = DB::table('classes as k')->join('schools as s', 's.id', '=', 'k.school_id')->where('k.id', $classId)->where('s.organization_id', $c['organization'])->where('k.school_id', $c['school'])
                ->when($kind === 'teacher', fn ($q) => $q->whereExists(fn ($e) => $e->from('teacher_assignments')->whereColumn('teacher_assignments.class_id', 'k.id')
                    ->where('teacher_assignments.teacher_id', $c['teacher']->id)->where('teacher_assignments.is_active', true)))->exists();
            abort_unless($allowed, 403, 'Kelas berada di luar ruang lingkup Anda');
        }
        return ['studentIds' => $ids, 'schoolIds' => [$c['school']], 'courseIds' => $courseIds, 'kind' => $kind];
    }

    public function assignments(array $c, array $scope): Builder
    {
        $q = DB::table('assignments as a')->join('courses as co', 'co.id', '=', 'a.course_id')->join('course_modules as m', 'm.id', '=', 'a.module_id')
            ->where('a.is_active', true)->whereNotNull('a.published_at')->where('a.published_at', '<=', now())->where('m.is_published', true);
        return match ($scope['kind']) {
            'teacher' => $q->where('a.teacher_id', $c['teacher']->id)->when($scope['courseIds'], fn ($q) => $q->whereIn('a.course_id', $scope['courseIds'])),
            'student', 'parent' => $q->whereIn('a.course_id', DB::table('course_enrollments')->whereIn('student_id', $scope['studentIds'])->where('status', 'ACTIVE')->select('course_id')),
            default => $q->whereIn('co.school_id', $scope['schoolIds']),
        };
    }

    public function lessons(array $c, array $scope): Builder
    {
        $q = DB::table('lessons as l')->join('course_modules as m', 'm.id', '=', 'l.module_id')->join('courses as co', 'co.id', '=', 'm.course_id')
            ->where('l.is_published', true)->where('m.is_published', true);
        return match ($scope['kind']) {
            'teacher' => $q->whereIn('co.id', $scope['courseIds'] ?: [''])->where('co.school_id', $c['school']),
            'student', 'parent' => $q->whereIn('co.id', DB::table('course_enrollments')->whereIn('student_id', $scope['studentIds'])->where('status', 'ACTIVE')->select('course_id')),
            default => $q->whereIn('co.school_id', $scope['schoolIds']),
        };
    }
}
