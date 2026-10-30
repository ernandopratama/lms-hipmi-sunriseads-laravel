<?php

namespace Tests\Feature\SchoolData;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Academic years, classes, students and class enrollment (master data). */
class MasterDataTest extends TestCase
{
    use SchoolDataHelpers;

    private function demoStudentId(): string
    {
        return DB::table('students')->where('user_id', $this->demoUser('student')->id)->value('id');
    }

    public function test_guest_and_non_admin_roles_cannot_read_master_data(): void
    {
        $this->getJson('/api/backend/classes')->assertUnauthorized();
        $this->loginAs('teacher');
        $this->getJson('/api/backend/classes')->assertForbidden();
        $this->getJson('/api/backend/students')->assertForbidden();
        $this->getJson('/api/backend/master-data')->assertForbidden();
        $this->login($this->demoUser('parent'));
        $this->getJson('/api/backend/academic-years')->assertForbidden();
    }

    public function test_teacher_cannot_create_a_class(): void
    {
        $this->loginAs('teacher');
        $this->postJson('/api/backend/classes', ['name' => 'X RPL 1', 'code' => 'X-RPL-1'])->assertForbidden();
        $this->assertSame(0, DB::table('classes')->count());
    }

    public function test_admin_creates_an_academic_year_and_a_semester(): void
    {
        $this->loginAs('school-admin');
        $year = $this->postJson('/api/backend/academic-years', ['name' => '2026/2027', 'startDate' => '2026-07-01', 'endDate' => '2027-06-30'])
            ->assertCreated()->assertJsonPath('name', '2026/2027')->assertJsonPath('isActive', false)->assertJsonPath('schoolId', $this->demoSchoolId())->json('id');

        $this->postJson("/api/backend/academic-years/$year/semesters", ['name' => 'Ganjil', 'startDate' => '2026-07-01', 'endDate' => '2026-12-31'])
            ->assertCreated()->assertJsonPath('academicYearId', $year);
        $this->getJson('/api/backend/academic-years')->assertOk()->assertJsonCount(1)->assertJsonPath('0.semesters.0.name', 'Ganjil');
    }

    public function test_academic_year_end_must_follow_start(): void
    {
        $this->loginAs('school-admin');
        $this->postJson('/api/backend/academic-years', ['name' => '2026/2027', 'startDate' => '2027-06-30', 'endDate' => '2026-07-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('endDate');
        $this->assertSame(0, DB::table('academic_years')->count());
    }

    public function test_semester_outside_the_academic_year_is_rejected(): void
    {
        $this->loginAs('school-admin');
        $year = $this->postJson('/api/backend/academic-years', ['name' => '2026/2027', 'startDate' => '2026-07-01', 'endDate' => '2027-06-30'])->json('id');
        $this->postJson("/api/backend/academic-years/$year/semesters", ['name' => 'Genap', 'startDate' => '2027-01-01', 'endDate' => '2027-08-31'])
            ->assertUnprocessable()->assertJsonPath('message', 'Semester harus berada dalam tahun ajaran');
        $this->assertSame(0, DB::table('semesters')->count());
    }

    public function test_activating_a_year_deactivates_the_previous_one(): void
    {
        $this->loginAs('school-admin');
        $a = $this->postJson('/api/backend/academic-years', ['name' => '2025/2026', 'startDate' => '2025-07-01', 'endDate' => '2026-06-30'])->json('id');
        $b = $this->postJson('/api/backend/academic-years', ['name' => '2026/2027', 'startDate' => '2026-07-01', 'endDate' => '2027-06-30'])->json('id');

        $this->putJson("/api/backend/academic-years/$a/activate")->assertOk()->assertJsonPath('isActive', true);
        $this->putJson("/api/backend/academic-years/$b/activate")->assertOk()->assertJsonPath('isActive', true);
        $this->assertSame([$b], DB::table('academic_years')->where('is_active', true)->pluck('id')->all());
    }

    public function test_academic_year_of_another_school_cannot_be_activated(): void
    {
        $this->loginAs('school-admin');
        $year = $this->postJson('/api/backend/academic-years', ['name' => '2026/2027', 'startDate' => '2026-07-01', 'endDate' => '2027-06-30'])->json('id');

        $this->login($this->otherSchool()['admin']);
        $this->putJson("/api/backend/academic-years/$year/activate")->assertNotFound();
        $this->getJson('/api/backend/academic-years')->assertOk()->assertJsonCount(0);
        $this->assertFalse((bool) DB::table('academic_years')->where('id', $year)->value('is_active'));
    }

    public function test_admin_creates_updates_and_archives_a_class(): void
    {
        $this->loginAs('school-admin');
        $id = $this->postJson('/api/backend/classes', ['name' => 'X RPL 1', 'code' => 'X-RPL-1', 'grade' => 10, 'capacity' => 36])
            ->assertCreated()->assertJsonPath('code', 'X-RPL-1')->assertJsonPath('isActive', true)->json('id');

        $this->putJson("/api/backend/classes/$id", ['name' => 'X RPL Satu'])->assertOk()->assertJsonPath('name', 'X RPL Satu')->assertJsonPath('code', 'X-RPL-1');
        $this->deleteJson("/api/backend/classes/$id")->assertOk()->assertJsonPath('isActive', false);
        $this->getJson('/api/backend/classes')->assertOk()->assertJsonCount(1)->assertJsonPath('0.isActive', false);
    }

    public function test_duplicate_class_code_is_rejected(): void
    {
        $this->makeClass($this->demoSchoolId(), 'X-RPL-1');
        $this->loginAs('school-admin');
        $this->postJson('/api/backend/classes', ['name' => 'Lain', 'code' => 'X-RPL-1'])->assertUnprocessable()->assertJsonPath('message', 'Kode kelas sudah digunakan');
        $this->postJson('/api/backend/classes', ['name' => 'Tanpa kode'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertSame(1, DB::table('classes')->count());
    }

    public function test_class_of_another_school_cannot_be_changed(): void
    {
        $other = $this->otherSchool();
        $foreign = $this->makeClass($other['school'], 'XI-TKJ');
        $this->loginAs('school-admin');

        $this->putJson("/api/backend/classes/$foreign", ['name' => 'Diambil alih'])->assertNotFound();
        $this->deleteJson("/api/backend/classes/$foreign")->assertNotFound();
        $this->getJson('/api/backend/classes')->assertOk()->assertJsonCount(0);
        $this->assertSame('Kelas XI-TKJ', DB::table('classes')->where('id', $foreign)->value('name'));
    }

    public function test_admin_enrolls_a_student_into_a_class_and_transfers_them(): void
    {
        $first = $this->makeClass($this->demoSchoolId(), 'X-RPL-1');
        $second = $this->makeClass($this->demoSchoolId(), 'X-RPL-2');
        $student = $this->demoStudentId();
        $this->loginAs('school-admin');

        $this->postJson('/api/backend/master-data/enrollments', ['studentId' => $student, 'classId' => $first, 'effectiveFrom' => '2026-07-01'])
            ->assertCreated()->assertJsonPath('status', 'ACTIVE')->assertJsonPath('classId', $first);
        $this->postJson('/api/backend/master-data/enrollments', ['studentId' => $student, 'classId' => $second, 'effectiveFrom' => '2026-08-01'])->assertCreated();

        $this->assertSame($second, DB::table('students')->where('id', $student)->value('class_id'));
        $this->assertSame('TRANSFERRED', DB::table('class_enrollments')->where('student_id', $student)->where('class_id', $first)->value('status'));
        $this->assertSame('ACTIVE', DB::table('class_enrollments')->where('student_id', $student)->where('class_id', $second)->value('status'));
        $this->getJson("/api/backend/students/$student")->assertOk()->assertJsonPath('class.code', 'X-RPL-2');
        $this->getJson('/api/backend/master-data/lists/classes')->assertOk()->assertJsonPath('total', 2);
    }

    public function test_enrollment_rejects_a_student_or_class_of_another_school(): void
    {
        $other = $this->otherSchool();
        $foreignStudent = $this->makeStudent($other['school'], 'LAIN-1');
        $foreignClass = $this->makeClass($other['school'], 'XI-TKJ');
        $ownClass = $this->makeClass($this->demoSchoolId(), 'X-RPL-1');
        $this->loginAs('school-admin');

        $this->postJson('/api/backend/master-data/enrollments', ['studentId' => $foreignStudent, 'classId' => $ownClass, 'effectiveFrom' => '2026-07-01'])->assertNotFound();
        $this->postJson('/api/backend/master-data/enrollments', ['studentId' => $this->demoStudentId(), 'classId' => $foreignClass, 'effectiveFrom' => '2026-07-01'])->assertNotFound();
        $this->postJson('/api/backend/master-data/enrollments', ['studentId' => $this->demoStudentId(), 'classId' => $ownClass])->assertUnprocessable();
        $this->assertSame(0, DB::table('class_enrollments')->count());
    }

    public function test_student_list_is_limited_to_the_school(): void
    {
        $this->makeStudent($this->otherSchool()['school'], 'LAIN-1');
        $this->loginAs('school-admin');

        $this->getJson('/api/backend/students')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.studentNumber', 'DEMO-001')->assertJsonPath('data.0.user.email', 'student@sunrise.example');
        $this->getJson('/api/backend/master-data')->assertOk()->assertJsonPath('counts.students', 1)->assertJsonPath('counts.parents', 1);
    }

    public function test_student_of_another_school_cannot_be_read_or_changed(): void
    {
        $foreign = $this->makeStudent($this->otherSchool()['school'], 'LAIN-1');
        $this->loginAs('school-admin');

        $this->getJson("/api/backend/students/$foreign")->assertNotFound();
        $this->putJson("/api/backend/students/$foreign", ['fullName' => 'Diubah'])->assertNotFound();
        $this->deleteJson("/api/backend/students/$foreign")->assertNotFound();
        $this->assertSame('ACTIVE', DB::table('students')->where('id', $foreign)->value('status'));
    }

    public function test_admin_of_another_organization_sees_no_students(): void
    {
        $this->login($this->otherOrganization()['admin']);
        $this->getJson('/api/backend/students')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/backend/students/'.$this->demoStudentId())->assertNotFound();
    }

    public function test_student_list_validates_paging_and_status(): void
    {
        $this->loginAs('school-admin');
        $this->getJson('/api/backend/students?take=101')->assertUnprocessable()->assertJsonValidationErrors('take');
        $this->getJson('/api/backend/students?status=UNKNOWN')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->getJson('/api/backend/students?status=ACTIVE&skip=0&take=50')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_admin_creates_a_student_profile_with_nisn(): void
    {
        $user = $this->makeUser('student', $this->demoOrgId(), $this->demoSchoolId());
        $this->loginAs('school-admin');

        $id = $this->postJson('/api/backend/students', ['userId' => $user->id, 'studentNumber' => 'NEW-1', 'fullName' => 'Siswa Baru', 'nisn' => '0012345678'])
            ->assertCreated()->assertJsonPath('status', 'DRAFT')->assertJsonPath('studentNumber', 'NEW-1')->json('id');
        $this->assertStringNotContainsString('0012345678', (string) DB::table('student_identifiers')->where('student_id', $id)->value('value_encrypted'));

        $this->postJson('/api/backend/students', ['userId' => $this->makeUser('student', $this->demoOrgId(), $this->demoSchoolId())->id, 'studentNumber' => 'NEW-1', 'fullName' => 'Kembar'])
            ->assertUnprocessable()->assertJsonPath('message', 'Nomor siswa sudah digunakan');
        $this->putJson('/api/backend/students/'.$this->demoStudentId(), ['nisn' => '0012345678'])->assertUnprocessable()->assertJsonPath('message', 'NISN sudah digunakan');
    }

    public function test_archiving_a_student_deactivates_the_account(): void
    {
        $student = $this->demoStudentId();
        $this->loginAs('school-admin');

        $this->deleteJson("/api/backend/students/$student")->assertOk()->assertJsonPath('status', 'INACTIVE');
        $this->assertSame('inactive', $this->demoUser('student')->status);
    }
}
