<?php

declare(strict_types=1);

namespace App\Listings\Domain\Exceptions;

use RuntimeException;

/**
 * Base for all Listings domain exceptions. Framework-agnostic.
 * NOTE (S1-01 enabling stub): the full hierarchy and HTTP 422 mapping
 * are formalized in S1-04. Do not extend further here.
 */
abstract class ListingDomainException extends RuntimeException {}
