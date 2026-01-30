<?php

namespace App\Actions\Contracts;

use Illuminate\Database\Eloquent\Model;

interface UpdateAction extends Action
{
    public static function execute(array $data = []): Model;
}