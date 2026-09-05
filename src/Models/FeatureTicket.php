<?php

namespace LucasDotVin\Soulbscription\Models;

use Illuminate\Database\Eloquent\Model;
use LucasDotVin\Soulbscription\Models\Concerns\Expires;
use InvalidArgumentException;

class FeatureTicket extends Model
{
    use Expires;

    protected $fillable = [
        'charges',
        'expired_at',
        'recurring',
    ];

    protected $casts = [
        'charges' => 'float',
        'consumed' => 'float',
        'recurring' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (FeatureTicket $ticket): void {
            $charges = $ticket->charges === null ? null : round((float) $ticket->charges, 2);
            $consumed = round((float) ($ticket->consumed ?? 0), 2);

            if ($charges !== null && (! is_finite((float) $charges) || (float) $charges < 0)) {
                throw new InvalidArgumentException('Ticket charges must be a non-negative finite number.');
            }

            if (! is_finite($consumed) || $consumed < 0) {
                throw new InvalidArgumentException('Ticket consumption must be a non-negative finite number.');
            }

            if ($charges !== null && $consumed > (float) $charges) {
                throw new InvalidArgumentException('Ticket consumption cannot exceed ticket charges.');
            }

            $ticket->charges = $charges;
            $ticket->consumed = $consumed;
        });
    }

    public function remainingCharges(): float
    {
        if ($this->charges === null) {
            return 0;
        }

        return round(max((float) $this->charges - (float) ($this->consumed ?? 0), 0), 2);
    }

    public function isOneTime(): bool
    {
        return $this->recurring === false;
    }

    public function feature()
    {
        return $this->belongsTo(config('soulbscription.models.feature'));
    }

    public function subscriber()
    {
        return $this->morphTo('subscriber');
    }
}
