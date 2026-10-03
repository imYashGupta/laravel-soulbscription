<?php

namespace LucasDotVin\Soulbscription\Models\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use LucasDotVin\Soulbscription\Enums\PeriodicityType;

trait HandlesRecurrence
{
    public function calculateNextRecurrenceEnd(CarbonInterface|string|null $start = null): CarbonInterface
    {
        if (empty($start)) {
            $start = now();
        }

        if (is_string($start)) {
            $start = Date::parse($start);
        }

        $recurrences = max(
            PeriodicityType::getDateDifference(from: $start, to: now(), unit: $this->periodicity_type),
            0,
        );

        $expirationDate = $start->copy()->add($this->periodicity_type, $this->periodicity + $recurrences);

        return $expirationDate;
    }
}
