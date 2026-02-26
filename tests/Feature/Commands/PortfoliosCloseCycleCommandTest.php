<?php

namespace Tests\Feature\Commands;

use App\Enums\PortfolioCategory;
use App\Models\Branch;
use App\Models\SellerPortfolio;
use App\Models\User;
use App\Models\UserPortfolio;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration tests for the `portfolios:close-cycle` Artisan command.
 *
 * The command processes active UserPortfolio records whose month_closed_at
 * has expired and migrates them to the successor portfolio.
 *
 * Covers: T-CLOSE-01 through T-CLOSE-04
 */
class PortfoliosCloseCycleCommandTest extends TestCase
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
    // T-CLOSE-01
    // ---------------------------------------------------------------

    /**
     * When a client's month_closed_at expires and the portfolio has a successor,
     * close-cycle must close the current record and create a new one in the
     * successor portfolio.
     */
    public function test_migrates_expired_portfolio_to_successor(): void
    {
        $sellerFresca = $this->createSeller('SELLER.FRESCA');
        $sellerPost = $this->createSeller('SELLER.POST');

        $postVenta = $this->createPortfolio($sellerPost, PortfolioCategory::PostVenta);
        $ventaFresca = $this->createPortfolio($sellerFresca, PortfolioCategory::VentaFresca, $postVenta);

        $client = $this->createClient($sellerFresca);

        Carbon::setTestNow('2026-01-15');
        $userPortfolio = $this->createActiveUserPortfolio($client, $ventaFresca, [
            'first_order_at' => Carbon::parse('2026-01-15'),
            'month_closed_at' => Carbon::parse('2026-02-28'),
        ]);

        Carbon::setTestNow('2026-03-01');
        $this->artisan('portfolios:close-cycle')->assertSuccessful();

        $this->assertDatabaseHas('user_portfolio', [
            'id' => $userPortfolio->id,
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('user_portfolio', [
            'user_id' => $client->id,
            'portfolio_id' => $postVenta->id,
            'is_active' => true,
            'previous_portfolio_id' => $ventaFresca->id,
        ]);
    }

    // ---------------------------------------------------------------
    // T-CLOSE-02
    // ---------------------------------------------------------------

    /**
     * When a portfolio has no successor, close-cycle must leave the
     * record untouched even if month_closed_at has expired.
     */
    public function test_skips_expired_portfolio_without_successor(): void
    {
        $seller = $this->createSeller();
        $portfolio = $this->createPortfolio($seller, PortfolioCategory::PostVenta);
        $client = $this->createClient($seller);

        Carbon::setTestNow('2026-01-15');
        $userPortfolio = $this->createActiveUserPortfolio($client, $portfolio, [
            'month_closed_at' => Carbon::parse('2026-02-28'),
        ]);

        Carbon::setTestNow('2026-03-01');
        $this->artisan('portfolios:close-cycle')->assertSuccessful();

        $this->assertDatabaseHas('user_portfolio', [
            'id' => $userPortfolio->id,
            'is_active' => true,
        ]);

        $this->assertDatabaseCount('user_portfolio', 1);
    }

    // ---------------------------------------------------------------
    // T-CLOSE-03
    // ---------------------------------------------------------------

    /**
     * Active portfolios whose month_closed_at is still in the future
     * must not be processed by close-cycle.
     */
    public function test_ignores_portfolios_with_future_month_closed_at(): void
    {
        $sellerFresca = $this->createSeller('SELLER.FRESCA');
        $sellerPost = $this->createSeller('SELLER.POST');

        $postVenta = $this->createPortfolio($sellerPost, PortfolioCategory::PostVenta);
        $ventaFresca = $this->createPortfolio($sellerFresca, PortfolioCategory::VentaFresca, $postVenta);

        $client = $this->createClient($sellerFresca);

        Carbon::setTestNow('2026-02-15');
        $this->createActiveUserPortfolio($client, $ventaFresca, [
            'month_closed_at' => Carbon::parse('2026-03-31'),
        ]);

        $this->artisan('portfolios:close-cycle')->assertSuccessful();

        $this->assertDatabaseCount('user_portfolio', 1);
        $this->assertDatabaseHas('user_portfolio', [
            'user_id' => $client->id,
            'is_active' => true,
            'portfolio_id' => $ventaFresca->id,
        ]);
    }

    // ---------------------------------------------------------------
    // T-CLOSE-04
    // ---------------------------------------------------------------

    /**
     * When close-cycle migrates a client to the successor portfolio,
     * the user's seller_id must be updated to match the successor
     * portfolio's seller. After this, portfolios:sync must see no
     * mismatch and leave the portfolio untouched.
     */
    public function test_updates_user_seller_id_when_migrating_to_successor_portfolio(): void
    {
        $sellerFresca = $this->createSeller('SELLER.FRESCA');
        $sellerPost = $this->createSeller('SELLER.POST');

        $postVenta = $this->createPortfolio($sellerPost, PortfolioCategory::PostVenta);
        $ventaFresca = $this->createPortfolio($sellerFresca, PortfolioCategory::VentaFresca, $postVenta);

        $client = $this->createClient($sellerFresca);

        Carbon::setTestNow('2026-01-15');
        $this->createActiveUserPortfolio($client, $ventaFresca, [
            'first_order_at' => Carbon::parse('2026-01-15'),
            'month_closed_at' => Carbon::parse('2026-02-28'),
        ]);

        Carbon::setTestNow('2026-03-01');
        $this->artisan('portfolios:close-cycle')->assertSuccessful();

        $client->refresh();
        $this->assertEquals(
            $sellerPost->id,
            $client->seller_id,
            'User seller_id must match the successor portfolio seller after cycle close'
        );

        // Sync must not re-create the portfolio back in Venta Fresca
        $this->artisan('portfolios:sync')->assertSuccessful();

        $activePortfolios = UserPortfolio::where('user_id', $client->id)
            ->where('is_active', true)
            ->get();

        $this->assertCount(1, $activePortfolios);
        $this->assertEquals($postVenta->id, $activePortfolios->first()->portfolio_id);
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

    private function createPortfolio(
        User $seller,
        PortfolioCategory $category = PortfolioCategory::VentaFresca,
        ?SellerPortfolio $successor = null,
    ): SellerPortfolio {
        return SellerPortfolio::create([
            'name' => 'Cartera '.$seller->nickname,
            'category' => $category,
            'seller_id' => $seller->id,
            'successor_portfolio_id' => $successor?->id,
            'is_default' => true,
        ]);
    }

    private function createClient(User $seller): User
    {
        return User::factory()->create([
            'seller_id' => $seller->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    private function createActiveUserPortfolio(User $client, SellerPortfolio $portfolio, array $extra = []): UserPortfolio
    {
        return UserPortfolio::create(array_merge([
            'user_id' => $client->id,
            'portfolio_id' => $portfolio->id,
            'is_active' => true,
            'assigned_at' => now(),
        ], $extra));
    }
}
