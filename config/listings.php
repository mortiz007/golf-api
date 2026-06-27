<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Daily Listing Creation Limit
    |--------------------------------------------------------------------------
    |
    | Maximum number of listings a single user may create per day, enforced by
    | the named "listing-creation" rate limiter on POST /api/listings. This is a
    | stricter per-action cap on top of the global 60 req/min throttle, to
    | protect the API and control LLM processing cost (SPECS §4 / DESIGN §VI).
    |
    */

    'daily_creation_limit' => (int) env('LISTINGS_DAILY_CREATION_LIMIT', 10),

];
