<?php

namespace App\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Brings /api/backend JSON in line with the original API's wire format, whatever the
 * controller read from MySQL:
 *  - DATETIME strings become ISO-8601 UTC with milliseconds ("2026-09-23T05:38:08.744Z");
 *  - decimal strings lose trailing zeros like Prisma's Decimal#toString ("25000.00" -> "25000");
 *  - tinyint flags named isX / known flag keys become booleans.
 */
class NormalizeApiJson
{
    private const DECIMAL_KEYS = ['amount', 'compareAtAmount', 'taxRate', 'unitAmount', 'lineTotal', 'subtotalAmount', 'discountAmount', 'taxAmount', 'feeAmount',
        'totalAmount', 'requestedAmount', 'settledAmount', 'gmv', 'commission', 'averageOrderValue', 'ctr', 'conversionRate', 'commissionRate', 'confidence'];
    private const FLAG_KEYS = ['enabled', 'declarationAccepted', 'disclosureAccepted', 'ownershipDeclared', 'emailVerified', 'mfaEnabled', 'insufficientEvidence', 'requiresTeacherReview'];

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if ($response instanceof JsonResponse && $request->is('api/backend/*') && $response->isSuccessful()) {
            $response->setData($this->walk($response->getData(true), null));
        }
        return $response;
    }

    private function walk(mixed $value, ?string $key): mixed
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) $value[$k] = $this->walk($v, is_string($k) ? $k : $key);
            return $value;
        }
        if (is_string($value)) {
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/', $value)) {
                return CarbonImmutable::parse($value, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
            }
            if ($key !== null && in_array($key, self::DECIMAL_KEYS, true) && preg_match('/^-?\d+\.\d+$/', $value)) {
                return rtrim(rtrim($value, '0'), '.');
            }
        }
        if (is_int($value) && ($value === 0 || $value === 1) && $key !== null && (in_array($key, self::FLAG_KEYS, true) || preg_match('/^is[A-Z]/', $key))) {
            return $value === 1;
        }
        return $value;
    }
}
