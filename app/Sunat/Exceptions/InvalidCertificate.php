<?php

namespace App\Sunat\Exceptions;

use RuntimeException;

/** El certificado no se puede usar; el mensaje dice por qué, en español (RF-003). */
class InvalidCertificate extends RuntimeException {}
