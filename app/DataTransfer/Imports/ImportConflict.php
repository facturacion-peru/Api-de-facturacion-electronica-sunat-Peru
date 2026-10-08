<?php

namespace App\DataTransfer\Imports;

use RuntimeException;

/** Los datos cambiaron entre la vista previa y la confirmación (spec 014). */
class ImportConflict extends RuntimeException {}
