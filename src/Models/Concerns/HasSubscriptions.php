<?php

namespace LucasDotVin\Soulbscription\Models\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use LucasDotVin\Soulbscription\Events\FeatureConsumed;
use LucasDotVin\Soulbscription\Events\FeatureTicketConsumed;
use LucasDotVin\Soulbscription\Events\FeatureTicketCreated;
use LucasDotVin\Soulbscription\Models\Feature;
use LucasDotVin\Soulbscription\Models\FeatureConsumption;
use LucasDotVin\Soulbscription\Models\FeatureTicket;
use LucasDotVin\Soulbscription\Models\Plan;
use LucasDotVin\Soulbscription\Models\Subscription;
use LucasDotVin\Soulbscription\Models\Scopes\ExpiringScope;
use OutOfBoundsException;
use OverflowException;

trait HasSubscriptions
{
    protected ?Collection $loadedFeatures = null;

    protected ?Collection $loadedSubscriptionFeatures = null;

    protected ?Collection $loadedTicketFeatures = null;

    public function featureConsumptions()
    {
        return $this->morphMany(config('soulbscription.models.feature_consumption'), 'subscriber');
    }

    public function featureTickets()
    {
        return $this->morphMany(config('soulbscription.models.feature_ticket'), 'subscriber');
    }

    public function renewals()
    {
        return $this->hasManyThrough(
            config('soulbscription.models.subscription_renewal'),
            config('soulbscription.models.subscription'),
            'subscriber_id',
        );
    }

    public function subscription()
    {
        return $this->morphOne(config('soulbscription.models.subscription'), 'subscriber')->ofMany('started_at', 'MAX');
    }

    public function lastSubscription()
    {
        return app(config('soulbscription.models.subscription'))
            ->withExpired()
            ->whereMorphedTo('subscriber', $this)
            ->orderBy('started_at', 'DESC')
            ->first();
    }

    /**
     * @throws OutOfBoundsException
     * @throws OverflowException
     */
    public function consume($featureName, ?float $consumption = null)
    {
        throw_if($this->missingFeature($featureName), new OutOfBoundsException(
            'None of the active plans grants access to this feature.',
        ));

        $feature = $this->getFeature($featureName);
        $consumption = $this->normalizeDecimal($consumption);

        if ($feature->consumable || $consumption !== null) {
            $this->validateConsumption($consumption);
        }

        $allocation = DB::transaction(function () use ($feature, $consumption): array {
            // Serialize all allocations for a subscriber. This lock is acquired
            // before ticket locks so concurrent calls use one stable lock order.
            $this->getConnection()->table($this->getTable())
                ->where($this->getKeyName(), $this->getKey())
                ->lockForUpdate()
                ->first();

            if ($feature->quota) {
                if ($this->cantConsume($feature->name, $consumption)) {
                    throw new OverflowException('The feature has no enough charges to this consumption.');
                }

                return [
                    'feature_consumption' => $this->consumeQuotaFeature($feature, (float) $consumption),
                    'ticket_allocations' => [],
                ];
            }

            if (! $feature->consumable) {
                return [
                    'feature_consumption' => $this->consumeNotQuotaFeature($feature, $consumption),
                    'ticket_allocations' => [],
                ];
            }

            return $this->consumeAllocations($feature, (float) $consumption);
        }, 5);

        $this->forgetFeatureCaches();

        if ($allocation['feature_consumption']?->exists) {
            event(new FeatureConsumed($this, $feature, $allocation['feature_consumption']));
        }

        foreach ($allocation['ticket_allocations'] as $ticketAllocation) {
            event(new FeatureTicketConsumed(
                $this,
                $feature,
                $ticketAllocation['ticket'],
                $ticketAllocation['consumption'],
            ));
        }
    }

    /**
     * @throws OutOfBoundsException
     * @throws OverflowException
     */
    public function setConsumedQuota($featureName, float $consumption)
    {
        $consumption = $this->normalizeDecimal($consumption);

        if (! is_finite($consumption) || $consumption < 0) {
            throw new InvalidArgumentException('The consumption must be a non-negative finite number.');
        }

        throw_if($this->missingFeature($featureName), new OutOfBoundsException(
            'None of the active plans grants access to this feature.',
        ));

        throw_if($this->getTotalCharges($featureName) < $consumption, new OverflowException(
            'The feature has no enough charges to this consumption.',
        ));

        $feature = $this->getFeature($featureName);

        throw_unless($feature->quota, new InvalidArgumentException(
            'The feature is not a quota feature.',
        ));

        $featureConsumption = $this->featureConsumptions()
            ->whereFeatureId($feature->id)
            ->firstOrNew();

        if ($featureConsumption->consumption == $consumption) {
            return;
        }

        $featureConsumption->feature()->associate($feature);
        $featureConsumption->consumption = $consumption;
        $featureConsumption->save();

        event(new FeatureConsumed($this, $feature, $featureConsumption));
    }

    public function subscribeTo(Plan $plan, $expiration = null, $startDate = null): Subscription
    {
        if ($plan->periodicity) {
            $expiration = $expiration ?? $plan->calculateNextRecurrenceEnd($startDate);
        } else {
            $expiration = $expiration ?? null;
        }

        $graceDaysEnd = $plan->hasGraceDays && $expiration
                ? $plan->calculateGraceDaysEnd($expiration)
                : null;

        $subscription = $this->subscription()->make([
            'expired_at' => $expiration,
            'grace_days_ended_at' => $graceDaysEnd,
        ]);

        $subscription->plan()->associate($plan);
        $this->forgetFeatureCaches();
        $subscription->start($startDate);
        $this->forgetFeatureCaches();

        return $subscription;
    }

    public function hasSubscriptionTo(Plan $plan): bool
    {
        return $this->subscription()
            ->where('plan_id', $plan->id)
            ->exists();
    }

    public function isSubscribedTo(Plan $plan): bool
    {
        return $this->hasSubscriptionTo($plan);
    }

    public function missingSubscriptionTo(Plan $plan): bool
    {
        return ! $this->hasSubscriptionTo($plan);
    }

    public function isNotSubscribedTo(Plan $plan): bool
    {
        return ! $this->isSubscribedTo($plan);
    }

    public function switchTo(Plan $plan, $expiration = null, $immediately = true): Subscription
    {
        if ($immediately) {
            $this->subscription
                ->markAsSwitched()
                ->suppress()
                ->save();

            return $this->subscribeTo($plan, $expiration);
        }

        $this->subscription
            ->markAsSwitched()
            ->save();

        $startDate = $this->subscription->expired_at;
        $newSubscription = $this->subscribeTo($plan, startDate: $startDate);

        return $newSubscription;
    }

    /**
     * @throws LogicException
     * @throws ModelNotFoundException
     */
    public function giveTicketFor(
        $featureName,
        $expiration = null,
        ?float $charges = null,
        bool $recurring = true,
    ): FeatureTicket {
        throw_unless(
            config('soulbscription.feature_tickets'),
            new LogicException('The tickets are not enabled in the configs.'),
        );

        $feature = Feature::whereName($featureName)->firstOrFail();

        if ($charges !== null && (! is_finite($charges) || $charges < 0)) {
            throw new InvalidArgumentException('Ticket charges must be a non-negative finite number.');
        }

        $charges = $this->normalizeDecimal($charges);

        if (
            $recurring === false && (! $feature->consumable || $feature->quota
            || $charges === null || ! is_finite($charges) || $charges <= 0)
        ) {
            throw new InvalidArgumentException(
                'One-time tickets require a positive charge for a non-quota consumable feature.',
            );
        }

        $featureTicket = $this->featureTickets()
            ->make([
                'charges' => $charges,
                'expired_at' => $expiration,
                'recurring' => $recurring,
            ]);

        $featureTicket->feature()->associate($feature);
        $featureTicket->save();

        $this->forgetFeatureCaches();

        event(new FeatureTicketCreated($this, $feature, $featureTicket));

        return $featureTicket;
    }

    public function giveOneTimeTicketFor($featureName, $expiration = null, ?float $charges = null): FeatureTicket
    {
        return $this->giveTicketFor($featureName, $expiration, $charges, false);
    }

    public function giveRecurringTicketFor($featureName, $expiration = null, ?float $charges = null): FeatureTicket
    {
        return $this->giveTicketFor($featureName, $expiration, $charges, true);
    }

    public function canConsume($featureName, ?float $consumption = null): bool
    {
        $consumption = $this->normalizeDecimal($consumption);

        if (empty($feature = $this->getFeature($featureName))) {
            return false;
        }

        if (! $feature->consumable) {
            if ($consumption !== null && ! $this->isValidConsumption($consumption)) {
                return false;
            }

            return true;
        }

        if ($consumption === null) {
            return $feature->postpaid || $this->getRemainingCharges($featureName) > 0;
        }

        if (! $this->isValidConsumption($consumption)) {
            return false;
        }

        if ($feature->postpaid) {
            return true;
        }

        $remainingCharges = $this->getRemainingCharges($featureName);

        return $remainingCharges >= $consumption;
    }

    public function cantConsume($featureName, ?float $consumption = null): bool
    {
        return ! $this->canConsume($featureName, $consumption);
    }

    public function hasFeature($featureName): bool
    {
        return ! $this->missingFeature($featureName);
    }

    public function missingFeature($featureName): bool
    {
        return empty($this->getFeature($featureName));
    }

    public function getRemainingCharges($featureName): float
    {
        $balance = $this->balance($featureName);

        return max($balance, 0);
    }

    public function balance($featureName)
    {
        if (empty($feature = $this->getFeature($featureName))) {
            return 0;
        }

        $breakdown = $this->getFeatureBalanceBreakdown($featureName);

        if ($feature->postpaid) {
            return $breakdown['total_allowance']
                - $breakdown['periodic_consumption']
                - $breakdown['one_time_consumed'];
        }

        return $breakdown['total_remaining'];
    }

    public function getCurrentConsumption($featureName): float
    {
        if (empty($feature = $this->getFeature($featureName))) {
            return 0;
        }

        return round((float) $this->featureConsumptions()
            ->whereBelongsTo($feature)
            ->sum('consumption'), 2);
    }

    public function getTotalCharges($featureName): float
    {
        if (empty($feature = $this->getFeature($featureName))) {
            return 0;
        }

        return $this->getFeatureBalanceBreakdown($featureName)['total_allowance'];
    }

    /**
     * Return the current period and lifetime ticket balances in one query contract.
     *
     * The allowance fields are gross values. Remaining values account for current
     * period consumption and one-time ticket consumption respectively.
     *
     * @return array{
     *     periodic_allowance: float,
     *     periodic_consumption: float,
     *     periodic_remaining: float,
     *     one_time_allowance: float,
     *     one_time_consumed: float,
     *     one_time_remaining: float,
     *     total_allowance: float,
     *     total_remaining: float
     * }
     */
    public function getFeatureBalanceBreakdown(string $featureName): array
    {
        return $this->getFeatureBalanceBreakdowns([$featureName])[$featureName]
            ?? $this->emptyBalanceBreakdown();
    }

    public function balanceBreakdown(string $featureName): array
    {
        return $this->getFeatureBalanceBreakdown($featureName);
    }

    /**
     * @param iterable<string> $featureNames
     * @return array<string, array<string, float>>
     */
    public function getFeatureBalanceBreakdowns(iterable $featureNames): array
    {
        $featureNames = array_values(array_unique(array_map(
            static fn ($featureName): string => (string) $featureName,
            is_array($featureNames) ? $featureNames : iterator_to_array($featureNames),
        )));
        $breakdowns = [];
        $features = $this->features->whereIn('name', $featureNames)->keyBy('name');
        $featureIds = $features->pluck('id')->all();
        $consumptions = empty($featureIds)
            ? Collection::empty()
            : $this->featureConsumptions()
                ->whereIn('feature_id', $featureIds)
                ->selectRaw('feature_id, SUM(consumption) AS total_consumption')
                ->groupBy('feature_id')
                ->pluck('total_consumption', 'feature_id');
        $tickets = config('soulbscription.feature_tickets') && ! empty($featureIds)
            ? $this->featureTickets()
                ->withoutExpired()
                ->whereIn('feature_id', $featureIds)
                ->get()
                ->groupBy('feature_id')
            : Collection::empty();
        $subscription = $this->currentSoulbscriptionSubscription();
        $subscriptionFeatures = $subscription?->plan?->features ?? Collection::empty();

        foreach ($featureNames as $featureName) {
            $feature = $features->get($featureName);

            if (empty($feature)) {
                $breakdowns[$featureName] = $this->emptyBalanceBreakdown();
                continue;
            }

            $subscriptionFeature = $subscriptionFeatures->firstWhere('id', $feature->id);
            $featureTickets = $tickets->get($feature->id, Collection::empty());
            $periodicAllowance = round((float) ($subscriptionFeature?->pivot?->charges ?? 0)
                + (float) $featureTickets
                    ->where('recurring', true)
                    ->sum(fn (FeatureTicket $ticket): float => (float) ($ticket->charges ?? 0)), 2);
            $periodicConsumption = round((float) ($consumptions->get($feature->id) ?? 0), 2);
            $oneTimeAllowance = round((float) $featureTickets
                ->where('recurring', false)
                ->sum(fn (FeatureTicket $ticket): float => (float) ($ticket->charges ?? 0)), 2);
            $oneTimeConsumed = round((float) $featureTickets
                ->where('recurring', false)
                ->sum(fn (FeatureTicket $ticket): float => (float) ($ticket->consumed ?? 0)), 2);
            $periodicRemaining = round(max($periodicAllowance - $periodicConsumption, 0), 2);
            $oneTimeRemaining = round(max($oneTimeAllowance - $oneTimeConsumed, 0), 2);

            $breakdowns[$featureName] = [
                'periodic_allowance' => $periodicAllowance,
                'periodic_consumption' => $periodicConsumption,
                'periodic_remaining' => $periodicRemaining,
                'one_time_allowance' => $oneTimeAllowance,
                'one_time_consumed' => $oneTimeConsumed,
                'one_time_remaining' => $oneTimeRemaining,
                'total_allowance' => round($periodicAllowance + $oneTimeAllowance, 2),
                'total_remaining' => round($periodicRemaining + $oneTimeRemaining, 2),
            ];
        }

        return $breakdowns;
    }

    protected function consumeNotQuotaFeature(Feature $feature, ?float $consumption = null)
    {
        $featureConsumption = $this->featureConsumptions()
            ->make([
                'consumption' => $consumption,
                'expired_at' => $this->featureConsumptionExpiration($feature),
            ])
            ->feature()
            ->associate($feature);

        $featureConsumption->save();

        return $featureConsumption;
    }

    protected function consumeAllocations(Feature $feature, float $consumption): array
    {
        $tickets = config('soulbscription.feature_tickets')
            ? $this->activeTicketQuery($feature)
                ->orderByRaw('CASE WHEN expired_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('expired_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
            : Collection::empty();

        $breakdown = $this->getFeatureBalanceBreakdown($feature->name);
        $available = $breakdown['total_remaining'];

        if (! $feature->postpaid && $available < $consumption) {
            throw new OverflowException('The feature has no enough charges to this consumption.');
        }

        $periodicRemaining = max($breakdown['periodic_remaining'], 0);
        $periodicConsumption = min($consumption, $periodicRemaining);
        $ticketConsumption = round($consumption - $periodicConsumption, 2);
        $consumedFromTickets = 0.0;
        $ticketAllocations = [];

        foreach ($tickets->where('recurring', false) as $ticket) {
            if ($ticketConsumption <= 0) {
                break;
            }

            $ticketAmount = round(min($ticketConsumption, $ticket->remainingCharges()), 2);
            if ($ticketAmount <= 0) {
                continue;
            }

            $this->consumeTicketLocked($ticket, $ticketAmount);
            $ticketConsumption = round($ticketConsumption - $ticketAmount, 2);
            $consumedFromTickets = round($consumedFromTickets + $ticketAmount, 2);
            $ticketAllocations[] = [
                'ticket' => $ticket,
                'consumption' => $ticketAmount,
            ];
        }

        // Postpaid features can record the portion not covered by any allowance.
        $recordedConsumption = round($consumption - $consumedFromTickets, 2);

        if ($recordedConsumption <= 0) {
            return [
                'feature_consumption' => null,
                'ticket_allocations' => $ticketAllocations,
            ];
        }

        $featureConsumption = $this->featureConsumptions()
            ->make([
                'consumption' => $recordedConsumption,
                'expired_at' => $this->featureConsumptionExpiration($feature),
            ])
            ->feature()
            ->associate($feature);

        $featureConsumption->save();

        return [
            'feature_consumption' => $featureConsumption,
            'ticket_allocations' => $ticketAllocations,
        ];
    }

    protected function consumeTicketLocked(FeatureTicket $ticket, float $amount): void
    {
        $amount = round($amount, 2);

        if (! is_finite($amount) || $amount <= 0 || $amount > $ticket->remainingCharges()) {
            throw new InvalidArgumentException('Ticket consumption exceeds the available ticket balance.');
        }

        $ticket->consumed = round((float) ($ticket->consumed ?? 0) + $amount, 2);
        $ticket->save();
    }

    protected function consumeQuotaFeature(Feature $feature, float $consumption)
    {
        $featureConsumption = $this->featureConsumptions()
            ->whereFeatureId($feature->id)
            ->firstOrNew();

        $featureConsumption->feature()->associate($feature);
        $featureConsumption->consumption = round(
            (float) $featureConsumption->consumption + $consumption,
            2,
        );
        $featureConsumption->save();

        return $featureConsumption;
    }

    protected function getSubscriptionChargesForAFeature(Model $feature): float
    {
        $subscription = $this->currentSoulbscriptionSubscription();

        $subscriptionFeature = $subscription?->plan?->features?->firstWhere('id', $feature->id);

        if (empty($subscriptionFeature)) {
            return 0;
        }

        return $subscriptionFeature
            ->pivot
            ->charges;
    }

    protected function getTicketChargesForAFeature(Model $feature): float
    {
        if (! config('soulbscription.feature_tickets')) {
            return 0;
        }

        return (float) $this->activeTicketQuery($feature)
            ->sum('charges');
    }

    protected function activeTicketQuery(Feature $feature): MorphMany
    {
        return $this->featureTickets()
            ->withoutExpired()
            ->where('feature_id', $feature->id);
    }

    protected function featureConsumptionRecurrenceStart(?Feature $feature = null): CarbonInterface
    {
        $subscriptionStart = $this->currentSoulbscriptionSubscription()?->started_at;

        if ($subscriptionStart) {
            return $subscriptionStart;
        }

        if ($feature && config('soulbscription.feature_tickets')) {
            $ticketStart = $this->featureTickets()
                ->withoutGlobalScope(ExpiringScope::class)
                ->where('feature_id', $feature->id)
                ->where('recurring', true)
                ->orderBy('created_at')
                ->orderBy('id')
                ->value('created_at');

            if ($ticketStart) {
                return Date::parse($ticketStart);
            }
        }

        return now();
    }

    protected function currentSoulbscriptionSubscription(): ?Model
    {
        $subscriptionClass = config('soulbscription.models.subscription');

        return $subscriptionClass::query()
            ->whereMorphedTo('subscriber', $this)
            ->orderByDesc('started_at')
            ->first();
    }

    protected function validateConsumption(?float $consumption): void
    {
        if (! $this->isValidConsumption($consumption)) {
            throw new InvalidArgumentException('The consumption must be a positive finite number.');
        }
    }

    protected function isValidConsumption(?float $consumption): bool
    {
        return $consumption !== null
            && is_finite($consumption)
            && $consumption > 0;
    }

    protected function featureConsumptionExpiration(Feature $feature): ?CarbonInterface
    {
        if (! $feature->consumable || ! $feature->periodicity || ! $feature->periodicity_type) {
            return null;
        }

        return $feature->calculateNextRecurrenceEnd($this->featureConsumptionRecurrenceStart($feature));
    }

    protected function normalizeDecimal(?float $amount): ?float
    {
        return $amount === null ? null : round($amount, 2);
    }

    /** @return array<string, float> */
    protected function emptyBalanceBreakdown(): array
    {
        return [
            'periodic_allowance' => 0.0,
            'periodic_consumption' => 0.0,
            'periodic_remaining' => 0.0,
            'one_time_allowance' => 0.0,
            'one_time_consumed' => 0.0,
            'one_time_remaining' => 0.0,
            'total_allowance' => 0.0,
            'total_remaining' => 0.0,
        ];
    }

    protected function forgetFeatureCaches(): void
    {
        $this->loadedFeatures = null;
        $this->loadedSubscriptionFeatures = null;
        $this->loadedTicketFeatures = null;
        $this->unsetRelation('subscription');
        $this->unsetRelation('featureTickets');
    }

    public function flushFeatureCache(): void
    {
        $this->forgetFeatureCaches();
    }

    public function getFeature(string $featureName): ?Feature
    {
        $feature = $this->features->firstWhere('name', $featureName);

        return $feature;
    }

    public function getFeaturesAttribute(): Collection
    {
        if (! is_null($this->loadedFeatures)) {
            return $this->loadedFeatures;
        }

        $this->loadedFeatures = $this->loadSubscriptionFeatures()
            ->concat($this->loadTicketFeatures());

        return $this->loadedFeatures;
    }

    protected function loadSubscriptionFeatures(): Collection
    {
        if (! is_null($this->loadedSubscriptionFeatures)) {
            return $this->loadedSubscriptionFeatures;
        }

        $this->loadMissing('subscription.plan.features');

        return $this->loadedSubscriptionFeatures = $this->subscription?->plan?->features ?? Collection::empty();
    }

    protected function loadTicketFeatures(): Collection
    {
        if (! config('soulbscription.feature_tickets')) {
            return $this->loadedTicketFeatures = Collection::empty();
        }

        if (! is_null($this->loadedTicketFeatures)) {
            return $this->loadedTicketFeatures;
        }

        return $this->loadedTicketFeatures = Feature::with([
                'tickets' => fn (HasMany $query) => $query->withoutExpired()->whereMorphedTo('subscriber', $this),
            ])
            ->whereHas(
                'tickets',
                fn (Builder $query) => $query->withoutExpired()->whereMorphedTo('subscriber', $this),
            )
            ->get();
    }
}
