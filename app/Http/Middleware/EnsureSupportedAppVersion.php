<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Versión mínima de la app Android (spec 013, A-67). La app envía su versión
 * en X-App-Version; por debajo de APP_ANDROID_MIN_VERSION responde 426 para
 * que pida actualizarla. La web no envía la cabecera y no se ve afectada.
 */
class EnsureSupportedAppVersion
{
    public const HEADER = 'X-App-Version';

    public function handle(Request $request, Closure $next): Response
    {
        $min = config('app.android_min_version');
        $version = $request->header(self::HEADER);

        if ($min && $version !== null && ! $this->supported($version, $min)) {
            return response()->json([
                'success' => false,
                'message' => 'Esta versión de la app ya no es compatible. Actualízala para seguir usándola.',
                'min_version' => $min,
            ], 426);
        }

        return $next($request);
    }

    private function supported(string $version, string $min): bool
    {
        // Una versión mal formada no se compara: se trata como desactualizada.
        return preg_match('/^\d+\.\d+\.\d+$/', $version) === 1 && version_compare($version, $min, '>=');
    }
}
