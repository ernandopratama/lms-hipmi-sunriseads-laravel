<?php

namespace Tests\Feature\SchoolData;

use App\Jobs\ProcessStudentImport;
use App\Support\IdentifierCrypto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** The Vue form parses the CSV in the browser and posts the rows as JSON; this covers that endpoint and its job. */
class StudentImportTest extends TestCase
{
    use SchoolDataHelpers;

    private function row(string $n, array $extra = []): array
    {
        return $extra + ['email' => "siswa$n@import.example", 'studentNumber' => "IMP-$n", 'fullName' => "Siswa Import $n"];
    }

    private function import(array $rows)
    {
        return $this->postJson('/api/backend/imports/students', ['sourceName' => 'siswa.csv', 'rows' => $rows]);
    }

    public function test_guest_cannot_import(): void
    {
        $this->import([$this->row('1')])->assertUnauthorized();
    }

    public function test_teacher_cannot_import(): void
    {
        $this->loginAs('teacher');
        $this->import([$this->row('1')])->assertForbidden();
        $this->assertSame(0, DB::table('import_jobs')->count());
    }

    public function test_invalid_rows_payload_is_rejected_and_nothing_is_queued(): void
    {
        $this->loginAs('school-admin');
        $this->import([$this->row('1'), ['email' => 'bukan-email', 'studentNumber' => 'X', 'fullName' => 'Y']])
            ->assertUnprocessable()->assertJsonValidationErrors('rows.1.email');
        $this->import([])->assertUnprocessable()->assertJsonValidationErrors('rows');
        $this->assertSame(0, DB::table('import_jobs')->count());
    }

    public function test_import_is_queued_with_encrypted_nisn(): void
    {
        Queue::fake();
        $this->loginAs('school-admin');
        $this->import([$this->row('1', ['email' => ' Siswa1@Import.Example ', 'nisn' => '1234567890'])])->assertCreated()
            ->assertJsonPath('status', 'QUEUED')->assertJsonPath('totalRows', 1)->assertJsonPath('sourceName', 'siswa.csv');

        Queue::assertPushed(ProcessStudentImport::class);
        $payload = DB::table('import_rows')->value('payload');
        $this->assertStringNotContainsString('1234567890', $payload);
        $p = json_decode($payload, true);
        $this->assertSame('siswa1@import.example', $p['email']);
        $this->assertSame('7890', $p['nisnLast4']);
        $this->assertSame('1234567890', IdentifierCrypto::decrypt($p['nisnEncrypted']));
    }

    public function test_valid_rows_create_pending_students_and_invalid_rows_are_reported(): void
    {
        $classId = $this->makeClass($this->demoSchoolId(), 'X-RPL-1');
        $this->loginAs('school-admin');
        $id = $this->import([
            $this->row('1', ['classCode' => 'X-RPL-1', 'nisn' => '1234567890']),
            $this->row('2', ['email' => 'student@sunrise.example']),   // existing account
            $this->row('3', ['classCode' => 'TIDAK-ADA']),
            $this->row('4', ['email' => 'siswa1@import.example']),     // duplicate inside the file
        ])->assertCreated()->json('id');

        $this->getJson("/api/backend/imports/$id")->assertOk()
            ->assertJsonPath('status', 'COMPLETED_WITH_ERRORS')->assertJsonPath('totalRows', 4)
            ->assertJsonPath('validRows', 1)->assertJsonPath('failedRows', 3)->assertJsonPath('processedRows', 4)
            ->assertJsonCount(3, 'rows')
            ->assertJsonPath('rows.0.rowNumber', 3)->assertJsonPath('rows.0.errors.codes', ['DUPLICATE_STUDENT'])
            ->assertJsonPath('rows.1.errors.codes', ['CLASS_NOT_FOUND'])
            ->assertJsonPath('rows.2.errors.codes', ['DUPLICATE_STUDENT']);

        $student = DB::table('students')->where('student_number', 'IMP-1')->first();
        $this->assertSame('DRAFT', $student->status);
        $this->assertSame($classId, $student->class_id);
        $this->assertSame('pending_activation', DB::table('users')->where('id', $student->user_id)->value('status'));
        $this->assertTrue(DB::table('class_enrollments')->where('student_id', $student->id)->where('class_id', $classId)->where('status', 'ACTIVE')->exists());
        $this->assertTrue(DB::table('invitations')->where('user_id', $student->user_id)->exists());
        $this->assertSame('7890', DB::table('student_identifiers')->where('student_id', $student->id)->where('type', 'NISN')->value('last4'));
        $this->assertFalse(DB::table('students')->whereIn('student_number', ['IMP-2', 'IMP-3', 'IMP-4'])->exists());
        // Imported rows are rewritten without the encrypted NISN.
        $this->assertStringNotContainsString('nisnEncrypted', DB::table('import_rows')->where('import_job_id', $id)->where('row_number', 2)->value('payload'));
    }

    public function test_duplicate_nisn_fails_the_row(): void
    {
        $this->loginAs('school-admin');
        $this->import([$this->row('1', ['nisn' => '1234567890'])])->assertCreated();
        $id = $this->import([$this->row('2', ['nisn' => '1234567890'])])->json('id');

        $this->getJson("/api/backend/imports/$id")->assertJsonPath('status', 'COMPLETED_WITH_ERRORS')->assertJsonPath('rows.0.errors.codes', ['DUPLICATE_STUDENT'])
            ->assertJsonMissingPath('rows.0.payload.nisnEncrypted');
    }

    public function test_all_valid_rows_complete_the_job(): void
    {
        $this->loginAs('school-admin');
        $id = $this->import([$this->row('1'), $this->row('2')])->json('id');

        $this->getJson('/api/backend/imports')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.id', $id)->assertJsonPath('0.status', 'COMPLETED')->assertJsonPath('0.validRows', 2);
        $this->assertSame(2, DB::table('students')->where('school_id', $this->demoSchoolId())->where('student_number', 'like', 'IMP-%')->count());
    }

    public function test_import_jobs_of_another_school_are_hidden(): void
    {
        $this->loginAs('school-admin');
        $id = $this->import([$this->row('1')])->json('id');

        $this->login($this->otherSchool()['admin']);
        $this->getJson('/api/backend/imports')->assertOk()->assertJsonCount(0);
        $this->getJson("/api/backend/imports/$id")->assertNotFound();
    }
}
