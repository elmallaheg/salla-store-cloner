<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * متجر سلة مثبَّت عليه التطبيق.
 *
 * @property int    $id
 * @property int    $store_id          معرّف المتجر في سلة
 * @property string $store_name
 * @property string $access_token
 * @property string $refresh_token
 * @property \Carbon\Carbon $token_expires_at
 * @property string $role              source | target | unassigned
 */
class SallaStore extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'token_expires_at' => 'datetime',
    ];

    // ======== العلاقات ========

    /** أزواج المزامنة حيث هذا المتجر هو المصدر */
    public function sourcePairs(): HasMany
    {
        return $this->hasMany(StorePair::class, 'source_store_id', 'store_id');
    }

    /** أزواج المزامنة حيث هذا المتجر هو الهدف */
    public function targetPairs(): HasMany
    {
        return $this->hasMany(StorePair::class, 'target_store_id', 'store_id');
    }

    // ======== Helper Methods ========

    /** هل التوكن صالح (لم ينته أو متبقي أكثر من 12 ساعة)؟ */
    public function hasValidToken(): bool
    {
        return $this->token_expires_at
            && $this->token_expires_at->isFuture()
            && $this->token_expires_at->diffInHours(now()) > 12;
    }
}
