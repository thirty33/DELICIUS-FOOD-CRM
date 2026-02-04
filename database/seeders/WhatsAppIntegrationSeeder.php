<?php

namespace Database\Seeders;

use App\Enums\IntegrationName;
use App\Enums\IntegrationType;
use App\Models\Integration;
use Illuminate\Database\Seeder;

class WhatsAppIntegrationSeeder extends Seeder
{
    public function run(): void
    {
        Integration::updateOrCreate(
            ['name' => IntegrationName::WHATSAPP->value],
            [
                'url' => 'https://graph.facebook.com/v20.0',
                'url_test' => 'https://graph.facebook.com/v20.0',
                'type' => IntegrationType::MESSAGING->value,
                'production' => false,
                'active' => true,
            ]
        );
    }
}