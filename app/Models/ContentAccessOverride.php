<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContentAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $actor_id
 * @property ContentAccess $access
 * @property string $set_by_actor_id
 * @property CarbonImmutable $set_at
 * @property string|null $reason
 */
final class ContentAccessOverride extends Model
{
    protected $table = 'assay_content_access_overrides';

    protected $primaryKey = 'actor_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'actor_id',
        'access',
        'set_by_actor_id',
        'set_at',
        'reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'access' => ContentAccess::class,
            'set_at' => 'immutable_datetime',
        ];
    }
}
