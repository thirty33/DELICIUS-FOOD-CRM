<?php

namespace App\Actions\Contracts;

use Illuminate\Database\Eloquent\Model;

interface CreateAction extends Action
{
    public static function execute(array $data = []): Model;
}
