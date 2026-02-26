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
 * Integration tests for the `portfolios:migrate-unassigned` Artisan command.
 *
 * The command assigns unassigned users (seller_id IS NULL) to the correct
 * default portfolio based on their order history:
 *   - No orders OR month still open → Venta Fresca
 *   - Month closed since first order → Post Venta
 *
 * Covers: T-MIGRATE-01 through T-MIGRATE-13
 */
class MigrateUnassignedPortfoliosCommandTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private SellerPortfolio $ventaFresca;

    private SellerPortfolio $postVenta;

    private User $sellerFresca;

    private User $sellerPost;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();

        $this->sellerFresca = $this->createSeller('SELLER.FRESCA');
        $this->sellerPost = $this->createSeller('SELLER.POST');

        $this->postVenta = SellerPortfolio::create([
            'name' => 'cartera: POST VENTA',
            'category' => PortfolioCategory::PostVenta,
            'seller_id' => $this->sellerPost->id,
            'is_default' => true,
        ]);

        $this->ventaFresca = SellerPortfolio::create([
            'name' => 'cartera: VENTA FRESCA',
            'category' => PortfolioCategory::VentaFresca,
            'seller_id' => $this->sellerFresca->id,
            'successor_portfolio_id' => $this->postVenta->id,
            'is_default' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // T-MIGRATE-01: User without orders → Venta Fresca
    // ---------------------------------------------------------------

    public function test_assigns_user_without_orders_to_venta_fresca(): void
    {
        $client = $this->createUnassignedClient();

        Carbon::setTestNow('2026-02-26');
        $this->artisan('portfolios:migrate-unassigned')->assertSuccessful();

        $record = UserPortfolio::where('user_id', $client->id)->first();

        $this->assertNotNull($record);
        $this->assertEquals($this->ventaFresca->id, $record->portfolio_id);
        $this->assertTrue($record->is_active);
        $this->assertNull($record->first_order_at);
        $this->assertNull($record->month_closed_at);

        $client->refresh();
        $this->assertEquals($this->sellerFresca->id, $client->seller_id);
    }

    // ---------------------------------------------------------------
    // T-MIGRATE-02: User with orders, month still open → Venta Fresca
    // ---------------------------------------------------------------

    public function test_assigns_user_with_open_month_to_venta_fresca(): void
    {
        $client = $this->createUnassignedClient();

        Carbon::setTestNow('2026-02-15');
        Order::create(['user_id' => $client->id, 'total' => 5000, 'status' => 'pending']);

        Carbon::setTestNow('2026-02-26');
        $this->artisan('portfolios:migrate-unassigned')->assertSuccessful();

        $record = UserPortfolio::where('user_id', $client->id)->first();

        $this->assertNotNull($record);
        $this->assertEquals($this->ventaFresca->id, $record->portfolio_id);
        $this->assertTrue($record->is_active);
        $this->assertEquals('2026-02-15', $record->first_order_at->toDateString());
        $this->assertEquals('2026-03-31', $record->month_closed_at->toDateString());

        $client->refresh();
        $this->assertEquals($this->sellerFresca->id, $client->seller_id);
    }

    // ---------------------------------------------------------------
    // T-MIGRATE-03: User with orders, month closed → Post Venta
    // ---------------------------------------------------------------

    public function test_assigns_user_with_closed_month_to_post_venta(): void
    {
        $client = $this->createUnassignedClient();

        Carbon::setTestNow('2025-12-10');
        Order::create(['user_id' => $client->id, 'total' => 5000, 'status' => 'pending']);

        Carbon::setTestNow('2026-02-26');
        $this->artisan('portfolios:migrate-unassigned')->assertSuccessful();

        $record = UserPortfolio::where('user_id', $client->id)->first();

        $this->assertNotNull($record);
        $this->assertEquals($this->postVenta->id, $record->portfolio_id);
        $this->assertTrue($record->is_active);
        $this->assertEquals('2025-12-10', $record->first_order_at->toDateString());
        $this->assertNull($record->month_closed_at);

        $client->refresh();
        $this->assertEquals($this->sellerPost->id, $client->seller_id);
    }

    // ---------------------------------------------------------------
    // T-MIGRATE-04: --limit processes only the specified amount
    // ---------------------------------------------------------------

    public function test_limit_option_restricts_processed_users(): void
    {
        $this->createUnassignedClient();
        $this->createUnassignedClient();
        $this->createUnassignedClient();

        $this->artisan('portfolios:migrate-unassigned', ['--limit' => 2])->assertSuccessful();

        $this->assertEquals(2, UserPortfolio::count());
    }

    // ---------------------------------------------------------------
    // T-MIGRATE-05: Idempotency - user with active portfolio is skipped
    // ---------------------------------------------------------------

    public function test_skips_user_who_already_has_active_portfolio(): void
    {
        $client = $this->createUnassignedClient();

        UserPortfolio::create([
            'user_id' => $client->id,
            'portfolio_id' => $this->ventaFresca->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        $this->artisan('portfolios:migrate-unassigned')->assertSuccessful();

        $this->assertDatabaseCount('user_portfolio', 1);
    }

    // ---------------------------------------------------------------
    // T-MIGRATE-06: User with seller_id already set is excluded
    // ---------------------------------------------------------------

    public function test_skips_user_who_already_has_seller_assigned(): void
    {
        User::factory()->create([
            'seller_id' => $this->sellerFresca->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->artisan('portfolios:migrate-unassigned')->assertSuccessful();

        $this->assertDatabaseCount('user_portfolio', 0);
    }

    // ---------------------------------------------------------------
    // T-MIGRATE-07: First order on day 1 → month_closed_at = end of same month
    // ---------------------------------------------------------------

    public function test_calculates_month_closed_at_for_first_day_order(): void
    {
        $client = $this->createUnassignedClient();

        Carbon::setTestNow('2026-01-01');
        Order::create(['user_id' => $client->id, 'total' => 5000, 'status' => 'pending']);

        Carbon::setTestNow('2026-01-15');
        $this->artisan('portfolios:migrate-unassigned')->assertSuccessful();

        $record = UserPortfolio::where('user_id', $client->id)->first();

        $this->assertEquals('2026-01-01', $record->first_order_at->toDateString());
        $this->assertEquals('2026-01-31', $record->month_closed_at->toDateString());
    }

    // ---------------------------------------------------------------
    // T-MIGRATE-08: First order on any other day → month_closed_at = end of next month
    // ---------------------------------------------------------------

    public function test_calculates_month_closed_at_for_mid_month_order(): void
    {
        $client = $this->createUnassignedClient();

        Carbon::setTestNow('2026-01-15');
        Order::create(['user_id' => $client->id, 'total' => 5000, 'status' => 'pending']);

        Carbon::setTestNow('2026-01-20');
        $this->artisan('portfolios:migrate-unassigned')->assertSuccessful();

        $record = UserPortfolio::where('user_id', $client->id)->first();

        $this->assertEquals('2026-01-15', $record->first_order_at->toDateString());
        $this->assertEquals('2026-02-28', $record->month_closed_at->toDateString());
    }

    // ---------------------------------------------------------------
    // T-MIGRATE-09: Multiple orders → uses the oldest
    // ---------------------------------------------------------------

    public function test_uses_oldest_order_date_when_client_has_multiple_orders(): void
    {
        $client = $this->createUnassignedClient();

        Carbon::setTestNow('2025-06-20');
        Order::create(['user_id' => $client->id, 'total' => 3000, 'status' => 'pending']);

        Carbon::setTestNow('2025-09-10');
        Order::create(['user_id' => $client->id, 'total' => 4000, 'status' => 'pending']);

        Carbon::setTestNow('2026-02-26');
        $this->artisan('portfolios:migrate-unassigned')->assertSuccessful();

        $record = UserPortfolio::where('user_id', $client->id)->first();

        $this->assertEquals('2025-06-20', $record->first_order_at->toDateString());
        $this->assertEquals($this->postVenta->id, $record->portfolio_id);
    }

    // ---------------------------------------------------------------
    // T-MIGRATE-10: Seller users (is_seller=true) are excluded
    // ---------------------------------------------------------------

    public function test_excludes_users_marked_as_sellers(): void
    {
        User::factory()->create([
            'seller_id' => null,
            'is_seller' => true,
        ]);

        $this->artisan('portfolios:migrate-unassigned')->assertSuccessful();

        $this->assertDatabaseCount('user_portfolio', 0);
    }

    // ---------------------------------------------------------------
    // T-MIGRATE-11: User without branch → migration still works
    // ---------------------------------------------------------------

    public function test_migrates_user_without_branch(): void
    {
        $client = User::factory()->create([
            'seller_id' => null,
            'branch_id' => null,
        ]);

        $this->artisan('portfolios:migrate-unassigned')->assertSuccessful();

        $record = UserPortfolio::where('user_id', $client->id)->first();

        $this->assertNotNull($record);
        $this->assertNull($record->branch_created_at);
    }

    // ---------------------------------------------------------------
    // T-MIGRATE-12: Default limit processes 100 users
    // ---------------------------------------------------------------

    public function test_default_limit_is_100(): void
    {
        User::factory()->count(105)->create([
            'seller_id' => null,
            'branch_id' => $this->branch->id,
        ]);

        $this->artisan('portfolios:migrate-unassigned')->assertSuccessful();

        $this->assertEquals(100, UserPortfolio::count());
    }

    // ---------------------------------------------------------------
    // T-MIGRATE-13: Migrated users integrate with portfolios:sync
    // ---------------------------------------------------------------

    public function test_migrated_users_are_not_reprocessed_by_sync(): void
    {
        $client = $this->createUnassignedClient();

        Carbon::setTestNow('2026-02-26');
        $this->artisan('portfolios:migrate-unassigned')->assertSuccessful();

        $this->artisan('portfolios:sync')->assertSuccessful();

        $activePortfolios = UserPortfolio::where('user_id', $client->id)
            ->where('is_active', true)
            ->get();

        $this->assertCount(1, $activePortfolios);
        $this->assertEquals($this->ventaFresca->id, $activePortfolios->first()->portfolio_id);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createSeller(string $nickname = 'TEST.SELLER'): User
    {
        return User::factory()->create([
            'nickname' => $nickname,
            'is_seller' => true,
        ]);
    }

    private function createUnassignedClient(): User
    {
        return User::factory()->create([
            'seller_id' => null,
            'branch_id' => $this->branch->id,
        ]);
    }
}
