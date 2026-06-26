<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuditLogServiceProvider;
use App\Providers\ListingsServiceProvider;

return [
    AppServiceProvider::class,
    ListingsServiceProvider::class,
    AuditLogServiceProvider::class,
];
