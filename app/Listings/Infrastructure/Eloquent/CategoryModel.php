<?php

declare(strict_types=1);

namespace App\Listings\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent persistence model for the `categories` table (SPECS §3).
 *
 * Read-only adapter used to eager-load the category name for the public listing
 * query (SPECS §4.4). Not a domain entity; carries no business logic.
 *
 * @property int $id
 * @property string $name
 */
final class CategoryModel extends Model
{
    protected $table = 'categories';

    public $timestamps = false;

    /**
     * Mass-assignable columns.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
    ];
}
