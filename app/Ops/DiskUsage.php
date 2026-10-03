<?php

namespace App\Ops;

/** Uso del disco del servidor (spec 009). Separado para poder simularlo en pruebas. */
class DiskUsage
{
    public function percentUsed(string $path): float
    {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        return $total ? round(100 * (1 - $free / $total), 1) : 0.0;
    }
}
