<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Convierte los bytes ESC/POS en el texto que saldría impreso (spec 015), para
 * las pruebas y para revisar a ojo un ticket sin impresora. Solo entiende los
 * comandos que usa EscposBuilder: cualquier otro es un error, así una prueba
 * detecta bytes inesperados.
 *
 * Marcas: [B]…[/B] negrita, [2x]…[/2x] doble tamaño, [QR:datos] QR nativo,
 * [IMG anchoxalto] imagen (en puntos) y [CUT] corte.
 */
final class EscposText
{
    /** Texto con marcas de estilo; una línea por salto de línea impreso. */
    public static function decode(string $bytes): string
    {
        $out = '';
        $qrData = null;
        $i = 0;
        $n = strlen($bytes);

        $take = function (int $count) use ($bytes, &$i, $n): string {
            if ($i + $count > $n) {
                throw new RuntimeException("Comando incompleto en el byte {$i}");
            }
            $chunk = substr($bytes, $i, $count);
            $i += $count;

            return $chunk;
        };

        while ($i < $n) {
            $byte = $bytes[$i++];

            if ($byte === "\x1B") {
                $cmd = $take(1);
                match ($cmd) {
                    '@' => null,
                    't', 'a' => $take(1),
                    'E' => $out .= ord($take(1)) ? '[B]' : '[/B]',
                    'd' => $out .= str_repeat("\n", ord($take(1))),
                    default => throw new RuntimeException('ESC '.bin2hex($cmd).' no reconocido'),
                };

                continue;
            }

            if ($byte === "\x1D") {
                $cmd = $take(1);
                if ($cmd === '!') {
                    $out .= ord($take(1)) ? '[2x]' : '[/2x]';
                } elseif ($cmd === 'V') {
                    $take(2);
                    $out .= '[CUT]';
                } elseif ($cmd === '(') {
                    if ($take(1) !== 'k') {
                        throw new RuntimeException('GS ( distinto de k');
                    }
                    $length = unpack('v', $take(2))[1];
                    $body = $take($length);
                    if ($body[0] !== '1') {
                        throw new RuntimeException('GS ( k de un código distinto de QR');
                    }
                    if ($body[1] === 'P') {
                        $qrData = substr($body, 3);
                    } elseif ($body[1] === 'Q') {
                        $out .= '[QR:'.$qrData.']';
                    }
                } elseif ($cmd === 'v') {
                    if ($take(2) !== "0\x00") {
                        throw new RuntimeException('GS v distinto de 0 con m = 0');
                    }
                    ['x' => $x, 'y' => $y] = unpack('vx/vy', $take(4));
                    $take($x * $y);
                    $out .= '[IMG '.($x * 8).'x'.$y.']';
                } else {
                    throw new RuntimeException('GS '.bin2hex($cmd).' no reconocido');
                }

                continue;
            }

            if ($byte === "\n") {
                $out .= "\n";

                continue;
            }

            if (ord($byte) < 0x20) {
                throw new RuntimeException('Byte de control '.bin2hex($byte).' fuera de un comando');
            }

            $out .= mb_convert_encoding($byte, 'UTF-8', 'CP850');
        }

        return $out;
    }

    /** Texto impreso sin marcas ni QR ni imágenes, línea por línea. */
    public static function lines(string $bytes): array
    {
        $text = preg_replace('/\[(\/?B|\/?2x|CUT|IMG \d+x\d+|QR:[^\]]*)\]/u', '', self::decode($bytes));

        return explode("\n", rtrim($text, "\n"));
    }

    /** Datos de cada QR nativo impreso, en orden. */
    public static function qrs(string $bytes): array
    {
        preg_match_all('/\[QR:([^\]]*)\]/u', self::decode($bytes), $matches);

        return $matches[1];
    }
}
