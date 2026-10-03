<?php

namespace Tests\Feature\Models\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Date;
use LucasDotVin\Soulbscription\Enums\PeriodicityType;
use LucasDotVin\Soulbscription\Models\Feature;
use LucasDotVin\Soulbscription\Models\Plan;
use Tests\Mocks\Models\User;
use Tests\TestCase;

/**
 * Laravel's starter kits call Date::use(CarbonImmutable::class), after which
 * now(), today() and every date cast return CarbonImmutable. Nothing here may
 * insist on the mutable Illuminate\Support\Carbon.
 */
class ImmutableDatesTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    protected function setUp(): void
    {
        parent::setUp();

        Date::use(CarbonImmutable::class);
    }

    protected function tearDown(): void
    {
        Date::useDefault();

        parent::tearDown();
    }

    public function testPlanCalculatesItsNextRecurrenceEnd()
    {
        Date::setTestNow(now()->startOfSecond());

        $plan = Plan::factory()->createOne([
            'periodicity_type' => PeriodicityType::Month,
            'periodicity' => 1,
        ]);

        $this->assertEquals(now()->addMonth(), $plan->calculateNextRecurrenceEnd());
        $this->assertEquals(now()->addMonth(), $plan->calculateNextRecurrenceEnd(now()->toDateTimeString()));
    }

    public function testModelSubscribesToAPeriodicPlanWithGraceDays()
    {
        Date::setTestNow(now()->startOfSecond());

        $plan = Plan::factory()->withGraceDays()->createOne([
            'periodicity_type' => PeriodicityType::Month,
            'periodicity' => 1,
        ]);

        $subscription = User::factory()->createOne()->subscribeTo($plan);

        $this->assertEquals(now()->addMonth(), $subscription->expired_at);
        $this->assertEquals(now()->addMonth()->addDays($plan->grace_days), $subscription->grace_days_ended_at);
    }

    public function testModelConsumesAPeriodicFeature()
    {
        $plan = Plan::factory()->createOne([
            'periodicity_type' => PeriodicityType::Year,
            'periodicity' => 1,
        ]);
        $feature = Feature::factory()->consumable()->createOne([
            'periodicity_type' => PeriodicityType::Month,
            'periodicity' => 1,
        ]);
        $feature->plans()->attach($plan, ['charges' => 10]);

        $subscriber = User::factory()->createOne();
        $subscription = $subscriber->subscribeTo($plan);

        $subscriber->consume($feature->name, 1);

        $this->assertDatabaseHas('feature_consumptions', [
            'feature_id' => $feature->id,
            'expired_at' => $feature->calculateNextRecurrenceEnd($subscription->started_at),
        ]);
        $this->assertEquals(9, $subscriber->getRemainingCharges($feature->name));
    }

    public function testSubscriptionRenewsCancelsAndSuppresses()
    {
        $plan = Plan::factory()->withGraceDays()->createOne([
            'periodicity_type' => PeriodicityType::Month,
            'periodicity' => 1,
        ]);

        $subscription = User::factory()->createOne()->subscribeTo($plan);

        $subscription->renew(now()->addMonths(2));
        $subscription->cancel(now());
        $subscription->suppress(now());

        $this->assertNotNull($subscription->fresh()->suppressed_at);
    }
}
