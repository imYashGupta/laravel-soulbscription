<?php

namespace Tests\Feature\Models\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Illuminate\Support\Facades\Event;
use LucasDotVin\Soulbscription\Events\FeatureConsumed;
use LucasDotVin\Soulbscription\Events\FeatureTicketConsumed;
use LucasDotVin\Soulbscription\Events\FeatureTicketCreated;
use LucasDotVin\Soulbscription\Models\Feature;
use LucasDotVin\Soulbscription\Models\FeatureTicket;
use LucasDotVin\Soulbscription\Models\Plan;
use OverflowException;
use Tests\Mocks\Models\User;
use Tests\TestCase;

class OneTimeFeatureTicketsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('soulbscription.feature_tickets', true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testLegacyTicketsDefaultToRecurringAndZeroConsumed(): void
    {
        $feature = Feature::factory()->consumable()->createOne();
        $subscriber = User::factory()->createOne();

        $ticket = $subscriber->giveTicketFor($feature->name, null, 10);

        $this->assertTrue($ticket->recurring);
        $this->assertSame(0.0, $ticket->consumed);
    }

    public function testOneTimeTicketIsConsumedForItsLifetime(): void
    {
        $feature = Feature::factory()->consumable()->createOne([
            'periodicity' => 1,
            'periodicity_type' => 'Month',
        ]);
        $subscriber = User::factory()->createOne();
        $ticket = $subscriber->giveOneTimeTicketFor($feature->name, null, 10);

        $subscriber->consume($feature->name, 4);

        $this->assertEquals(4, $ticket->fresh()->consumed);
        $this->assertEquals(6, $subscriber->getRemainingCharges($feature->name));

        Carbon::setTestNow(now()->addYear());

        $this->assertEquals(6, $subscriber->fresh()->getRemainingCharges($feature->name));
    }

    public function testTicketOnlyRecurringConsumptionUsesTheOldestTicketAsItsPeriodAnchor(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 10:00:00'));

        $feature = Feature::factory()->consumable()->createOne([
            'periodicity' => 1,
            'periodicity_type' => 'Month',
        ]);
        $subscriber = User::factory()->createOne();
        $oldest = $subscriber->giveRecurringTicketFor($feature->name, now()->subDay(), 10);
        $oldest->created_at = now()->subMonth();
        $oldest->save();
        $subscriber->giveRecurringTicketFor($feature->name, null, 10);

        $subscriber->consume($feature->name, 4);

        Carbon::setTestNow(Carbon::parse('2026-02-02 10:00:00'));

        $this->assertEquals(10, $subscriber->fresh()->getRemainingCharges($feature->name));
    }

    public function testRecurringOnlyConsumptionKeepsTheLegacyFeatureConsumedEvent(): void
    {
        $feature = Feature::factory()->consumable()->createOne();
        $subscriber = User::factory()->createOne();
        $subscriber->giveRecurringTicketFor($feature->name, null, 2);
        Event::fake();

        $subscriber->consume($feature->name, 1);

        Event::assertDispatched(FeatureConsumed::class);
        Event::assertNotDispatched(FeatureTicketConsumed::class);
    }

    public function testMixedConsumptionDispatchesPersistedExactTicketAllocations(): void
    {
        $feature = Feature::factory()->consumable()->createOne();
        $plan = Plan::factory()->createOne();
        $feature->plans()->attach($plan, ['charges' => 5]);
        $subscriber = User::factory()->createOne();
        $subscriber->subscribeTo($plan);
        $ticket = $subscriber->giveOneTimeTicketFor($feature->name, null, 10);
        Event::fake();

        $subscriber->consume($feature->name, 8);

        Event::assertDispatched(FeatureConsumed::class, function (FeatureConsumed $event): bool {
            return $event->featureConsumption->exists && $event->featureConsumption->consumption == 5;
        });
        Event::assertDispatched(
            FeatureTicketConsumed::class,
            function (FeatureTicketConsumed $event) use ($ticket): bool {
                return $event->featureTicket->exists
                    && $event->featureTicket->is($ticket)
                    && $event->consumption == 3;
            },
        );
    }

    public function testTicketOnlyConsumptionDispatchesOnlyThePersistedTicketEvent(): void
    {
        $feature = Feature::factory()->consumable()->createOne();
        $subscriber = User::factory()->createOne();
        $ticket = $subscriber->giveOneTimeTicketFor($feature->name, null, 2);
        Event::fake();

        $subscriber->consume($feature->name, 1.25);

        Event::assertNotDispatched(FeatureConsumed::class);
        Event::assertDispatched(
            FeatureTicketConsumed::class,
            function (FeatureTicketConsumed $event) use ($ticket): bool {
                return $event->featureTicket->exists
                    && $event->featureTicket->is($ticket)
                    && $event->consumption == 1.25;
            },
        );

        Event::assertDispatched(FeatureTicketConsumed::class, function (FeatureTicketConsumed $event): bool {
            $restored = unserialize(serialize($event));

            return $restored->featureTicket->exists
                && $restored->featureTicket->consumed == 1.25;
        });
    }

    public function testTicketCreatedEventSeesTheFreshBalanceAfterCacheInvalidation(): void
    {
        $feature = Feature::factory()->consumable()->createOne();
        $subscriber = User::factory()->createOne();
        $subscriber->features;
        $balance = null;
        Event::listen(
            FeatureTicketCreated::class,
            function (FeatureTicketCreated $event) use (&$balance, $feature): void {
                $balance = $event->subscriber->getFeatureBalanceBreakdown($feature->name)['one_time_remaining'];
            },
        );

        $subscriber->giveOneTimeTicketFor($feature->name, null, 3);

        $this->assertSame(3.0, $balance);
    }

    public function testSubscriptionTransitionsInvalidateFeatureCachesOnTheSameSubscriber(): void
    {
        $oldFeature = Feature::factory()->consumable()->createOne();
        $newFeature = Feature::factory()->consumable()->createOne();
        $oldPlan = Plan::factory()->createOne();
        $newPlan = Plan::factory()->createOne();
        $oldFeature->plans()->attach($oldPlan, ['charges' => 1]);
        $newFeature->plans()->attach($newPlan, ['charges' => 2]);
        $subscriber = User::factory()->createOne();

        $subscriber->subscribeTo($oldPlan);
        $subscriber->features;
        $subscriber->switchTo($newPlan);

        $this->assertTrue($subscriber->hasFeature($newFeature->name));
        $this->assertFalse($subscriber->hasFeature($oldFeature->name));
        $this->assertSame(2.0, $subscriber->getTotalCharges($newFeature->name));
    }

    public function testPublicFeatureCacheFlushReflectsDirectTicketEdits(): void
    {
        $feature = Feature::factory()->consumable()->createOne();
        $subscriber = User::factory()->createOne();
        $ticket = $subscriber->giveOneTimeTicketFor($feature->name, null, 3);
        $subscriber->features;

        FeatureTicket::query()->whereKey($ticket->id)->update(['consumed' => 2]);

        $subscriber->flushFeatureCache();

        $this->assertSame(1.0, $subscriber->getFeatureBalanceBreakdown($feature->name)['one_time_remaining']);
    }

    public function testTicketDoesNotExposeAStandaloneConsumptionApi(): void
    {
        $subscriber = User::factory()->createOne();
        $recurringFeature = Feature::factory()->consumable()->createOne();
        $ticket = $subscriber->featureTickets()->make([
            'feature_id' => $recurringFeature->id,
            'charges' => 1,
            'recurring' => true,
        ]);
        $this->assertFalse(method_exists($ticket, 'consume'));
    }

    public function testInvalidTicketRowsCannotBeAllocated(): void
    {
        $subscriber = User::factory()->createOne();
        $cases = [
            [
                'feature' => Feature::factory()->notConsumable()->createOne(),
                'expired_at' => now()->subDay(),
            ],
            [
                'feature' => Feature::factory()->notConsumable()->createOne(),
                'expired_at' => null,
            ],
            [
                'feature' => Feature::factory()->quota()->createOne(),
                'expired_at' => null,
            ],
        ];

        foreach ($cases as $case) {
            $ticket = $subscriber->featureTickets()->make([
                'feature_id' => $case['feature']->id,
                'charges' => 1,
                'recurring' => false,
                'expired_at' => $case['expired_at'],
            ]);
            $ticket->feature()->associate($case['feature']);
            $ticket->save();

            $this->assertEquals(0, $ticket->fresh()->consumed);
        }
    }

    public function testFullyConsumedOneTimeTicketRemainsAFeatureButCannotBeConsumed(): void
    {
        $feature = Feature::factory()->consumable()->createOne();
        $subscriber = User::factory()->createOne();
        $subscriber->giveOneTimeTicketFor($feature->name, null, 1);

        $subscriber->consume($feature->name, 1);

        $this->assertTrue($subscriber->fresh()->hasFeature($feature->name));
        $this->assertFalse($subscriber->fresh()->canConsume($feature->name, 1));
    }

    public function testPeriodicAllowanceIsSpentBeforeOneTimeTicketsInFefoOrder(): void
    {
        $feature = Feature::factory()->consumable()->createOne();
        $plan = Plan::factory()->createOne();
        $feature->plans()->attach($plan, ['charges' => 5]);
        $subscriber = User::factory()->createOne();
        $subscriber->subscribeTo($plan);

        $early = $subscriber->giveOneTimeTicketFor($feature->name, now()->addDay(), 2);
        $late = $subscriber->giveOneTimeTicketFor($feature->name, now()->addDays(2), 5);

        $subscriber->consume($feature->name, 8);

        $this->assertEquals(0, $early->fresh()->remainingCharges());
        $this->assertEquals(1, $late->fresh()->consumed);
        $this->assertEquals(4, $subscriber->getRemainingCharges($feature->name));
    }

    public function testInsufficientConsumptionRollsBackTicketAndConsumptionRows(): void
    {
        $feature = Feature::factory()->consumable()->createOne();
        $subscriber = User::factory()->createOne();
        $ticket = $subscriber->giveOneTimeTicketFor($feature->name, null, 3);

        $this->expectException(OverflowException::class);

        try {
            $subscriber->consume($feature->name, 4);
        } finally {
            $this->assertEquals(0, $ticket->fresh()->consumed);
            $this->assertDatabaseCount('feature_consumptions', 0);
        }
    }

    public function testInvalidConsumptionsAreRejectedAndFractionalValuesAreAccepted(): void
    {
        $feature = Feature::factory()->consumable()->createOne();
        $subscriber = User::factory()->createOne();
        $subscriber->giveOneTimeTicketFor($feature->name, null, 2);

        $this->expectException(InvalidArgumentException::class);
        $subscriber->consume($feature->name, 0);
    }

    public function testFractionalConsumptionIsAccepted(): void
    {
        $feature = Feature::factory()->consumable()->createOne();
        $subscriber = User::factory()->createOne();
        $ticket = $subscriber->giveOneTimeTicketFor($feature->name, null, 2);

        $subscriber->consume($feature->name, 0.5);

        $this->assertEquals(0.5, $ticket->fresh()->consumed);
    }

    public function testDecimalConsumptionUsesDatabaseScaleAtBoundaries(): void
    {
        $feature = Feature::factory()->consumable()->createOne();
        $subscriber = User::factory()->createOne();
        $ticket = $subscriber->giveOneTimeTicketFor($feature->name, null, 0.3);

        $subscriber->consume($feature->name, 0.1);
        $subscriber->consume($feature->name, 0.2);

        $this->assertSame(0.3, $ticket->fresh()->consumed);
        $this->assertSame(0.0, $ticket->fresh()->remainingCharges());
        $this->assertFalse($subscriber->fresh()->canConsume($feature->name, 0.01));
    }

    public function testTicketExpiringAtTheConsumptionBoundaryIsUnavailable(): void
    {
        $feature = Feature::factory()->consumable()->createOne();
        $subscriber = User::factory()->createOne();
        $subscriber->giveOneTimeTicketFor($feature->name, now(), 1);

        $this->assertFalse($subscriber->fresh()->hasFeature($feature->name));
        $this->expectException(\OutOfBoundsException::class);

        $subscriber->consume($feature->name, 1);
    }

    public function testOneTimeGrantRejectsNonConsumableFeaturesAndInvalidCharges(): void
    {
        $feature = Feature::factory()->notConsumable()->createOne();
        $subscriber = User::factory()->createOne();

        $this->expectException(InvalidArgumentException::class);

        $subscriber->giveOneTimeTicketFor($feature->name, null, 1);
    }

    public function testTicketCounterCannotBeOverfilledThroughPublicAllocation(): void
    {
        $feature = Feature::factory()->consumable()->createOne();
        $subscriber = User::factory()->createOne();
        $ticket = $subscriber->giveOneTimeTicketFor($feature->name, null, 2);

        $this->expectException(OverflowException::class);

        try {
            $subscriber->consume($feature->name, 3);
        } finally {
            $this->assertEquals(0, $ticket->fresh()->consumed);
        }
    }

    public function testBalanceBreakdownsCanBeCalculatedAsABatch(): void
    {
        $first = Feature::factory()->consumable()->createOne();
        $second = Feature::factory()->consumable()->createOne();
        $subscriber = User::factory()->createOne();
        $subscriber->giveOneTimeTicketFor($first->name, null, 3);
        $subscriber->giveOneTimeTicketFor($second->name, null, 4);

        $breakdowns = $subscriber->getFeatureBalanceBreakdowns([$first->name, $second->name]);

        $this->assertSame(3.0, $breakdowns[$first->name]['one_time_remaining']);
        $this->assertSame(4.0, $breakdowns[$second->name]['total_remaining']);
    }
}
