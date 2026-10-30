<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AnnouncementApiController extends Controller
{
    public function __construct(private ApiContext $context) {}

    private function serialize(object $row, bool $include = false): array
    {
        $data = ['id' => $row->id, 'organizationId' => $row->organization_id, 'schoolId' => $row->school_id,
            'authorId' => $row->author_id, 'title' => $row->title, 'body' => $row->body,
            'audience' => $row->audience, 'status' => $row->status, 'publishedAt' => $row->published_at,
            'expiresAt' => $row->expires_at, 'createdAt' => $row->created_at, 'updatedAt' => $row->updated_at];
        if ($include) {
            $author = DB::table('users')->where('id', $row->author_id)->first();
            $school = $row->school_id ? DB::table('schools')->where('id', $row->school_id)->first() : null;
            $data['author'] = $author ? ['firstName' => $author->first_name, 'lastName' => $author->last_name] : null;
            $data['school'] = $school ? ['name' => $school->name] : null;
        }
        return $data;
    }

    public function index(Request $request)
    {
        $c = $this->context->resolve($request, 'announcements', 'read');
        $audiences = ['ALL'];
        foreach (['student' => 'STUDENTS', 'teacher' => 'TEACHERS', 'parent' => 'PARENTS'] as $role => $audience)
            if (in_array($role, $c['roles'], true)) $audiences[] = $audience;
        if (array_intersect($c['roles'], ['super-admin', 'program-manager', 'school-admin', 'principal', 'teacher', 'support']))
            $audiences[] = 'STAFF';
        return response()->json(DB::table('announcements')->where('organization_id', $c['organization'])
            ->where(fn ($q) => $q->whereNull('school_id')->orWhere('school_id', $c['school']))
            ->where('status', 'PUBLISHED')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('published_at')->limit(30)->get()->filter(fn ($row) => in_array($row->audience, $audiences, true))
            ->values()->map(fn ($row) => $this->serialize($row, true)));
    }

    public function manage(Request $request)
    {
        $c = $this->context->resolve($request, 'announcements', 'manage');
        return response()->json(DB::table('announcements')->where('organization_id', $c['organization'])
            ->where(fn ($q) => $q->whereNull('school_id')->orWhere('school_id', $c['school']))
            ->orderByDesc('created_at')->limit(50)->get()->map(fn ($row) => $this->serialize($row)));
    }

    private function clean(string $value, int $limit): string
    {
        $value = preg_replace('/\b(password|kata\s+sandi|pin|otp|token|cookie|recovery\s*code|api\s*secret|nik|nisn|npsn)\b\s*[:=]?\s*\S+/iu', '$1 [DIHAPUS]', $value);
        $value = preg_replace('/\b\d{10,18}\b/u', '[IDENTIFIER DIHAPUS]', $value);
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $value)), 0, $limit);
    }

    public function store(Request $request)
    {
        $c = $this->context->resolve($request, 'announcements', 'manage');
        $d = $request->validate(['title' => 'required|string|min:3|max:160', 'body' => 'required|string|min:3|max:5000',
            'audience' => 'sometimes|in:ALL,STUDENTS,TEACHERS,PARENTS,STAFF', 'expiresAt' => 'nullable|date|after:now',
            'publishNow' => 'sometimes|boolean']);
        $id = (string) Str::ulid();
        DB::transaction(function () use ($c, $d, $id) {
            DB::table('announcements')->insert(['id' => $id, 'organization_id' => $c['organization'],
                'school_id' => $c['school'], 'author_id' => $c['user'], 'title' => $this->clean($d['title'], 160),
                'body' => $this->clean($d['body'], 5000), 'audience' => $d['audience'] ?? 'ALL',
                'expires_at' => $d['expiresAt'] ?? null, 'status' => ($d['publishNow'] ?? false) ? 'PUBLISHED' : 'DRAFT',
                'published_at' => ($d['publishNow'] ?? false) ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
            if ($d['publishNow'] ?? false) $this->distribute($id, $c);
        });
        return response()->json($this->serialize(DB::table('announcements')->where('id', $id)->first()), 201);
    }

    public function publish(Request $request, string $id)
    {
        $c = $this->context->resolve($request, 'announcements', 'manage');
        return DB::transaction(function () use ($c, $id) {
            $query = DB::table('announcements')->where('id', $id)->where('organization_id', $c['organization'])
                ->where('school_id', $c['school']);
            $row = $query->lockForUpdate()->first();
            abort_unless($row, 404, 'Pengumuman tidak ditemukan');
            if ($row->status !== 'PUBLISHED') {
                $query->update(['status' => 'PUBLISHED', 'published_at' => now(), 'updated_at' => now()]);
                $this->distribute($id, $c);
            }
            return response()->json($this->serialize(DB::table('announcements')->where('id', $id)->first()));
        });
    }

    private function distribute(string $id, array $c): void
    {
        $a = DB::table('announcements')->where('id', $id)->first();
        $roles = match ($a->audience) {
            'STUDENTS' => ['student'], 'TEACHERS' => ['teacher'], 'PARENTS' => ['parent'],
            'STAFF' => ['teacher', 'school-admin', 'principal', 'program-manager', 'super-admin'],
            default => null,
        };
        $users = DB::table('users as u')->where('u.organization_id', $c['organization'])->where('u.status', 'active');
        if ($a->school_id || $roles) {
            $users->whereExists(function ($q) use ($a, $roles) {
                $q->selectRaw('1')->from('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
                    ->whereColumn('ur.user_id', 'u.id');
                if ($a->school_id) $q->where('ur.school_id', $a->school_id);
                if ($roles) $q->whereIn('r.slug', $roles);
            });
        }
        foreach ($users->distinct()->pluck('u.id') as $user) {
            $notificationId = (string) Str::ulid();
            $inserted = DB::table('notifications')->insertOrIgnore(['id' => $notificationId,
                'organization_id' => $c['organization'], 'school_id' => $a->school_id, 'user_id' => $user,
                'event_type' => 'ANNOUNCEMENT', 'title' => $a->title, 'body' => $this->clean($a->body, 500),
                'action_url' => '/dashboard/dukungan', 'idempotency_key' => "announcement:$id:$user:v1", 'created_at' => now()]);
            if ($inserted) DB::table('notification_deliveries')->insert(['id' => (string) Str::ulid(),
                'notification_id' => $notificationId, 'channel' => 'IN_APP', 'status' => 'SENT',
                'provider' => 'INTERNAL', 'sent_at' => now(), 'scheduled_at' => now(),
                'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
