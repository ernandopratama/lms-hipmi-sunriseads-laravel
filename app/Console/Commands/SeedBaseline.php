<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SeedBaseline extends Command
{
    protected $signature = 'sunrise:seed-baseline {--organization= : Organization id; defaults to the first one}';
    protected $description = 'Idempotently create roles, permissions, access plans, offers and consent texts a fresh install needs';

    public function handle(): int
    {
        $organization = $this->option('organization') ?: DB::table('organizations')->orderBy('created_at')->value('id');
        if (! $organization || ! DB::table('organizations')->where('id', $organization)->exists()) {
            $this->error('Organisasi belum ada.');
            return self::FAILURE;
        }
        $baseline = json_decode(file_get_contents(config_path('baseline.json')), true, 512, JSON_THROW_ON_ERROR);
        DB::transaction(fn () => $this->seed($organization, $baseline));
        $this->info('Baseline roles, permissions, paket, penawaran, dan dokumen persetujuan siap.');
        return self::SUCCESS;
    }

    private function seed(string $organization, array $b): void
    {
        $now = now();
        $stamps = ['created_at' => $now, 'updated_at' => $now];
        $id = fn () => (string) Str::ulid();

        $roles = [];
        foreach ($b['roles'] as $r) {
            $row = DB::table('roles')->where('organization_id', $organization)->where('slug', $r['slug'])->first();
            if (! $row) {
                $new = ['id' => $id(), 'organization_id' => $organization, 'slug' => $r['slug'], 'name' => $r['name'], 'description' => $r['description'], 'is_system' => $r['isSystem'], 'is_active' => $r['isActive']] + $stamps;
                DB::table('roles')->insert($new);
                $row = (object) $new;
            }
            $roles[$r['slug']] = $row->id;
        }
        $permissions = [];
        foreach ($b['permissions'] as $p) {
            $row = DB::table('permissions')->where('organization_id', $organization)->where('resource', $p['resource'])->where('action', $p['action'])->first();
            if (! $row) {
                $new = ['id' => $id(), 'organization_id' => $organization, 'resource' => $p['resource'], 'action' => $p['action'], 'description' => $p['description'], 'is_system' => $p['isSystem']] + $stamps;
                DB::table('permissions')->insert($new);
                $row = (object) $new;
            }
            $permissions[$p['resource'].':'.$p['action']] = $row->id;
        }
        foreach ($b['rolePermissions'] as $rp) {
            $key = ['role_id' => $roles[$rp['role']], 'permission_id' => $permissions[$rp['resource'].':'.$rp['action']]];
            if (! DB::table('role_permissions')->where($key)->exists()) DB::table('role_permissions')->insert(['id' => $id(), 'created_at' => $now] + $key);
        }

        $plans = [];
        foreach ($b['accessPlans'] as $p) {
            $row = DB::table('access_plans')->where('code', $p['code'])->first();
            if (! $row) {
                $new = ['id' => $id(), 'code' => $p['code'], 'name' => $p['name'], 'rank' => $p['rank'], 'module_limit' => $p['moduleLimit'], 'status' => $p['status']] + $stamps;
                DB::table('access_plans')->insert($new);
                $row = (object) $new;
            }
            $plans[$p['code']] = $row->id;
        }
        $defs = [];
        foreach ($b['entitlementDefinitions'] as $d) {
            $row = DB::table('entitlement_definitions')->where('key', $d['key'])->first();
            if (! $row) {
                $new = ['id' => $id(), 'key' => $d['key'], 'value_type' => $d['valueType'], 'is_active' => $d['isActive']] + $stamps;
                DB::table('entitlement_definitions')->insert($new);
                $row = (object) $new;
            }
            $defs[$d['key']] = $row->id;
        }
        foreach ($b['planEntitlements'] as $e) {
            $key = ['access_plan_id' => $plans[$e['plan']], 'entitlement_definition_id' => $defs[$e['key']]];
            if (! DB::table('plan_entitlements')->where($key)->exists()) {
                DB::table('plan_entitlements')->insert(['id' => $id(), 'enabled' => $e['enabled'], 'value_json' => $e['valueJson'], 'effective_from' => '2026-01-01 00:00:00'] + $key + $stamps);
            }
        }
        foreach ($b['planOffers'] as $o) {
            $offer = DB::table('plan_offers')->where('code', $o['code'])->first();
            if (! $offer) {
                $new = ['id' => $id(), 'access_plan_id' => $plans[$o['plan']], 'code' => $o['code'], 'slug' => $o['slug'], 'name' => $o['name'], 'audience' => $o['audience'], 'billing_model' => $o['billingModel'],
                    'access_duration_days' => $o['accessDurationDays'], 'seat_min' => $o['seatMin'], 'seat_max' => $o['seatMax'], 'status' => $o['status'], 'terms_version' => $o['termsVersion'], 'refund_policy_version' => $o['refundPolicyVersion']] + $stamps;
                DB::table('plan_offers')->insert($new);
                $offer = (object) $new;
                foreach ($o['prices'] as $p) {
                    DB::table('offer_prices')->insert(['id' => $id(), 'plan_offer_id' => $offer->id, 'currency' => $p['currency'], 'amount' => $p['amount'], 'compare_at_amount' => $p['compareAtAmount'], 'tax_mode' => $p['taxMode'],
                        'tax_rate' => $p['taxRate'], 'effective_from' => '2026-01-01 00:00:00', 'status' => $p['status'], 'created_at' => $now]);
                }
            }
        }
        foreach ($b['consentDocuments'] as $c) {
            $key = ['organization_id' => $organization, 'type' => $c['type'], 'version' => $c['version']];
            if (! DB::table('consent_documents')->where($key)->exists()) {
                DB::table('consent_documents')->insert(['id' => $id(), 'title' => $c['title'], 'content' => $c['content'], 'effective_at' => $c['effectiveAt'].' 00:00:00', 'created_at' => $now] + $key);
            }
        }
    }
}
