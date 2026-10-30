<?php

namespace App\Http\Controllers;

use App\Support\ApiContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificationApiController extends Controller
{
    public function __construct(private ApiContext $context) {}

    private function notification(object $row): array
    {
        $deliveries = DB::table('notification_deliveries')->where('notification_id', $row->id)
            ->get(['channel', 'status'])->map(fn ($d) => (array) $d)->all();
        return ['id' => $row->id, 'organizationId' => $row->organization_id, 'schoolId' => $row->school_id,
            'userId' => $row->user_id, 'eventType' => $row->event_type, 'title' => $row->title,
            'body' => $row->body, 'actionUrl' => $row->action_url, 'templateVersion' => $row->template_version,
            'idempotencyKey' => $row->idempotency_key, 'readAt' => $row->read_at,
            'archivedAt' => $row->archived_at, 'expiresAt' => $row->expires_at,
            'createdAt' => $row->created_at, 'deliveries' => $deliveries];
    }

    public function index(Request $request)
    {
        $c = $this->context->resolve($request, 'notifications', 'read');
        return response()->json(DB::table('notifications')->where('user_id', $c['user'])
            ->where('organization_id', $c['organization'])->whereNull('archived_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('created_at')->limit(50)->get()->map(fn ($row) => $this->notification($row)));
    }

    public function read(Request $request, string $id)
    {
        return $this->mark($request, $id, false);
    }

    public function archive(Request $request, string $id)
    {
        return $this->mark($request, $id, true);
    }

    private function mark(Request $request, string $id, bool $archive)
    {
        $c = $this->context->resolve($request, 'notifications', 'update');
        $query = DB::table('notifications')->where('id', $id)->where('user_id', $c['user'])
            ->where('organization_id', $c['organization']);
        abort_unless($query->exists(), 404, 'Notifikasi tidak ditemukan');
        $query->update([$archive ? 'archived_at' : 'read_at' => now()]);
        return response()->json($this->notification(DB::table('notifications')->where('id', $id)->first()));
    }

    public function readAll(Request $request)
    {
        $c = $this->context->resolve($request, 'notifications', 'update');
        $count = DB::table('notifications')->where('user_id', $c['user'])
            ->where('organization_id', $c['organization'])->whereNull('read_at')->whereNull('archived_at')
            ->update(['read_at' => now()]);
        return response()->json(['updated' => $count]);
    }

    private function preference(string $user): array
    {
        DB::table('notification_preferences')->insertOrIgnore(['id' => (string) Str::ulid(),
            'user_id' => $user, 'created_at' => now(), 'updated_at' => now()]);
        $p = DB::table('notification_preferences')->where('user_id', $user)->first();
        $result = ['id' => $p->id, 'userId' => $user];
        foreach (['in_app_enabled', 'email_enabled', 'whatsapp_enabled', 'sms_enabled', 'digest_frequency',
            'quiet_hours_start', 'quiet_hours_end', 'timezone', 'assignment_updates', 'review_updates',
            'announcements', 'early_warnings', 'helpdesk_updates', 'created_at', 'updated_at'] as $column) {
            $key = Str::camel($column);
            $result[$key] = in_array($column, ['in_app_enabled', 'email_enabled', 'whatsapp_enabled', 'sms_enabled',
                'assignment_updates', 'review_updates', 'announcements', 'early_warnings', 'helpdesk_updates'])
                ? (bool) $p->$column : $p->$column;
        }
        return $result;
    }

    public function getPreference(Request $request)
    {
        $c = $this->context->resolve($request, 'notifications', 'read');
        return response()->json($this->preference($c['user']));
    }

    public function updatePreference(Request $request)
    {
        $c = $this->context->resolve($request, 'notifications', 'update');
        $data = $request->validate(['inAppEnabled' => 'sometimes|boolean', 'emailEnabled' => 'sometimes|boolean',
            'whatsappEnabled' => 'sometimes|boolean', 'smsEnabled' => 'sometimes|boolean',
            'digestFrequency' => 'sometimes|in:IMMEDIATE,DAILY,WEEKLY',
            'quietHoursStart' => ['sometimes', 'nullable', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/'],
            'quietHoursEnd' => ['sometimes', 'nullable', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/'],
            'timezone' => 'sometimes|string|max:191|timezone', 'assignmentUpdates' => 'sometimes|boolean',
            'reviewUpdates' => 'sometimes|boolean', 'announcements' => 'sometimes|boolean',
            'earlyWarnings' => 'sometimes|boolean', 'helpdeskUpdates' => 'sometimes|boolean']);
        $this->preference($c['user']);
        $values = [];
        foreach ($data as $key => $value) $values[Str::snake($key)] = $value;
        // In-app delivery remains mandatory in the source service.
        $values['in_app_enabled'] = true;
        $values['updated_at'] = now();
        DB::table('notification_preferences')->where('user_id', $c['user'])->update($values);
        return response()->json($this->preference($c['user']));
    }
}
