<?php

namespace App\Sunat;

/** Lo ya devuelto de una línea por notas no rechazadas: cantidad, bruto y descuento. */
final readonly class ReturnedSoFar
{
    public function __construct(
        public string $quantity,
        public string $gross,
        public string $discount,
    ) {}

    public static function none(): self
    {
        return new self('0.000', '0.00', '0.00');
    }

    public function plus(string $quantity, string $gross, string $discount): self
    {
        return new self(bcadd($this->quantity, $quantity, 3), bcadd($this->gross, $gross, 2), bcadd($this->discount, $discount, 2));
    }
}
