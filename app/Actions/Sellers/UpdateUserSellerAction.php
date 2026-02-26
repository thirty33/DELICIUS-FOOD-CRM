<?php

namespace App\Actions\Sellers;

use App\Actions\Contracts\UpdateAction;
use App\Models\User;

final class UpdateUserSellerAction implements UpdateAction
{
    public static function execute(array $data = []): User
    {
        $user = User::findOrFail(data_get($data, 'user_id'));

        $user->update([
            'seller_id' => data_get($data, 'seller_id'),
        ]);

        return $user;
    }
}
