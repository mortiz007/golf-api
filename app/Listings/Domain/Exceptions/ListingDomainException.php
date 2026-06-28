<?php

declare(strict_types=1);

namespace App\Listings\Domain\Exceptions;

use RuntimeException;

/**
 * Base for all Listings domain exceptions. Framework-agnostic.
 * NOTE (S1-01 enabling stub): the full hierarchy and HTTP 422 mapping
 * are formalized in S1-04. Do not extend further here.
 *
 * These are expected control-flow outcomes (404/403/422) mapped to the error
 * envelope in bootstrap/app.php; reporting is suppressed there via dontReport()
 * to keep the Domain layer free of any framework dependency (DESIGN §6.2).
 */
abstract class ListingDomainException extends RuntimeException {}
