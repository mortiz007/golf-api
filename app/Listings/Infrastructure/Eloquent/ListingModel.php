<?php

declare(strict_types=1);

namespace App\Listings\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent persistence model for the `listings` table (SPECS §3).
 *
 * IMPORTANT: this is an Infrastructure adapter, NOT a domain entity.
 * Conversion to/from the Listing domain entity is handled by ListingMapper (S1-10).
 * No business logic lives here.
 *
 * @property int $id
 * @property int $user_id
 * @property int $category_id
 * @property string $title
 * @property string $price
 * @property string $condition
 * @property string $description
 * @property string|null $end_date
 * @property string $moderation_status
 * @property array|null $moderation_result
 * @property array|null $ai_enrichment
 * @property string $ai_enrichment_status
 * @property string|null $cancelled_at
 */
final class ListingModel extends Model
{
    protected $table = 'listings';

    /**
     * Mass-assignable columns for create/update.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'category_id',
        'title',
        'price',
        'condition',
        'description',
        'end_date',
        'moderation_status',
        'moderation_result',
        'ai_enrichment',
        'ai_enrichment_status',
        'cancelled_at',
    ];

    /**
     * Attribute casting.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'end_date' => 'date:Y-m-d',
            'moderation_result' => 'array',
            'ai_enrichment' => 'array',
            'cancelled_at' => 'datetime',
        ];
    }
}
