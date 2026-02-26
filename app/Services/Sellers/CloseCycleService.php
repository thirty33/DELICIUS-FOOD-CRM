<?php

namespace App\Services\Sellers;

use App\Actions\Sellers\CloseUserPortfolioAction;
use App\Actions\Sellers\CreateUserPortfolioAction;
use App\Actions\Sellers\UpdateUserSellerAction;
use App\Models\UserPortfolio;
use App\Repositories\UserPortfolioRepository;

class CloseCycleService
{
    public function __construct(
        private UserPortfolioRepository $userPortfolioRepository,
    ) {}

    public function run(): void
    {
        $expiredRecords = $this->userPortfolioRepository->getActiveWithExpiredCycle();

        foreach ($expiredRecords as $record) {
            $this->processRecord($record);
        }
    }

    private function processRecord(UserPortfolio $record): void
    {
        $successor = $record->portfolio->successorPortfolio;

        if ($successor === null) {
            return;
        }

        $previousPortfolioId = $record->portfolio_id;

        CloseUserPortfolioAction::execute([
            'user_portfolio_id' => $record->id,
        ]);

        CreateUserPortfolioAction::execute([
            'user_id' => $record->user_id,
            'portfolio_id' => $successor->id,
            'previous_portfolio_id' => $previousPortfolioId,
        ]);

        UpdateUserSellerAction::execute([
            'user_id' => $record->user_id,
            'seller_id' => $successor->seller_id,
        ]);
    }
}
