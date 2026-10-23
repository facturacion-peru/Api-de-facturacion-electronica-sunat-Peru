<?php

namespace App\Printing\Escpos;

use App\Printing\PrintOptions;

/**
 * Bytes ESC/POS para impresoras térmicas Bluetooth (spec 015). Usa solo el
 * juego básico de Epson, que entienden las compatibles. El texto se ajusta al
 * ancho contando caracteres (no bytes) y se convierte a la tabla PC850.
 */
final class EscposBuilder
{
    private const ESC = "\x1B";

    private const GS = "\x1D";

    /** ESC t 2: PC850, la tabla más extendida en estas impresoras (riesgo del plan). */
    private const CODE_PAGE = 2;

    /** Signos tipográficos que PC850 no trae, con su equivalente. */
    private const REPLACEMENTS = [
        '—' => '-', '–' => '-', '−' => '-', '×' => 'x',
        '“' => '"', '”' => '"', '‘' => "'", '’' => "'", '…' => '...',
    ];

    private string $bytes;

    private Align $align = Align::Left;

    private bool $bold = false;

    private bool $big = false;

    public function __construct(private readonly PrintOptions $options)
    {
        $this->bytes = self::ESC.'@'.self::ESC.'t'.chr(self::CODE_PAGE);
    }

    /**
     * Texto que PC850 puede imprimir, en UTF-8: sin caracteres de control (no
     * se cuela ningún comando desde un nombre), con los signos tipográficos
     * reemplazados, sin lo que no tiene equivalente y con un solo espacio.
     */
    public static function sanitize(string $text): string
    {
        $text = strtr(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? '', self::REPLACEMENTS);

        $kept = '';
        foreach (mb_str_split($text) as $char) {
            if ($char === '?' || mb_convert_encoding($char, 'CP850', 'UTF-8') !== '?') {
                $kept .= $char;
            }
        }

        return trim(preg_replace('/ {2,}/', ' ', $kept) ?? '');
    }

    public function text(string $text, Align $align = Align::Left, bool $bold = false, bool $big = false): self
    {
        $this->style($align, $bold, $big);
        foreach ($this->wrap(self::sanitize($text), $this->columns()) as $line) {
            $this->line($line);
        }

        return $this;
    }

    /** Texto a la izquierda e importe a la derecha; si no caben, el importe va en la última línea o en una propia. */
    public function row(string $left, string $right, bool $bold = false, bool $big = false): self
    {
        $this->style(Align::Left, $bold, $big);
        $columns = $this->columns();
        $right = self::sanitize($right);
        $lines = $this->wrap(self::sanitize($left), $columns);
        $last = array_pop($lines) ?? '';

        if (mb_strlen($last) + 1 + mb_strlen($right) <= $columns) {
            $lines[] = $last.str_repeat(' ', $columns - mb_strlen($last) - mb_strlen($right)).$right;
        } else {
            $lines[] = $last;
            foreach ($this->wrap($right, $columns) as $part) {
                $lines[] = str_repeat(' ', $columns - mb_strlen($part)).$part;
            }
        }

        foreach ($lines as $line) {
            $this->line($line);
        }

        return $this;
    }

    /** Etiqueta y valor en una fila; si no caben, el valor va debajo con sangría. */
    public function pair(string $label, string $value): self
    {
        $label = self::sanitize($label);
        $value = self::sanitize($value);

        if (mb_strlen($label) + 1 + mb_strlen($value) <= $this->options->columns()) {
            return $this->row($label, $value);
        }

        $this->text($label);
        foreach ($this->wrap($value, $this->options->columns() - 2) as $line) {
            $this->line('  '.$line);
        }

        return $this;
    }

    public function rule(): self
    {
        $this->style(Align::Left, false, false);
        $this->line(str_repeat('-', $this->columns()));

        return $this;
    }

    public function feed(int $lines = 1): self
    {
        $this->bytes .= str_repeat("\n", $lines);

        return $this;
    }

    /** QR centrado: con el comando de la impresora o, si no lo tiene, como imagen (A-77). */
    public function qr(string $data): self
    {
        $this->style(Align::Center, false, false);

        if ($this->options->qr === 'image') {
            $this->bytes .= QrRaster::make($data, $this->options->qrModule(), $this->options->dots())->escpos();
        } else {
            $store = '1P0'.$data;
            $this->bytes .= self::GS.'(k'.pack('v', 4).'1A2'."\x00"
                .self::GS.'(k'.pack('v', 3).'1C'.chr($this->options->qrModule())
                .self::GS.'(k'.pack('v', 3).'1E1'
                .self::GS.'(k'.pack('v', strlen($store)).$store
                .self::GS.'(k'.pack('v', 3).'1Q0';
        }
        $this->bytes .= "\n";

        return $this;
    }

    /** Avance para que el texto pase la cuchilla y corte parcial (las impresoras sin cuchilla lo ignoran). */
    public function feedAndCut(): self
    {
        $this->bytes .= self::ESC.'d'.chr(4).self::GS.'VB'."\x00";

        return $this;
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    private function columns(): int
    {
        return $this->big ? intdiv($this->options->columns(), 2) : $this->options->columns();
    }

    /** Emite solo los comandos que cambian respecto del estado actual. */
    private function style(Align $align, bool $bold, bool $big): void
    {
        if ($align !== $this->align) {
            $this->bytes .= self::ESC.'a'.chr($align->value);
            $this->align = $align;
        }
        if ($bold !== $this->bold) {
            $this->bytes .= self::ESC.'E'.chr((int) $bold);
            $this->bold = $bold;
        }
        if ($big !== $this->big) {
            $this->bytes .= self::GS.'!'.($big ? "\x11" : "\x00");
            $this->big = $big;
        }
    }

    /** @param  string  $line  texto ya saneado */
    private function line(string $line): void
    {
        $this->bytes .= mb_convert_encoding($line, 'CP850', 'UTF-8')."\n";
    }

    /**
     * Ajuste por palabras; las más largas que la línea se parten.
     *
     * @return list<string>
     */
    private function wrap(string $text, int $width): array
    {
        $lines = [];
        $current = '';

        foreach (explode(' ', $text) as $word) {
            while (mb_strlen($word) > $width) {
                if ($current !== '') {
                    $lines[] = $current;
                    $current = '';
                }
                $lines[] = mb_substr($word, 0, $width);
                $word = mb_substr($word, $width);
            }

            if ($current === '') {
                $current = $word;
            } elseif (mb_strlen($current) + 1 + mb_strlen($word) <= $width) {
                $current .= ' '.$word;
            } else {
                $lines[] = $current;
                $current = $word;
            }
        }

        if ($current !== '' || $lines === []) {
            $lines[] = $current;
        }

        return $lines;
    }
}
