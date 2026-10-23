<?php

namespace App\Printing\Escpos;

/** Alineación de ESC a n. */
enum Align: int
{
    case Left = 0;
    case Center = 1;
    case Right = 2;
}
