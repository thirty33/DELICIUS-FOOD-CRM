<?php

namespace Tests\Feature\Commands;

use App\Enums\PortfolioCategory;
use App\Models\Branch;
use App\Models\SellerPortfolio;
use App\Models\User;
use App\Models\UserPortfolio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration tests for the `portfolios:sync` Artisan command.
 *
 * The command processes all client users (seller_id set, is_seller = false)
 * and ensures their user_portfolio records reflect the current seller assignment.
 *
 * Covers: T-SYNC-01 through T-SYNC-07
 */
class PortfolioSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
    }

    // ---------------------------------------------------------------
    // T-SYNC-01
    // ---------------------------------------------------------------

    /**
     * Happy path: a client with a seller_id and the seller already has a portfolio.
     * The command must create the user_portfolio record with is_active=true,
     * assigned_at and branch_created_at populated, and all other dates null.
     */
    public function test_creates_user_portfolio_when_client_has_seller_with_portfolio(): void
    {
        $seller = $this->createSeller();
        $portfolio = $this->createPortfolio($seller);
        $client = $this->createClient($seller);

        $this->artisan('portfolios:sync')->assertSuccessful();

        $record = UserPortfolio::where('user_id', $client->id)->first();

        $this->assertNotNull($record, 'A user_portfolio record should be created');
        $this->assertEquals($portfolio->id, $record->portfolio_id);
        $this->assertTrue($record->is_active);
        $this->assertNotNull($record->assigned_at);
        $this->assertNotNull($record->branch_created_at);
        $this->assertNull($record->first_order_at);
        $this->assertNull($record->month_closed_at);
        $this->assertNull($record->previous_portfolio_id);
    }

    // ---------------------------------------------------------------
    // T-SYNC-02
    // ---------------------------------------------------------------

    /**
     * The client has a seller_id but the seller has no portfolios in seller_portfolios.
     * The command cannot assign any portfolio, so no user_portfolio record must be created.
     */
    public function test_does_not_create_user_portfolio_when_seller_has_no_portfolio(): void
    {
        $seller = $this->createSeller();
        $client = $this->createClient($seller);

        $this->artisan('portfolios:sync')->assertSuccessful();

        $this->assertDatabaseCount('user_portfolio', 0);
    }

    // ---------------------------------------------------------------
    // T-SYNC-03
    // ---------------------------------------------------------------

    /**
     * The client already has a correct active user_portfolio pointing to the current seller.
     * Running the command twice must not create duplicate records or alter the existing one.
     */
    public function test_is_idempotent_for_clients_already_correctly_assigned(): void
    {
        $seller = $this->createSeller();
        $portfolio = $this->createPortfolio($seller);
        $client = $this->createClient($seller);
        $this->createActiveUserPortfolio($client, $portfolio);

        $this->artisan('portfolios:sync')->assertSuccessful();
        $this->artisan('portfolios:sync')->assertSuccessful();

        $this->assertDatabaseCount('user_portfolio', 1);
        $this->assertDatabaseHas('user_portfolio', [
            'user_id' => $client->id,
            'is_active' => true,
        ]);
    }

    // ---------------------------------------------------------------
    // T-SYNC-04
    // ---------------------------------------------------------------

    /**
     * The client's seller_id was updated from Seller A to Seller B (both have portfolios).
     * The command must close the old record (is_active=false) and open a new one for
     * Seller B's portfolio, linking it back via previous_portfolio_id for traceability.
     */
    public function test_closes_previous_portfolio_and_creates_new_when_seller_changes(): void
    {
        $sellerA = $this->createSeller('SELLER.A');
        $sellerB = $this->createSeller('SELLER.B');
        $portfolioA = $this->createPortfolio($sellerA);
        $portfolioB = $this->createPortfolio($sellerB);
        $client = $this->createClient($sellerA);
        $this->createActiveUserPortfolio($client, $portfolioA);

        $client->update(['seller_id' => $sellerB->id]);

        $this->artisan('portfolios:sync')->assertSuccessful();

        $this->assertDatabaseHas('user_portfolio', [
            'user_id' => $client->id,
            'portfolio_id' => $portfolioA->id,
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('user_portfolio', [
            'user_id' => $client->id,
            'portfolio_id' => $portfolioB->id,
            'is_active' => true,
            'previous_portfolio_id' => $portfolioA->id,
        ]);

        $this->assertDatabaseCount('user_portfolio', 2);
        $this->assertEquals(
            1,
            UserPortfolio::where('user_id', $client->id)->where('is_active', true)->count(),
            'Only one active user_portfolio should exist for the client'
        );
    }

    // ---------------------------------------------------------------
    // T-SYNC-05
    // ---------------------------------------------------------------

    /**
     * The client's seller_id was updated to Seller B, but Seller B has no portfolios.
     * The command must leave the existing active record untouched rather than closing it
     * without a valid destination portfolio.
     */
    public function test_does_not_alter_active_portfolio_when_new_seller_has_no_portfolio(): void
    {
        $sellerA = $this->createSeller('SELLER.A');
        $sellerB = $this->createSeller('SELLER.B');
        $portfolioA = $this->createPortfolio($sellerA);
        $client = $this->createClient($sellerA);
        $this->createActiveUserPortfolio($client, $portfolioA);

        $client->update(['seller_id' => $sellerB->id]);

        $this->artisan('portfolios:sync')->assertSuccessful();

        $this->assertDatabaseCount('user_portfolio', 1);
        $this->assertDatabaseHas('user_portfolio', [
            'user_id' => $client->id,
            'portfolio_id' => $portfolioA->id,
            'is_active' => true,
        ]);
    }

    // ---------------------------------------------------------------
    // T-SYNC-06
    // ---------------------------------------------------------------

    /**
     * Users without a seller_id are not clients of any seller.
     * The command must skip them entirely and create no user_portfolio records.
     */
    public function test_ignores_users_without_seller_assigned(): void
    {
        User::factory()->create([
            'seller_id' => null,
            'branch_id' => $this->branch->id,
        ]);

        $this->artisan('portfolios:sync')->assertSuccessful();

        $this->assertDatabaseCount('user_portfolio', 0);
    }

    // ---------------------------------------------------------------
    // T-SYNC-07
    // ---------------------------------------------------------------

    /**
     * Users marked as sellers (is_seller=true) are part of the sales team, not clients.
     * Even if they happen to have a seller_id, the command must not assign them to any portfolio.
     */
    public function test_ignores_users_marked_as_sellers(): void
    {
        $seller = $this->createSeller();
        $this->createPortfolio($seller);

        $this->artisan('portfolios:sync')->assertSuccessful();

        $this->assertDatabaseCount('user_portfolio', 0);
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

    private function createPortfolio(User $seller, PortfolioCategory $category = PortfolioCategory::VentaFresca): SellerPortfolio
    {
        return SellerPortfolio::create([
            'name' => 'Cartera '.$seller->nickname,
            'category' => $category,
            'seller_id' => $seller->id,
        ]);
    }

    private function createClient(User $seller): User
    {
        return User::factory()->create([
            'seller_id' => $seller->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    private function createActiveUserPortfolio(User $client, SellerPortfolio $portfolio): UserPortfolio
    {
        return UserPortfolio::create([
            'user_id' => $client->id,
            'portfolio_id' => $portfolio->id,
            'is_active' => true,
            'assigned_at' => now(),
        ]);
    }
}
