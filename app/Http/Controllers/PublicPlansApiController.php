<?php
namespace App\Http\Controllers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
class PublicPlansApiController extends Controller
{
    private function encode(object $row, array $fields): array
    {
        $result = []; foreach ($fields as $f) $result[Str::camel($f)] = $row->$f; return $result;
    }
    public function __invoke()
    {
        $plans = DB::table('access_plans')->where('status', 'ACTIVE')->orderBy('rank')->get()->map(function ($p) {
            $plan = $this->encode($p, ['code', 'name', 'rank', 'module_limit']);
            $plan['entitlements'] = DB::table('plan_entitlements as e')->join('entitlement_definitions as d', 'd.id', '=', 'e.entitlement_definition_id')->where('e.access_plan_id', $p->id)
                ->where('e.effective_from', '<=', now())->where(fn ($q) => $q->whereNull('e.effective_to')->orWhere('e.effective_to', '>', now()))->orderByDesc('e.effective_from')->get(['d.key', 'e.value_json', 'e.enabled'])
                ->unique('key')->mapWithKeys(fn ($e) => [$e->key => $e->value_json !== null ? json_decode($e->value_json, true) : (bool) $e->enabled])->all();
            $plan['offers'] = DB::table('plan_offers')->where('access_plan_id', $p->id)->where('status', 'ACTIVE')
                ->where(fn ($q) => $q->whereNull('sales_start_at')->orWhere('sales_start_at', '<=', now()))->where(fn ($q) => $q->whereNull('sales_end_at')->orWhere('sales_end_at', '>', now()))->get()->map(function ($o) {
                    $offer = $this->encode($o, ['code', 'slug', 'name', 'audience', 'billing_model', 'access_duration_days', 'seat_min', 'seat_max']);
                    $offer['prices'] = DB::table('offer_prices')->where('plan_offer_id', $o->id)->where('status', 'ACTIVE')->where('effective_from', '<=', now())->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', now()))->orderByDesc('effective_from')->limit(1)->get()->map(fn ($price) => $this->encode($price, ['currency', 'amount', 'compare_at_amount', 'tax_mode', 'tax_rate']));
                    return $offer;
                });
            return $plan;
        });
        return response()->json($plans)->header('Cache-Control', 'no-store');
    }
}
