<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Models\DocumentLookupRequest;
use Illuminate\Http\JsonResponse;

/** Uso del servicio de consulta de DNI y RUC (spec 018, HU-4): para saber cuándo conviene pagar. */
class DocumentLookupController extends Controller
{
    private const DAYS = 14;

    public function usage(): JsonResponse
    {
        $from = today()->subDays(self::DAYS - 1);
        $byDay = DocumentLookupRequest::query()->where('created_at', '>=', $from)
            ->get(['outcome', 'created_at'])
            ->groupBy(fn (DocumentLookupRequest $r) => $r->created_at->timezone(config('app.timezone'))->toDateString());

        $days = collect(range(0, self::DAYS - 1))->map(function (int $offset) use ($from, $byDay) {
            $date = $from->copy()->addDays($offset)->toDateString();
            $rows = $byDay->get($date, collect());

            return [
                'date' => $date,
                'total' => $rows->count(),
                'found' => $rows->where('outcome', 'found')->count(),
                'not_found' => $rows->where('outcome', 'not_found')->count(),
                'failed' => $rows->whereIn('outcome', ['unavailable', 'quota'])->count(),
            ];
        });

        return response()->json(['success' => true, 'data' => $days->all()]);
    }
}
