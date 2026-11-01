<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/**
 * A money operation refused by a business rule (not enough funds, cap
 * reached). Nothing was written. The API reports these as 422.
 */
abstract class OperationRejected extends RuntimeException {}
