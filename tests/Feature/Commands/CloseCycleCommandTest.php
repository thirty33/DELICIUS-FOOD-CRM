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
 * The command processes all active user_portfolio records whose month_closed_at
 * has already passed and migrates those clients to the successor portfolio defined
 * in seller_portfolios.successor_portfolio_id. Traceability is preserved via
 * previous_portfolio_id.
 *
 * Covers: T-CYCLE-01 through T-CYCLE-06
 */
class CloseCycleCommandTest extends TestCase
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
    // T-CYCLE-01
    // ---------------------------------------------------------------

    /**
     * Happy path: expired month_closed_at with a successor portfolio configured.
     * The old record is closed and a fresh one is opened in the successor portfolio.
     * first_order_at and month_closed_at on the new record start as null (next observer fills them).
     */
    public function test_migrates_client_with_expired_month_closed_at_to_successor_portfolio(): void
    {
        Carbon::setTestNow('2026-01-20');

        [$portfolioA, $portfolioB] = $this->createPortfolioChain(2);
        $client = $this->createClient();
        $this->createActiveUserPortfolio($client, $portfolioA, monthClosedAt: Carbon::parse('2026-01-15'));

        $this->artisan('portfolios:close-cycle')->assertSuccessful();

        $this->assertDatabaseHas('user_portfolio', [
            'user_id' => $client->id,
            'portfolio_id' => $portfolioA->id,
            'is_active' => false,
        ]);

        $newRecord = UserPortfolio::where('user_id', $client->id)
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($newRecord);
        $this->assertEquals($portfolioB->id, $newRecord->portfolio_id);
        $this->assertEquals($portfolioA->id, $newRecord->previous_portfolio_id);
        $this->assertNull($newRecord->first_order_at);
        $this->assertNull($newRecord->month_closed_at);
        $this->assertDatabaseCount('user_portfolio', 2);
    }

    // ---------------------------------------------------------------
    // T-CYCLE-02
    // ---------------------------------------------------------------

    /**
     * month_closed_at is in the future: the command must leave the record untouched.
     */
    public function test_skips_client_when_month_closed_at_is_in_the_future(): void
    {
        Carbon::setTestNow('2026-01-20');

        [$portfolioA, $portfolioB] = $this->createPortfolioChain(2);
        $client = $this->createClient();
        $this->createActiveUserPortfolio($client, $portfolioA, monthClosedAt: Carbon::parse('2026-02-28'));

        $this->artisan('portfolios:close-cycle')->assertSuccessful();

        $this->assertDatabaseCount('user_portfolio', 1);
        $this->assertDatabaseHas('user_portfolio', [
            'user_id' => $client->id,
            'portfolio_id' => $portfolioA->id,
            'is_active' => true,
        ]);
    }

    // ---------------------------------------------------------------
    // T-CYCLE-03
    // ---------------------------------------------------------------

    /**
     * month_closed_at is expired but the portfolio has no successor configured.
     * The command must leave the record unchanged and not throw.
     */
    public function test_skips_client_when_active_portfolio_has_no_successor(): void
    {
        Carbon::setTestNow('2026-01-20');

        $seller = $this->createSeller();
        $portfolioWithoutSuccessor = $this->createPortfolio($seller);
        $client = $this->createClient();
        $this->createActiveUserPortfolio($client, $portfolioWithoutSuccessor, monthClosedAt: Carbon::parse('2026-01-10'));

        $this->artisan('portfolios:close-cycle')->assertSuccessful();

        $this->assertDatabaseCount('user_portfolio', 1);
        $this->assertDatabaseHas('user_portfolio', [
            'user_id' => $client->id,
            'portfolio_id' => $portfolioWithoutSuccessor->id,
            'is_active' => true,
        ]);
    }

    // ---------------------------------------------------------------
    // T-CYCLE-04
    // ---------------------------------------------------------------

    /**
     * Client was already migrated in a previous cycle: their active record points to the
     * successor portfolio with month_closed_at = null. Running the command twice must
     * produce no additional records.
     */
    public function test_is_idempotent_for_clients_already_in_successor_portfolio(): void
    {
        Carbon::setTestNow('2026-01-20');

        [$portfolioA, $portfolioB] = $this->createPortfolioChain(2);
        $client = $this->createClient();

        // Client already migrated: active record is in Portfolio B with no month_closed_at
        $this->createActiveUserPortfolio($client, $portfolioB, monthClosedAt: null);

        $this->artisan('portfolios:close-cycle')->assertSuccessful();
        $this->artisan('portfolios:close-cycle')->assertSuccessful();

        $this->assertDatabaseCount('user_portfolio', 1);
        $this->assertDatabaseHas('user_portfolio', [
            'user_id' => $client->id,
            'portfolio_id' => $portfolioB->id,
            'is_active' => true,
        ]);
    }

    // ---------------------------------------------------------------
    // T-CYCLE-05
    // ---------------------------------------------------------------

    /**
     * Two complete close-cycle executions produce a three-record traceability chain:
     * two inactive records pointing to their predecessor, one active at the end.
     */
    public function test_traceability_chain_reflects_two_consecutive_migrations(): void
    {
        [$portfolioA, $portfolioB, $portfolioC] = $this->createPortfolioChain(3);
        $client = $this->createClient();

        // First cycle: client in Portfolio A, month_closed_at expired
        Carbon::setTestNow('2026-01-10');
        $this->createActiveUserPortfolio($client, $portfolioA, monthClosedAt: Carbon::parse('2026-01-05'));

        Carbon::setTestNow('2026-01-15');
        $this->artisan('portfolios:close-cycle')->assertSuccessful();

        // Simulate observer setting month_closed_at on Portfolio B record
        UserPortfolio::where('user_id', $client->id)
            ->where('portfolio_id', $portfolioB->id)
            ->update(['month_closed_at' => '2026-01-31']);

        // Second cycle: Portfolio B month_closed_at now expired
        Carbon::setTestNow('2026-02-10');
        $this->artisan('portfolios:close-cycle')->assertSuccessful();

        $this->assertDatabaseCount('user_portfolio', 3);

        $records = UserPortfolio::where('user_id', $client->id)
            ->orderBy('assigned_at')
            ->get();

        $this->assertFalse($records[0]->is_active);
        $this->assertEquals($portfolioA->id, $records[0]->portfolio_id);

        $this->assertFalse($records[1]->is_active);
        $this->assertEquals($portfolioB->id, $records[1]->portfolio_id);
        $this->assertEquals($portfolioA->id, $records[1]->previous_portfolio_id);

        $this->assertTrue($records[2]->is_active);
        $this->assertEquals($portfolioC->id, $records[2]->portfolio_id);
        $this->assertEquals($portfolioB->id, $records[2]->previous_portfolio_id);
    }

    // ---------------------------------------------------------------
    // T-CYCLE-06
    // ---------------------------------------------------------------

    /**
     * Edge case: month_closed_at is far in the past because first_order_at was
     * backfilled by portfolios:sync from historical orders that predate the portfolio.
     * The command must treat any past date the same way — no special age threshold.
     */
    public function test_migrates_client_when_month_closed_at_is_very_old_from_backfill(): void
    {
        Carbon::setTestNow('2026-01-20');

        [$portfolioA, $portfolioB] = $this->createPortfolioChain(2);
        $client = $this->createClient();

        // month_closed_at backfilled to almost 2 years ago
        $this->createActiveUserPortfolio($client, $portfolioA, monthClosedAt: Carbon::parse('2024-04-30'));

        $this->artisan('portfolios:close-cycle')->assertSuccessful();

        $this->assertDatabaseHas('user_portfolio', [
            'user_id' => $client->id,
            'portfolio_id' => $portfolioA->id,
            'is_active' => false,
        ]);

        $newRecord = UserPortfolio::where('user_id', $client->id)
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($newRecord);
        $this->assertEquals($portfolioB->id, $newRecord->portfolio_id);
        $this->assertEquals($portfolioA->id, $newRecord->previous_portfolio_id);
        $this->assertNull($newRecord->first_order_at);
        $this->assertNull($newRecord->month_closed_at);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Creates a chain of N portfolios where each portfolio's successor points to the next.
     * Returns the portfolios in order (first → last).
     *
     * @return SellerPortfolio[]
     */
    private function createPortfolioChain(int $length): array
    {
        $portfolios = [];

        for ($i = 0; $i < $length; $i++) {
            $seller = $this->createSeller("SELLER-{$i}");
            $portfolios[] = $this->createPortfolio($seller);
        }

        for ($i = 0; $i < $length - 1; $i++) {
            $portfolios[$i]->update(['successor_portfolio_id' => $portfolios[$i + 1]->id]);
        }

        return $portfolios;
    }

    private function createSeller(string $nickname = 'SELLER'): User
    {
        return User::factory()->create([
            'nickname' => $nickname,
            'is_seller' => true,
        ]);
    }

    private function createPortfolio(User $seller): SellerPortfolio
    {
        return SellerPortfolio::create([
            'name' => 'Cartera '.$seller->nickname,
            'category' => PortfolioCategory::VentaFresca,
            'seller_id' => $seller->id,
        ]);
    }

    private function createClient(): User
    {
        return User::factory()->create([
            'branch_id' => $this->branch->id,
        ]);
    }

    private function createActiveUserPortfolio(
        User $client,
        SellerPortfolio $portfolio,
        ?Carbon $monthClosedAt,
    ): UserPortfolio {
        return UserPortfolio::create([
            'user_id' => $client->id,
            'portfolio_id' => $portfolio->id,
            'is_active' => true,
            'assigned_at' => now(),
            'month_closed_at' => $monthClosedAt,
        ]);
    }
}
