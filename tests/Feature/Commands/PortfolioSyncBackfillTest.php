<?php

namespace Tests\Feature\Commands;

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
 * Integration tests for the backfill edge case in `portfolios:sync`.
 *
 * When a user already had orders before their seller was assigned (via import),
 * the Order observer never fired for those historical orders. The sync command
 * must detect existing orders and retroactively populate first_order_at and
 * month_closed_at when it creates the user_portfolio record.
 *
 * The month_closed_at rule mirrors the observer:
 *   - first order on day 1  → last day of the SAME month
 *   - any other day         → last day of the NEXT month
 *
 * Covers: T-SYNC-08 through T-SYNC-12
 */
class PortfolioSyncBackfillTest extends TestCase
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
    // T-SYNC-08
    // ---------------------------------------------------------------

    /**
     * Single pre-existing order in the current year on a mid-month day.
     * Sync must backfill first_order_at and calculate month_closed_at as last day of next month.
     */
    public function test_backfills_first_order_at_for_single_order_in_current_year(): void
    {
        $client = $this->createClientWithoutSeller();

        Carbon::setTestNow('2026-01-10');
        Order::create(['user_id' => $client->id, 'total' => 5000, 'status' => 'pending']);

        $this->assignSellerToClient($client);

        $this->artisan('portfolios:sync')->assertSuccessful();

        $record = UserPortfolio::where('user_id', $client->id)->first();

        $this->assertEquals('2026-01-10', $record->first_order_at->toDateString());
        $this->assertEquals('2026-02-28', $record->month_closed_at->toDateString());
    }

    // ---------------------------------------------------------------
    // T-SYNC-09
    // ---------------------------------------------------------------

    /**
     * Single pre-existing order from last year on a mid-month day.
     * month_closed_at must cross the year boundary (December → January of next year).
     */
    public function test_backfills_first_order_at_for_order_from_previous_year_mid_month(): void
    {
        $client = $this->createClientWithoutSeller();

        Carbon::setTestNow('2025-12-15');
        Order::create(['user_id' => $client->id, 'total' => 5000, 'status' => 'pending']);

        $this->assignSellerToClient($client);

        Carbon::setTestNow('2026-01-20');
        $this->artisan('portfolios:sync')->assertSuccessful();

        $record = UserPortfolio::where('user_id', $client->id)->first();

        $this->assertEquals('2025-12-15', $record->first_order_at->toDateString());
        $this->assertEquals('2026-01-31', $record->month_closed_at->toDateString());
    }

    // ---------------------------------------------------------------
    // T-SYNC-10
    // ---------------------------------------------------------------

    /**
     * Single pre-existing order from last year on day 1.
     * The day-1 rule applies: month_closed_at = last day of the SAME month.
     */
    public function test_backfills_first_order_at_for_order_from_previous_year_on_day_one(): void
    {
        $client = $this->createClientWithoutSeller();

        Carbon::setTestNow('2025-12-01');
        Order::create(['user_id' => $client->id, 'total' => 5000, 'status' => 'pending']);

        $this->assignSellerToClient($client);

        Carbon::setTestNow('2026-01-20');
        $this->artisan('portfolios:sync')->assertSuccessful();

        $record = UserPortfolio::where('user_id', $client->id)->first();

        $this->assertEquals('2025-12-01', $record->first_order_at->toDateString());
        $this->assertEquals('2025-12-31', $record->month_closed_at->toDateString());
    }

    // ---------------------------------------------------------------
    // T-SYNC-11
    // ---------------------------------------------------------------

    /**
     * Client has three pre-existing orders spread across different years.
     * Sync must use the OLDEST order date, not the most recent one.
     */
    public function test_backfills_with_the_oldest_order_when_client_has_multiple_orders(): void
    {
        $client = $this->createClientWithoutSeller();

        Carbon::setTestNow('2025-03-20');
        Order::create(['user_id' => $client->id, 'total' => 5000, 'status' => 'pending']);

        Carbon::setTestNow('2025-06-05');
        Order::create(['user_id' => $client->id, 'total' => 3000, 'status' => 'pending']);

        Carbon::setTestNow('2026-01-10');
        Order::create(['user_id' => $client->id, 'total' => 4000, 'status' => 'pending']);

        $this->assignSellerToClient($client);

        $this->artisan('portfolios:sync')->assertSuccessful();

        $record = UserPortfolio::where('user_id', $client->id)->first();

        $this->assertEquals('2025-03-20', $record->first_order_at->toDateString());
        $this->assertEquals('2025-04-30', $record->month_closed_at->toDateString());
    }

    // ---------------------------------------------------------------
    // T-SYNC-12
    // ---------------------------------------------------------------

    /**
     * Client has no prior orders at the time the seller is assigned.
     * Sync creates the portfolio with first_order_at = null so the observer
     * can register it when the first real order arrives.
     */
    public function test_creates_portfolio_with_null_first_order_at_when_client_has_no_prior_orders(): void
    {
        $client = $this->createClientWithoutSeller();

        $this->assignSellerToClient($client);

        $this->artisan('portfolios:sync')->assertSuccessful();

        $record = UserPortfolio::where('user_id', $client->id)->first();

        $this->assertNotNull($record, 'UserPortfolio record should be created');
        $this->assertNull($record->first_order_at);
        $this->assertNull($record->month_closed_at);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createClientWithoutSeller(): User
    {
        return User::factory()->create([
            'seller_id' => null,
            'branch_id' => $this->branch->id,
        ]);
    }

    private function assignSellerToClient(User $client): void
    {
        $seller = User::factory()->create(['is_seller' => true]);

        SellerPortfolio::create([
            'name' => 'Test Portfolio',
            'category' => PortfolioCategory::VentaFresca,
            'seller_id' => $seller->id,
        ]);

        $client->update(['seller_id' => $seller->id]);
    }
}
