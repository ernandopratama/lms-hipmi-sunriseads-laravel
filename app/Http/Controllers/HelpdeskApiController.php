<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HelpdeskApiController extends Controller
{
    public function __construct(private ApiContext $context) {}

    private function staff(array $c): bool
    {
        return (bool) array_intersect($c['roles'], ['super-admin', 'program-manager', 'school-admin', 'principal', 'support']);
    }

    private function tickets(array $c)
    {
        $q = DB::table('helpdesk_tickets')->where('organization_id', $c['organization']);
        if ($this->staff($c)) return $q->where(fn ($q) => $q->where('school_id', $c['school'])->orWhereNull('school_id'));
        return $q->where('requester_id', $c['user']);
    }

    private function person(?string $id): ?array
    {
        $u = $id ? DB::table('users')->where('id', $id)->first() : null;
        return $u ? ['firstName' => $u->first_name, 'lastName' => $u->last_name] : null;
    }

    private function ticket(object $r, bool $detail = false, ?array $c = null): array
    {
        $data = ['id' => $r->id, 'organizationId' => $r->organization_id, 'schoolId' => $r->school_id,
            'requesterId' => $r->requester_id, 'assignedToId' => $r->assigned_to_id,
            'ticketNumber' => $r->ticket_number, 'category' => $r->category,
            'priority' => $r->priority, 'status' => $r->status, 'subject' => $r->subject,
            'description' => $r->description, 'slaDueAt' => $r->sla_due_at,
            'resolution' => $r->resolution, 'resolvedAt' => $r->resolved_at,
            'satisfaction' => $r->satisfaction, 'createdAt' => $r->created_at,
            'updatedAt' => $r->updated_at, 'requester' => $this->person($r->requester_id),
            'assignedTo' => $this->person($r->assigned_to_id)];
        if ($detail) $data['messages'] = DB::table('helpdesk_messages')->where('ticket_id', $r->id)
            ->when(! $this->staff($c), fn ($q) => $q->where('is_internal', false))
            ->orderBy('created_at')->get()->map(fn ($m) => $this->message($m));
        else $data['_count'] = ['messages' => DB::table('helpdesk_messages')->where('ticket_id', $r->id)->count()];
        return $data;
    }

    private function message(object $m): array
    {
        return ['id' => $m->id, 'ticketId' => $m->ticket_id, 'authorId' => $m->author_id,
            'body' => $m->body, 'isInternal' => (bool) $m->is_internal,
            'storageKey' => $m->storage_key, 'quarantineStorageKey' => $m->quarantine_storage_key,
            'fileName' => $m->file_name, 'mimeType' => $m->mime_type,
            'sizeBytes' => $m->size_bytes, 'scanStatus' => $m->scan_status,
            'createdAt' => $m->created_at, 'author' => $this->person($m->author_id)];
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/\b\d{10,18}\b/u', '[IDENTIFIER DIHAPUS]',
            preg_replace('/\b(password|kata\s+sandi|pin|otp|token|cookie|recovery\s*code|api\s*secret|nik|nisn|npsn)\b\s*[:=]?\s*\S+/iu',
                '$1 [DIHAPUS]', $value)));
    }

    public function index(Request $request)
    {
        $c = $this->context->resolve($request, 'helpdesk', 'read');
        return response()->json($this->tickets($c)->orderByDesc('updated_at')->limit(100)->get()
            ->map(fn ($r) => $this->ticket($r)));
    }

    public function show(Request $request, string $id)
    {
        $c = $this->context->resolve($request, 'helpdesk', 'read');
        $r = $this->tickets($c)->where('id', $id)->first();
        abort_unless($r, 404, 'Tiket tidak ditemukan');
        return response()->json($this->ticket($r, true, $c));
    }

    private function notify(array $c, string $user, string $type, string $title, string $body, string $key): void
    {
        $id = (string) Str::ulid();
        if (DB::table('notifications')->insertOrIgnore(['id' => $id, 'organization_id' => $c['organization'],
            'school_id' => $c['school'], 'user_id' => $user, 'event_type' => $type,
            'title' => $title, 'body' => $body, 'action_url' => '/dashboard/dukungan',
            'idempotency_key' => $key, 'created_at' => now()]))
            DB::table('notification_deliveries')->insert(['id' => (string) Str::ulid(), 'notification_id' => $id,
                'channel' => 'IN_APP', 'status' => 'SENT', 'provider' => 'INTERNAL',
                'sent_at' => now(), 'scheduled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function store(Request $request)
    {
        $c = $this->context->resolve($request, 'helpdesk', 'create');
        $d = $request->validate(['category' => 'required|string|min:2|max:80',
            'priority' => 'sometimes|in:LOW,NORMAL,HIGH,URGENT', 'subject' => 'required|string|min:5|max:180',
            'description' => 'required|string|min:10|max:5000']);
        $id = (string) Str::ulid();
        $number = 'HD-'.now()->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
        $hours = match ($d['priority'] ?? 'NORMAL') { 'URGENT' => 4, 'HIGH' => 12, default => 48 };
        DB::transaction(function () use ($c, $d, $id, $number, $hours) {
            DB::table('helpdesk_tickets')->insert(['id' => $id, 'organization_id' => $c['organization'],
                'school_id' => $c['school'], 'requester_id' => $c['user'], 'ticket_number' => $number,
                'category' => trim($d['category']), 'priority' => $d['priority'] ?? 'NORMAL',
                'subject' => $this->clean($d['subject']), 'description' => $this->clean($d['description']),
                'sla_due_at' => now()->addHours($hours), 'status' => 'OPEN',
                'created_at' => now(), 'updated_at' => now()]);
            $responders = DB::table('users as u')->join('user_roles as ur', 'ur.user_id', '=', 'u.id')
                ->join('roles as role', 'role.id', '=', 'ur.role_id')
                ->where('u.organization_id', $c['organization'])->where('u.status', 'active')
                ->whereIn('role.slug', ['support', 'school-admin', 'super-admin'])
                ->where(fn ($q) => $q->where('ur.school_id', $c['school'])->orWhereNull('ur.school_id'))
                ->distinct()->limit(50)->pluck('u.id');
            foreach ($responders as $user) $this->notify($c, $user, 'HELPDESK_CREATED',
                'Tiket baru '.$number, $this->clean($d['subject']), "helpdesk-created:$id:$user");
        });
        return response()->json($this->ticket(DB::table('helpdesk_tickets')->where('id', $id)->first()), 201);
    }

    public function messageStore(Request $request, string $id)
    {
        $c = $this->context->resolve($request, 'helpdesk', 'create');
        $d = $request->validate(['body' => 'required|string|min:2|max:5000', 'isInternal' => 'sometimes|boolean']);
        $r = $this->tickets($c)->where('id', $id)->first();
        abort_unless($r, 404, 'Tiket tidak ditemukan');
        abort_if(in_array($r->status, ['RESOLVED', 'CLOSED']), 409, 'Tiket sudah ditutup');
        abort_if(($d['isInternal'] ?? false) && ! $this->staff($c), 403, 'Catatan internal hanya untuk petugas');
        $messageId = (string) Str::ulid();
        DB::transaction(function () use ($c, $d, $r, $id, $messageId) {
            DB::table('helpdesk_messages')->insert(['id' => $messageId, 'ticket_id' => $id,
                'author_id' => $c['user'], 'body' => $this->clean($d['body']),
                'is_internal' => $d['isInternal'] ?? false, 'created_at' => now()]);
            $target = $c['user'] === $r->requester_id ? $r->assigned_to_id : $r->requester_id;
            if ($target) $this->notify($c, $target, 'HELPDESK_UPDATE',
                'Pembaruan tiket '.$r->ticket_number, 'Ada balasan baru pada tiket bantuan Anda.',
                "helpdesk-message:$messageId:$target");
        });
        return response()->json($this->message(DB::table('helpdesk_messages')->where('id', $messageId)->first()), 201);
    }

    public function update(Request $request, string $id)
    {
        $c = $this->context->resolve($request, 'helpdesk', 'update');
        $d = $request->validate(['status' => 'sometimes|in:OPEN,IN_PROGRESS,WAITING_REQUESTER,RESOLVED,CLOSED',
            'priority' => 'sometimes|in:LOW,NORMAL,HIGH,URGENT', 'assignedToId' => 'sometimes|string',
            'resolution' => 'sometimes|string|max:2000', 'satisfaction' => 'sometimes|integer|min:1|max:5']);
        $r = $this->tickets($c)->where('id', $id)->first();
        abort_unless($r, 404, 'Tiket tidak ditemukan');
        if (! $this->staff($c)) {
            abort_unless($r->requester_id === $c['user'] && array_keys($d) === ['satisfaction'],
                403, 'Anda tidak dapat mengubah penanganan tiket');
            abort_unless(in_array($r->status, ['RESOLVED', 'CLOSED']) && $r->satisfaction === null,
                409, 'Penilaian hanya dapat diberikan satu kali setelah tiket selesai');
        } else {
            abort_if(in_array($r->status, ['RESOLVED', 'CLOSED']) && isset($d['status']) && $d['status'] !== $r->status,
                409, 'Status akhir tiket tidak dapat dibuka kembali');
            abort_if(($d['status'] ?? null) === 'RESOLVED' && ! trim($d['resolution'] ?? $r->resolution ?? ''),
                400, 'Ringkasan penyelesaian wajib diisi');
            if (isset($d['assignedToId'])) {
                $valid = DB::table('users as u')->join('user_roles as ur', 'ur.user_id', '=', 'u.id')
                    ->join('roles as role', 'role.id', '=', 'ur.role_id')
                    ->where('u.id', $d['assignedToId'])->where('u.organization_id', $c['organization'])
                    ->where('u.status', 'active')->whereIn('role.slug', ['support', 'school-admin', 'principal', 'program-manager', 'super-admin'])
                    ->where(fn ($q) => $q->where('ur.school_id', $r->school_id)->orWhereNull('ur.school_id'))->exists();
                abort_unless($valid, 400, 'Petugas tidak tersedia');
            }
        }
        $values = [];
        foreach (['status' => 'status', 'priority' => 'priority', 'assignedToId' => 'assigned_to_id',
            'resolution' => 'resolution', 'satisfaction' => 'satisfaction'] as $input => $column)
            if (array_key_exists($input, $d)) $values[$column] = $input === 'resolution' ? $this->clean($d[$input]) : $d[$input];
        if (($d['status'] ?? null) === 'RESOLVED') $values['resolved_at'] = now();
        DB::table('helpdesk_tickets')->where('id', $id)->update([...$values, 'updated_at' => now()]);
        return response()->json($this->ticket(DB::table('helpdesk_tickets')->where('id', $id)->first()));
    }
}
