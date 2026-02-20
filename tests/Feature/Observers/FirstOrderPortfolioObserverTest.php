<?php

namespace Tests\Feature\Observers;

use App\Enums\PortfolioCategory;
use App\Models\Branch;
use App\Models\Order;
use App\Models\SellerPortfolio;
use App\Models\User;
use App\Models\UserPortfolio;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration tests for the Order observer + job that tracks the first order
 * date for each client's active portfolio.
 *
 * When a client places their first order, the system must record:
 *   - first_order_at: the date of the order
 *   - month_closed_at: last day of current month (if order is on day 1)
 *                      or last day of NEXT month (any other day)
 *
 * Covers: T-OBS-01 through T-OBS-05
 */
class FirstOrderPortfolioObserverTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // T-OBS-01
    // ---------------------------------------------------------------

    /**
     * Order placed on a mid-month day (not the 1st) sets first_order_at to that date
     * and month_closed_at to the last day of the NEXT month.
     */
    public function test_first_order_on_mid_month_sets_month_closed_at_to_end_of_next_month(): void
    {
        Carbon::setTestNow('2026-01-15');

        $client = $this->createClientWithActivePortfolio();

        Order::create(['user_id' => $client->id, 'total' => 5000, 'status' => 'pending']);

        $record = $this->getActivePortfolioRecord($client);

        $this->assertEquals('2026-01-15', $record->first_order_at->toDateString());
        $this->assertEquals('2026-02-28', $record->month_closed_at->toDateString());
    }

    // ---------------------------------------------------------------
    // T-OBS-02
    // ---------------------------------------------------------------

    /**
     * Order placed on day 1 of the month sets first_order_at to that date
     * and month_closed_at to the last day of the SAME month.
     */
    public function test_first_order_on_day_one_sets_month_closed_at_to_end_of_same_month(): void
    {
        Carbon::setTestNow('2026-01-01');

        $client = $this->createClientWithActivePortfolio();

        Order::create(['user_id' => $client->id, 'total' => 5000, 'status' => 'pending']);

        $record = $this->getActivePortfolioRecord($client);

        $this->assertEquals('2026-01-01', $record->first_order_at->toDateString());
        $this->assertEquals('2026-01-31', $record->month_closed_at->toDateString());
    }

    // ---------------------------------------------------------------
    // T-OBS-03
    // ---------------------------------------------------------------

    /**
     * A second order placed after the first must not overwrite first_order_at
     * or recalculate month_closed_at. Both dates are locked after the first order.
     */
    public function test_subsequent_orders_do_not_modify_first_order_at_or_month_closed_at(): void
    {
        Carbon::setTestNow('2026-01-10');

        $client = $this->createClientWithActivePortfolio();

        Order::create(['user_id' => $client->id, 'total' => 5000, 'status' => 'pending']);

        Carbon::setTestNow('2026-02-05');

        Order::create(['user_id' => $client->id, 'total' => 3000, 'status' => 'pending']);

        $record = $this->getActivePortfolioRecord($client);

        $this->assertEquals('2026-01-10', $record->first_order_at->toDateString());
        $this->assertEquals('2026-02-28', $record->month_closed_at->toDateString());
    }

    // ---------------------------------------------------------------
    // T-OBS-04
    // ---------------------------------------------------------------

    /**
     * An order from a user with no active portfolio must be silently ignored.
     * No user_portfolio record should be created or modified, and no error thrown.
     */
    public function test_order_for_user_without_active_portfolio_is_silently_ignored(): void
    {
        $client = User::factory()->create([
            'branch_id' => $this->branch->id,
        ]);

        Order::create(['user_id' => $client->id, 'total' => 5000, 'status' => 'pending']);

        $this->assertDatabaseCount('user_portfolio', 0);
    }

    // ---------------------------------------------------------------
    // T-OBS-05
    // ---------------------------------------------------------------

    /**
     * If two orders are created close together, both jobs must process without error
     * but first_order_at must be written exactly once. The second job detects
     * that first_order_at is already set and performs no update.
     */
    public function test_two_orders_for_same_user_set_first_order_at_only_once(): void
    {
        Carbon::setTestNow('2026-01-15');

        $client = $this->createClientWithActivePortfolio();

        Order::create(['user_id' => $client->id, 'total' => 5000, 'status' => 'pending']);
        Order::create(['user_id' => $client->id, 'total' => 3000, 'status' => 'pending']);

        $record = $this->getActivePortfolioRecord($client);

        $this->assertEquals('2026-01-15', $record->first_order_at->toDateString());
        $this->assertEquals(
            1,
            UserPortfolio::where('user_id', $client->id)->whereNotNull('first_order_at')->count(),
            'first_order_at should only be recorded once regardless of how many orders exist'
        );
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createClientWithActivePortfolio(): User
    {
        $seller = User::factory()->create(['is_seller' => true]);

        $portfolio = SellerPortfolio::create([
            'name' => 'Test Portfolio',
            'category' => PortfolioCategory::VentaFresca,
            'seller_id' => $seller->id,
        ]);

        $client = User::factory()->create([
            'seller_id' => $seller->id,
            'branch_id' => $this->branch->id,
        ]);

        UserPortfolio::create([
            'user_id' => $client->id,
            'portfolio_id' => $portfolio->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        return $client;
    }

    private function getActivePortfolioRecord(User $client): UserPortfolio
    {
        $record = UserPortfolio::where('user_id', $client->id)
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($record, 'Expected an active user_portfolio record');

        return $record;
    }
}
