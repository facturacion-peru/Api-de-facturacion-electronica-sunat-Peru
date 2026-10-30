<?php

namespace App\Images;

use RuntimeException;

/** El archivo no es una imagen JPEG, PNG o WebP decodificable y de tamaño aceptable (spec 016). */
class InvalidImage extends RuntimeException {}
