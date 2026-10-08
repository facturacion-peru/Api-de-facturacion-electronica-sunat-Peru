<?php

namespace App\DataTransfer;

use RuntimeException;

/** El archivo subido no es un XLSX o CSV legible (spec 014). */
class UnreadableFile extends RuntimeException {}
