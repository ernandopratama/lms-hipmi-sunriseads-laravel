<?php

namespace Tests\Feature\Support;

use App\Models\Organization;
use App\Models\School;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HelpdeskTest extends TestCase
{
    use SupportFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('helpdesk_quarantine');
        Storage::fake('helpdesk_private');
    }

    private function ticket(string $role = 'student', array $body = []): string
    {
        $this->loginAs($role);

        return $this->postJson('/api/backend/support/helpdesk', [...['category' => 'Akun', 'subject' => 'Tidak bisa masuk',
            'description' => 'Saya tidak bisa masuk ke akun sejak kemarin.'], ...$body])->assertCreated()->json('id');
    }

    /** Runs the upload-url -> PUT -> complete flow and returns the completed message id. */
    private function attach(string $ticketId, string $bytes, string $mime = 'application/pdf', string $name = 'bukti.pdf'): string
    {
        $up = $this->postJson("/api/backend/support/helpdesk/$ticketId/attachments/upload-url",
            ['fileName' => $name, 'mimeType' => $mime, 'sizeBytes' => strlen($bytes)])->assertOk()->assertJsonPath('expiresIn', 300);
        $this->call('PUT', $up->json('uploadUrl'), [], [], [], ['CONTENT_TYPE' => $mime], $bytes)->assertNoContent();

        return $this->postJson("/api/backend/support/helpdesk/$ticketId/attachments", ['storageKey' => $up->json('storageKey'),
            'fileName' => $name, 'mimeType' => $mime, 'sizeBytes' => strlen($bytes)])
            ->assertCreated()->assertJsonPath('scanStatus', 'PENDING')->json('id');
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/backend/support/helpdesk')->assertUnauthorized();
    }

    public function test_student_opens_ticket_with_redaction_and_staff_are_notified(): void
    {
        $id = $this->ticket('student', ['priority' => 'URGENT', 'description' => 'Tolong, password: Rahasia123 dan NIK 3174000012345678']);

        $row = DB::table('helpdesk_tickets')->where('id', $id)->first();
        $this->assertSame('Tolong, password [DIHAPUS] dan NIK [DIHAPUS]', $row->description);
        $this->assertSame('OPEN', $row->status);
        $this->assertMatchesRegularExpression('/^HD-\d{8}-[0-9A-F]{8}$/', $row->ticket_number);
        $this->assertEqualsWithDelta(now()->addHours(4)->timestamp, strtotime($row->sla_due_at), 60);
        foreach (['support', 'school-admin', 'super-admin'] as $role) {
            $this->assertDatabaseHas('notifications', ['user_id' => $this->demoUser($role)->id, 'event_type' => 'HELPDESK_CREATED']);
        }
        $this->assertDatabaseMissing('notifications', ['user_id' => $this->demoUser('teacher')->id]);
    }

    public function test_invalid_ticket_is_rejected(): void
    {
        $this->loginAs('student');
        $this->postJson('/api/backend/support/helpdesk', ['category' => 'A', 'subject' => 'abc', 'description' => 'pendek', 'priority' => 'MAX'])
            ->assertUnprocessable()->assertJsonValidationErrors(['category', 'subject', 'description', 'priority']);
        $this->assertSame(0, DB::table('helpdesk_tickets')->count());
    }

    public function test_non_staff_see_only_their_own_tickets(): void
    {
        $id = $this->ticket('student');

        foreach (['parent', 'teacher'] as $role) {
            $this->loginAs($role);
            $this->getJson('/api/backend/support/helpdesk')->assertOk()->assertJsonCount(0);
            $this->getJson("/api/backend/support/helpdesk/$id")->assertNotFound()->assertJsonPath('message', 'Tiket tidak ditemukan');
            $this->postJson("/api/backend/support/helpdesk/$id/messages", ['body' => 'Ikut balas'])->assertNotFound();
        }
        $this->loginAs('support');
        $this->getJson('/api/backend/support/helpdesk')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.id', $id)->assertJsonPath('0._count.messages', 0);
    }

    public function test_staff_of_another_organization_cannot_see_ticket(): void
    {
        $id = $this->ticket('student');
        $org = Organization::create(['name' => 'Organisasi Lain']);
        $school = School::create(['organization_id' => $org->id, 'name' => 'Sekolah Organisasi Lain']);
        Artisan::call('sunrise:seed-baseline', ['--organization' => $org->id]);
        $this->makeUser('support@lain.example', $org->id, $school->id, 'support');
        $this->postJson('/api/session', ['email' => 'support@lain.example', 'password' => 'DemoLogin#2026'])->assertOk();

        $this->getJson('/api/backend/support/helpdesk')->assertOk()->assertJsonCount(0);
        $this->getJson("/api/backend/support/helpdesk/$id")->assertNotFound();
        $this->patchJson("/api/backend/support/helpdesk/$id", ['status' => 'IN_PROGRESS'])->assertNotFound();
    }

    public function test_reply_and_internal_notes(): void
    {
        $id = $this->ticket('student');
        $this->postJson("/api/backend/support/helpdesk/$id/messages", ['body' => 'Catatan', 'isInternal' => true])
            ->assertForbidden()->assertJsonPath('message', 'Catatan internal hanya untuk petugas');
        $this->postJson("/api/backend/support/helpdesk/$id/messages", ['body' => 'Masih belum bisa'])->assertCreated()
            ->assertJsonPath('isInternal', false);

        $this->loginAs('support');
        $this->postJson("/api/backend/support/helpdesk/$id/messages", ['body' => 'Cek log server', 'isInternal' => true])->assertCreated();
        $this->postJson("/api/backend/support/helpdesk/$id/messages", ['body' => 'Sudah kami reset'])->assertCreated();
        $this->assertDatabaseHas('notifications', ['user_id' => $this->demoUser('student')->id, 'event_type' => 'HELPDESK_UPDATE']);
        $this->getJson("/api/backend/support/helpdesk/$id")->assertOk()->assertJsonCount(3, 'messages');

        $this->loginAs('student');
        $this->getJson("/api/backend/support/helpdesk/$id")->assertOk()->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.1.body', 'Sudah kami reset');
    }

    public function test_requester_cannot_handle_ticket(): void
    {
        $id = $this->ticket('student');
        $this->patchJson("/api/backend/support/helpdesk/$id", ['status' => 'RESOLVED', 'resolution' => 'Selesai sendiri'])
            ->assertForbidden()->assertJsonPath('message', 'Anda tidak dapat mengubah penanganan tiket');
        $this->assertSame('OPEN', DB::table('helpdesk_tickets')->where('id', $id)->value('status'));
    }

    public function test_resolve_lifecycle_and_single_rating(): void
    {
        $id = $this->ticket('student');
        $this->patchJson("/api/backend/support/helpdesk/$id", ['satisfaction' => 5])->assertStatus(409);

        $this->loginAs('support');
        $this->patchJson("/api/backend/support/helpdesk/$id", ['status' => 'RESOLVED'])
            ->assertStatus(400)->assertJsonPath('message', 'Ringkasan penyelesaian wajib diisi');
        $this->patchJson("/api/backend/support/helpdesk/$id", ['assignedToId' => $this->demoUser('teacher')->id])
            ->assertStatus(400)->assertJsonPath('message', 'Petugas tidak tersedia');
        $this->patchJson("/api/backend/support/helpdesk/$id", ['status' => 'RESOLVED', 'resolution' => 'Kata sandi direset',
            'assignedToId' => $this->demoUser('support')->id])->assertOk()->assertJsonPath('status', 'RESOLVED');
        $this->assertNotNull(DB::table('helpdesk_tickets')->where('id', $id)->value('resolved_at'));
        $this->patchJson("/api/backend/support/helpdesk/$id", ['status' => 'OPEN'])
            ->assertStatus(409)->assertJsonPath('message', 'Status akhir tiket tidak dapat dibuka kembali');

        $this->loginAs('student');
        $this->postJson("/api/backend/support/helpdesk/$id/messages", ['body' => 'Terima kasih'])
            ->assertStatus(409)->assertJsonPath('message', 'Tiket sudah ditutup');
        $this->patchJson("/api/backend/support/helpdesk/$id", ['satisfaction' => 5])->assertOk()->assertJsonPath('satisfaction', 5);
        $this->patchJson("/api/backend/support/helpdesk/$id", ['satisfaction' => 4])->assertStatus(409)
            ->assertJsonPath('message', 'Penilaian hanya dapat diberikan satu kali setelah tiket selesai');
    }

    public function test_clean_attachment_is_scanned_and_downloadable_by_requester(): void
    {
        $id = $this->ticket('student');
        $bytes = "%PDF-1.4\nisi bukti\n%%EOF";
        $messageId = $this->attach($id, $bytes);

        $m = DB::table('helpdesk_messages')->where('id', $messageId)->first();
        $this->assertSame('CLEAN', $m->scan_status);
        $this->assertNull($m->quarantine_storage_key);
        Storage::disk('helpdesk_quarantine')->assertMissing($m->storage_key);
        Storage::disk('helpdesk_private')->assertExists($m->storage_key);

        $url = $this->getJson("/api/backend/support/helpdesk/messages/$messageId/attachment")->assertOk()->json('url');
        $res = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame($bytes, $res->getContent());

        // The signed link is bound to the user that requested it.
        $this->loginAs('support');
        $this->get($url)->assertForbidden();
    }

    public function test_infected_attachment_is_quarantined_and_blocked(): void
    {
        $id = $this->ticket('student');
        $messageId = $this->attach($id, '%PDF-1.4 X5O!P%@AP EICAR-STANDARD-ANTIVIRUS-TEST-FILE!');

        $m = DB::table('helpdesk_messages')->where('id', $messageId)->first();
        $this->assertSame('INFECTED', $m->scan_status);
        $this->assertNull($m->storage_key);
        $this->getJson("/api/backend/support/helpdesk/messages/$messageId/attachment")->assertStatus(409)
            ->assertJsonPath('message', 'Lampiran masih diperiksa atau tidak aman');
    }

    public function test_other_user_cannot_get_attachment_link(): void
    {
        $id = $this->ticket('student');
        $messageId = $this->attach($id, "%PDF-1.4\nok");
        $this->loginAs('parent');
        $this->getJson("/api/backend/support/helpdesk/messages/$messageId/attachment")->assertNotFound();
    }

    public function test_attachment_metadata_and_location_are_validated(): void
    {
        $id = $this->ticket('student');
        $other = $this->ticket('student');
        $this->postJson("/api/backend/support/helpdesk/$id/attachments/upload-url", ['fileName' => 'x.exe', 'mimeType' => 'application/x-msdownload', 'sizeBytes' => 10])
            ->assertStatus(400)->assertJsonPath('message', 'Format file tidak diizinkan');
        $this->postJson("/api/backend/support/helpdesk/$id/attachments/upload-url", ['fileName' => 'x.png', 'mimeType' => 'application/pdf', 'sizeBytes' => 10])
            ->assertStatus(400)->assertJsonPath('message', 'Ekstensi file tidak sesuai dengan format yang dipilih');

        $key = $this->postJson("/api/backend/support/helpdesk/$other/attachments/upload-url", ['fileName' => 'a.pdf', 'mimeType' => 'application/pdf', 'sizeBytes' => 5])
            ->json('storageKey');
        $this->postJson("/api/backend/support/helpdesk/$id/attachments", ['storageKey' => $key, 'fileName' => 'a.pdf', 'mimeType' => 'application/pdf', 'sizeBytes' => 5])
            ->assertForbidden()->assertJsonPath('message', 'Lokasi lampiran tidak valid');
        $this->postJson("/api/backend/support/helpdesk/$other/attachments", ['storageKey' => $key, 'fileName' => 'a.pdf', 'mimeType' => 'application/pdf', 'sizeBytes' => 5])
            ->assertNotFound()->assertJsonPath('message', 'File belum berhasil diunggah atau sudah kedaluwarsa');
        $this->assertSame(0, DB::table('helpdesk_messages')->count());
    }

    public function test_upload_rejects_size_mismatch(): void
    {
        $id = $this->ticket('student');
        $url = $this->postJson("/api/backend/support/helpdesk/$id/attachments/upload-url", ['fileName' => 'a.pdf', 'mimeType' => 'application/pdf', 'sizeBytes' => 5])
            ->json('uploadUrl');
        $this->call('PUT', $url, [], [], [], ['CONTENT_TYPE' => 'application/pdf'], '%PDF-1.4 terlalu panjang')->assertStatus(400);
    }
}
