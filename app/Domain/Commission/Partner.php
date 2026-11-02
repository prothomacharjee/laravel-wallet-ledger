<?php

declare(strict_types=1);

namespace App\Domain\Commission;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $parent_id
 * @property int $rate_bps
 * @property-read User $user
 * @property-read Partner|null $parent
 */
class Partner extends Model
{
    protected $guarded = ['*'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Partner, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    protected function casts(): array
    {
        return ['rate_bps' => 'integer'];
    }
}
