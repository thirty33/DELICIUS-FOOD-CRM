<?php

namespace Tests\Feature\Chat;

use App\Models\Branch;
use App\Models\Company;
use App\Models\PriceList;
use App\Repositories\PhoneNumberRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhoneNumberRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private PhoneNumberRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(PhoneNumberRepository::class);
    }

    public function test_resolves_branch_owner_with_spaces_in_phone(): void
    {
        $priceList = PriceList::create(['name' => 'Test PL']);

        $company = Company::factory()->create([
            'price_list_id' => $priceList->id,
        ]);

        $branch = Branch::factory()->create([
            'company_id' => $company->id,
            'contact_phone_number' => '569 1234 5678',
        ]);

        $result = $this->repository->resolveOwner('56912345678');

        $this->assertNotNull($result);
        $this->assertEquals('branch', $result['source_type']);
        $this->assertEquals($branch->id, $result['branch_id']);
        $this->assertEquals($company->id, $result['company_id']);
    }

    public function test_resolves_company_owner_with_plus_and_spaces(): void
    {
        $priceList = PriceList::create(['name' => 'Test PL']);

        $company = Company::factory()->create([
            'phone_number' => '+56 9 8765 4321',
            'price_list_id' => $priceList->id,
        ]);

        $result = $this->repository->resolveOwner('56987654321');

        $this->assertNotNull($result);
        $this->assertEquals('company', $result['source_type']);
        $this->assertEquals($company->id, $result['company_id']);
        $this->assertNull($result['branch_id']);
    }

    public function test_returns_null_for_unknown_phone(): void
    {
        $result = $this->repository->resolveOwner('9999999999');

        $this->assertNull($result);
    }

    public function test_branch_takes_priority_over_company(): void
    {
        $priceList = PriceList::create(['name' => 'Test PL']);

        $company = Company::factory()->create([
            'phone_number' => '56912345678',
            'price_list_id' => $priceList->id,
        ]);

        $branch = Branch::factory()->create([
            'company_id' => $company->id,
            'contact_phone_number' => '56912345678',
        ]);

        $result = $this->repository->resolveOwner('56912345678');

        $this->assertNotNull($result);
        $this->assertEquals('branch', $result['source_type']);
        $this->assertEquals($branch->id, $result['branch_id']);
    }

    public function test_ignores_sin_informacion_values(): void
    {
        $priceList = PriceList::create(['name' => 'Test PL']);

        Company::factory()->create([
            'phone_number' => 'SIN INFORMACION',
            'price_list_id' => $priceList->id,
        ]);

        $result = $this->repository->resolveOwner('SIN INFORMACION');

        // Normalized "SIN INFORMACION" becomes "" (empty after stripping non-digits)
        // which should not match any real phone number
        $this->assertNull($result);
    }
}
