<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditDashboardMigration extends Command
{
    protected $signature = 'sunrise:audit-dashboard {--baseline= : JSON file from the read-only source inventory}';

    protected $description = 'Read-only dashboard migration counts, ID hashes, and tenant integrity checks';

    private const TABLES = [
        'organizations', 'schools', 'programs', 'departments', 'users', 'courses', 'course_modules', 'lessons', 'materials', 'lesson_progress', 'assignments', 'assignment_versions', 'submissions', 'submission_versions',
        'classes', 'students', 'teachers', 'academic_years', 'semesters', 'class_enrollments', 'course_enrollments',
        'teacher_assignments', 'affiliate_accounts', 'affiliate_account_reviews', 'affiliate_videos', 'affiliate_content_versions', 'affiliate_metric_snapshots',
        'access_plans', 'entitlement_definitions', 'plan_entitlements', 'access_grants', 'module_plan_access',
        'roles', 'permissions', 'user_roles', 'role_permissions',
        'rubrics', 'rubric_criteria', 'submission_reviews', 'review_scores', 'ai_assessments', 'ai_assessment_scores',
        'store_checklist_templates', 'store_checklist_items', 'student_stores', 'student_store_submissions', 'student_store_submission_versions',
        'student_store_files', 'store_checklist_responses', 'store_reviews', 'store_review_scores', 'store_fraud_flags',
        'affiliate_fraud_flags', 'affiliate_metric_verifications',
        'import_jobs', 'import_rows',
    ];

    public function handle(): int
    {
        $baselinePath = $this->option('baseline');
        $baseline = null;
        if ($baselinePath) {
            if (! is_file($baselinePath)) {
                $this->error('File baseline tidak ditemukan.');
                return self::FAILURE;
            }
            $baseline = json_decode(file_get_contents($baselinePath), true);
            if (! is_array($baseline) || ! isset($baseline['tables']) || ! is_array($baseline['tables'])) {
                $this->error('Format baseline tidak valid.');
                return self::FAILURE;
            }
        }

        $results = [];
        $defects = [];
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                $defects[$table] = 'table_missing';
                continue;
            }
            $hash = hash_init('sha256');
            $count = 0;
            $key = $table === 'module_plan_access' ? 'module_id' : 'id';
            foreach (DB::table($table)->orderBy($key)->cursor() as $row) {
                hash_update($hash, $row->$key."\n");
                $count++;
            }
            $results[$table] = ['count' => $count, 'idSha256' => hash_final($hash)];
            if ($baseline && ($baseline['tables'][$table] ?? null) !== $results[$table]) {
                $defects['baseline_'.$table] = 'count_or_id_mismatch';
            }
        }

        if (count($results) === count(self::TABLES)) {
            $checks = [
                'courses_without_school' => DB::table('courses')->whereNull('school_id')->count(),
                'courses_wrong_organization' => DB::table('courses as c')->join('schools as s', 's.id', '=', 'c.school_id')->whereColumn('c.organization_id', '!=', 's.organization_id')->count(),
                'programs_cross_school' => DB::table('programs as p')->join('schools as s', 's.id', '=', 'p.school_id')->whereColumn('p.organization_id', '!=', 's.organization_id')->count(),
                'classes_wrong_department' => DB::table('classes as c')->leftJoin('departments as d', 'd.id', '=', 'c.department_id')->whereNotNull('c.department_id')->where(fn ($q) => $q->whereNull('d.id')->orWhereColumn('d.school_id', '!=', 'c.school_id'))->count(),
                'lessons_wrong_module' => DB::table('lessons as l')->leftJoin('course_modules as m', 'm.id', '=', 'l.module_id')->whereNotNull('l.module_id')->where(fn ($q) => $q->whereNull('m.id')->orWhereColumn('l.course_module_id', '!=', 'l.module_id'))->count(),
                'materials_cross_school' => DB::table('materials as mat')->join('courses as c', 'c.id', '=', 'mat.course_id')->join('teachers as t', 't.id', '=', 'mat.teacher_id')->leftJoin('lessons as l', 'l.id', '=', 'mat.lesson_id')->leftJoin('course_modules as m', 'm.id', '=', 'l.module_id')->where(fn ($q) => $q->whereColumn('c.school_id', '!=', 't.school_id')->orWhere(fn ($q) => $q->whereNotNull('mat.lesson_id')->where(fn ($q) => $q->whereNull('l.id')->orWhereColumn('m.course_id', '!=', 'mat.course_id'))))->count(),
                'lesson_progress_cross_school' => DB::table('lesson_progress as p')->join('students as st', 'st.id', '=', 'p.student_id')->join('lessons as l', 'l.id', '=', 'p.lesson_id')->join('course_modules as m', 'm.id', '=', 'l.module_id')->join('courses as c', 'c.id', '=', 'm.course_id')->where(fn ($q) => $q->whereColumn('st.school_id', '!=', 'c.school_id')->orWhereColumn('p.user_id', '!=', 'st.user_id'))->count(),
                'courses_missing_program' => DB::table('courses as c')->leftJoin('programs as p', 'p.id', '=', 'c.program_id')->whereNotNull('c.program_id')->whereNull('p.id')->count(),
                'courses_wrong_program' => DB::table('courses as c')->join('programs as p', 'p.id', '=', 'c.program_id')->where(fn ($q) => $q->whereColumn('c.organization_id', '!=', 'p.organization_id')->orWhereColumn('c.school_id', '!=', 'p.school_id'))->count(),
                'students_wrong_organization' => DB::table('students as st')->join('schools as s', 's.id', '=', 'st.school_id')->join('users as u', 'u.id', '=', 'st.user_id')->whereColumn('u.organization_id', '!=', 's.organization_id')->count(),
                'teachers_wrong_organization' => DB::table('teachers as t')->join('schools as s', 's.id', '=', 't.school_id')->join('users as u', 'u.id', '=', 't.user_id')->whereColumn('u.organization_id', '!=', 's.organization_id')->count(),
                'assignments_cross_school' => DB::table('assignments as a')->join('courses as c', 'c.id', '=', 'a.course_id')->leftJoin('teachers as t', 't.id', '=', 'a.teacher_id')->where(fn ($q) => $q->whereColumn('a.organization_id', '!=', 'c.organization_id')->orWhere(fn ($teacher) => $teacher->whereNotNull('a.teacher_id')->whereColumn('t.school_id', '!=', 'c.school_id')))->count(),
                'submissions_cross_school' => DB::table('submissions as sub')->join('assignments as a', 'a.id', '=', 'sub.assignment_id')->join('courses as c', 'c.id', '=', 'a.course_id')->join('students as st', 'st.user_id', '=', 'sub.user_id')->leftJoin('teachers as t', 't.id', '=', 'sub.reviewed_by_id')->where(fn ($q) => $q->whereColumn('st.school_id', '!=', 'c.school_id')->orWhere(fn ($q) => $q->whereNotNull('sub.reviewed_by_id')->where(fn ($q) => $q->whereNull('t.id')->orWhereColumn('t.school_id', '!=', 'c.school_id'))))->count(),
                'assignment_modules_wrong_course' => DB::table('assignments as a')->join('course_modules as m', 'm.id', '=', 'a.module_id')->whereColumn('a.course_id', '!=', 'm.course_id')->count(),
                'assignment_rubrics_wrong_organization' => DB::table('assignments as a')->leftJoin('rubrics as r', 'r.id', '=', 'a.rubric_id')->whereNotNull('a.rubric_id')->where(fn ($q) => $q->whereNull('r.id')->orWhereColumn('a.organization_id', '!=', 'r.organization_id'))->count(),
                'reviews_cross_school' => DB::table('submission_reviews as rev')->join('submissions as sub', 'sub.id', '=', 'rev.submission_id')->join('assignments as a', 'a.id', '=', 'sub.assignment_id')->join('courses as c', 'c.id', '=', 'a.course_id')->join('teachers as t', 't.id', '=', 'rev.reviewer_id')->leftJoin('rubrics as r', 'r.id', '=', 'rev.rubric_id')->where(fn ($q) => $q->whereColumn('t.school_id', '!=', 'c.school_id')->orWhere(fn ($q) => $q->whereNotNull('rev.rubric_id')->where(fn ($q) => $q->whereNull('r.id')->orWhereColumn('r.organization_id', '!=', 'a.organization_id'))))->count(),
                'review_scores_wrong_rubric' => DB::table('review_scores as s')->join('submission_reviews as r', 'r.id', '=', 's.review_id')->join('rubric_criteria as c', 'c.id', '=', 's.criterion_id')->whereColumn('r.rubric_id', '!=', 'c.rubric_id')->count(),
                'ai_scores_wrong_rubric' => DB::table('ai_assessment_scores as s')->join('ai_assessments as a', 'a.id', '=', 's.ai_assessment_id')->join('rubric_criteria as c', 'c.id', '=', 's.criterion_id')->whereColumn('a.rubric_id', '!=', 'c.rubric_id')->count(),
                'ai_assessments_wrong_organization' => DB::table('ai_assessments as ai')->join('submissions as sub', 'sub.id', '=', 'ai.submission_id')->join('assignments as a', 'a.id', '=', 'sub.assignment_id')->join('rubrics as r', 'r.id', '=', 'ai.rubric_id')->whereColumn('a.organization_id', '!=', 'r.organization_id')->count(),
                'student_stores_cross_school' => DB::table('student_stores as st')->join('students as s', 's.id', '=', 'st.student_id')->join('schools as sc', 'sc.id', '=', 'st.school_id')->where(fn ($q) => $q->whereColumn('st.school_id', '!=', 's.school_id')->orWhereColumn('st.organization_id', '!=', 'sc.organization_id'))->count(),
                'store_submissions_cross_school' => DB::table('student_store_submissions as sub')->join('students as s', 's.id', '=', 'sub.student_id')->join('assignments as a', 'a.id', '=', 'sub.assignment_id')->join('courses as c', 'c.id', '=', 'a.course_id')->leftJoin('student_stores as st', 'st.id', '=', 'sub.student_store_id')->where(fn ($q) => $q->whereColumn('sub.school_id', '!=', 's.school_id')->orWhereColumn('sub.school_id', '!=', 'c.school_id')->orWhereColumn('sub.organization_id', '!=', 'a.organization_id')->orWhere(fn ($q) => $q->whereNotNull('sub.student_store_id')->where(fn ($q) => $q->whereNull('st.id')->orWhereColumn('st.student_id', '!=', 'sub.student_id'))))->count(),
                'store_responses_wrong_template' => DB::table('store_checklist_responses as r')->join('student_store_submissions as sub', 'sub.id', '=', 'r.store_submission_id')->join('assignments as a', 'a.id', '=', 'sub.assignment_id')->join('store_checklist_items as i', 'i.id', '=', 'r.item_id')->whereColumn('i.template_id', '!=', 'a.checklist_template_id')->count(),
                'store_review_scores_wrong_rubric' => DB::table('store_review_scores as s')->join('store_reviews as r', 'r.id', '=', 's.review_id')->join('rubric_criteria as c', 'c.id', '=', 's.criterion_id')->whereColumn('r.rubric_id', '!=', 'c.rubric_id')->count(),
                'class_enrollments_cross_school' => DB::table('class_enrollments as e')->join('students as st', 'st.id', '=', 'e.student_id')->join('classes as c', 'c.id', '=', 'e.class_id')->where(fn ($q) => $q->whereColumn('e.school_id', '!=', 'st.school_id')->orWhereColumn('e.school_id', '!=', 'c.school_id'))->count(),
                'academic_years_missing_school' => DB::table('academic_years as y')->leftJoin('schools as s', 's.id', '=', 'y.school_id')->whereNull('s.id')->count(),
                'semesters_missing_year' => DB::table('semesters as sem')->leftJoin('academic_years as y', 'y.id', '=', 'sem.academic_year_id')->whereNull('y.id')->count(),
                'class_enrollments_wrong_calendar' => DB::table('class_enrollments as e')->leftJoin('academic_years as y', 'y.id', '=', 'e.academic_year_id')->leftJoin('semesters as sem', 'sem.id', '=', 'e.semester_id')->where(fn ($q) => $q->where(fn ($q) => $q->whereNotNull('e.academic_year_id')->where(fn ($q) => $q->whereNull('y.id')->orWhereColumn('y.school_id', '!=', 'e.school_id')))->orWhere(fn ($q) => $q->whereNotNull('e.semester_id')->where(fn ($q) => $q->whereNull('sem.id')->orWhereColumn('sem.academic_year_id', '!=', 'e.academic_year_id'))))->count(),
                'course_enrollments_cross_school' => DB::table('course_enrollments as e')->join('students as st', 'st.id', '=', 'e.student_id')->join('courses as c', 'c.id', '=', 'e.course_id')->where(fn ($q) => $q->whereColumn('e.school_id', '!=', 'st.school_id')->orWhereColumn('e.school_id', '!=', 'c.school_id'))->count(),
                'teacher_assignments_cross_school' => DB::table('teacher_assignments as a')->join('teachers as t', 't.id', '=', 'a.teacher_id')->join('classes as c', 'c.id', '=', 'a.class_id')->leftJoin('courses as co', 'co.id', '=', 'a.course_id')->where(fn ($q) => $q->whereColumn('a.school_id', '!=', 't.school_id')->orWhereColumn('a.school_id', '!=', 'c.school_id')->orWhere(fn ($q) => $q->whereNotNull('a.course_id')->where(fn ($q) => $q->whereNull('co.id')->orWhereColumn('a.school_id', '!=', 'co.school_id'))))->count(),
                'affiliate_snapshots_cross_school' => DB::table('affiliate_metric_snapshots as m')->join('schools as s', 's.id', '=', 'm.school_id')->join('students as st', 'st.id', '=', 'm.student_id')->join('affiliate_videos as v', 'v.id', '=', 'm.affiliate_video_id')->where(fn ($q) => $q->whereColumn('m.organization_id', '!=', 's.organization_id')->orWhereColumn('m.school_id', '!=', 'st.school_id')->orWhereColumn('m.student_id', '!=', 'v.student_id'))->count(),
                'affiliate_corrections_wrong_video' => DB::table('affiliate_metric_snapshots as m')->join('affiliate_metric_snapshots as parent', 'parent.id', '=', 'm.supersedes_id')->where(fn ($q) => $q->whereColumn('m.affiliate_video_id', '!=', 'parent.affiliate_video_id')->orWhereColumn('m.school_id', '!=', 'parent.school_id'))->count(),
                'affiliate_videos_wrong_student' => DB::table('affiliate_videos as v')->join('submissions as sub', 'sub.id', '=', 'v.submission_id')->join('students as st', 'st.id', '=', 'v.student_id')->where(fn ($q) => $q->whereColumn('sub.user_id', '!=', 'st.user_id')->orWhere(fn ($q) => $q->whereNotNull('sub.student_id')->whereColumn('sub.student_id', '!=', 'v.student_id')))->count(),
                'affiliate_flags_wrong_snapshot' => DB::table('affiliate_fraud_flags as f')->join('affiliate_videos as v', 'v.id', '=', 'f.affiliate_video_id')->leftJoin('affiliate_metric_snapshots as m', 'm.id', '=', 'f.metric_snapshot_id')->whereNotNull('f.metric_snapshot_id')->where(fn ($q) => $q->whereNull('m.id')->orWhereColumn('m.affiliate_video_id', '!=', 'v.id'))->count(),
                'import_jobs_cross_tenant' => DB::table('import_jobs as j')->join('schools as s', 's.id', '=', 'j.school_id')->join('users as u', 'u.id', '=', 'j.created_by_id')->where(fn ($q) => $q->whereColumn('j.organization_id', '!=', 's.organization_id')->orWhereColumn('j.organization_id', '!=', 'u.organization_id')->orWhere(fn ($q) => $q->whereNotNull('u.school_id')->whereColumn('j.school_id', '!=', 'u.school_id')))->count(),
                'affiliate_snapshots_wrong_import_job' => DB::table('affiliate_metric_snapshots as m')->leftJoin('import_jobs as j', 'j.id', '=', 'm.import_job_id')->whereNotNull('m.import_job_id')->where(fn ($q) => $q->whereNull('j.id')->orWhereColumn('m.school_id', '!=', 'j.school_id')->orWhereColumn('m.organization_id', '!=', 'j.organization_id'))->count(),
                'access_grants_cross_school' => DB::table('access_grants as g')->join('schools as s', 's.id', '=', 'g.school_id')->where('g.target_type', 'SCHOOL')->whereColumn('g.organization_id', '!=', 's.organization_id')->count(),
                'user_roles_cross_organization' => DB::table('user_roles as ur')->join('users as u', 'u.id', '=', 'ur.user_id')->join('roles as r', 'r.id', '=', 'ur.role_id')->whereNotNull('r.organization_id')->whereColumn('u.organization_id', '!=', 'r.organization_id')->count(),
            ];
            foreach ($checks as $name => $count) if ($count > 0) $defects[$name] = $count;
        }

        $this->line(json_encode(['tables' => $results, 'defects' => $defects, 'baselineCompared' => $baseline !== null], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if (! $baseline) $this->warn('Audit struktural saja: baseline sumber diperlukan untuk rekonsiliasi ID.');
        return $defects ? self::FAILURE : self::SUCCESS;
    }
}
