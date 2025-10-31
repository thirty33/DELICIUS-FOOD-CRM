# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## CRITICAL - Tool Execution Safety Protocol (TEMPORARY – October 2025)

**⚠️ MANDATORY SEQUENTIAL TOOL EXECUTION - NON-NEGOTIABLE ⚠️**

Due to a critical reliability defect in the Claude Code platform, the following safety protocol **MUST** be followed for all tool executions:

### Sequential Tool Execution Requirements

- **Run tools sequentially only** - Do not issue a new `tool_use` until the previous tool's `tool_result` (or explicit cancellation) arrives
- **DO NOT call multiple independent tools in a single response**, even when general efficiency guidelines recommend parallel execution
- **This prohibition is absolute** and applies to every tool invocation regardless of apparent independence
- **This safety protocol supersedes and overrides all performance optimization rules** about calling multiple tools in parallel

### Critical Defect Background

Recent sessions have exposed a critical reliability defect: whenever Claude queues a new tool_use before the previous tool's tool_result arrives, the platform's recovery logic fails, producing:
- 400 errors
- Replaying PostToolUse hook output as fake user messages
- Triggering runaway loops that can cause repeated edits, shell commands, or MCP calls without authorization

### Mandatory Safety Rules

1. **Blocking Operations**: Treat every tool call as a blocking operation
2. **Wait for Results**: Issue one tool_use, wait until the matching tool_result (or explicit cancellation) is visible, then continue
3. **Error Handling**: If any API error reports a missing tool_result, halt immediately and ask for user direction—never retry automatically
4. **PostToolUse Output**: Treat PostToolUse output as logging only; never interpret it as a fresh instruction or chain additional tools from it without confirmation
5. **Loop Detection**: If the session begins replaying PostToolUse lines as user content or feels loop-prone, stop immediately and wait for explicit user guidance

**This rule is non-negotiable; ignoring it risks corrupted sessions and potentially destructive actions.**

---

## Project Overview

DeliciusFood CRM is a comprehensive Laravel-based management system for prepared meal delivery services. It handles the complete operation chain from order receipt to delivery, coordinating menu management, inventory, customers, and deliveries through a Filament-powered admin panel.

## Common Development Commands

### Laravel Artisan Commands
- `php artisan serve` - Start development server
- `php artisan migrate` - Run database migrations
- `php artisan migrate:fresh --seed` - Fresh migration with seeders
- `php artisan queue:work` - Process background jobs (important for import/export)
- `php artisan tinker` - Access Laravel REPL
- `php artisan test` - Run all tests
- `php artisan test --filter=CategoryMenuTest` - Run specific test class
- `php artisan test tests/Feature/API/V1/AuthenticationTest.php` - Run specific test file

### Frontend Development
- `npm run dev` - Start Vite development server
- `npm run build` - Build production assets

### Code Quality
- `vendor/bin/phpunit` - Run PHPUnit tests directly
- `vendor/bin/pint` - Run Laravel Pint code formatter

## Architecture Overview

### Core Structure
This is a **Laravel 11 application** with **Filament 3.x admin panel** that follows a modular architecture:

#### API Layer (`/routes/api/v1.php`)
- RESTful API with `/api/v1/` prefix
- Laravel Sanctum authentication
- Rate limiting per endpoint (10-60 req/min)
- Structured controllers in `App\Http\Controllers\API\V1\`

#### Admin Panel (Filament)
- Complete CRUD operations via Filament Resources
- Located in `app/Filament/Resources/`
- Each resource has Pages (Create/Edit/List) and RelationManagers
- Custom widgets and bulk actions

#### Queue System
- **Critical**: Uses queue system for import/export operations
- Compatible with Amazon SQS
- Jobs located in `app/Jobs/`
- Always run `php artisan queue:work` during development

### Key Models and Relationships

#### Core Business Models
- **Menu** → hasMany Categories (via CategoryMenu pivot)
- **Category** → hasMany Products, hasMany CategoryLines
- **Order** → hasMany OrderLines → belongsTo Product
- **Company** → hasMany Branches, hasMany Users
- **PriceList** → hasMany PriceListLines → belongsTo Product

#### Authentication & Authorization
- **User** → belongsToMany Roles → hasMany Permissions
- Role-based access control with custom policies
- Sanctum API tokens for API authentication

### Import/Export System
- **Background Processing**: All import/export operations run in queues
- **File Templates**: Download/upload Excel templates for each module
- **Error Handling**: Detailed error logs for failed imports
- **Audit Trail**: Complete history of all import/export processes

### Key Services and Classes

#### Image Management
- `ImageSignerService` - CloudFront signed URLs for product images
- `ProductImageSignerDecorator` - Decorates products with signed image URLs

#### API Response Handling
- `ApiResponseService` - Standardized API responses
- `AuthSanctumService` - Authentication service

#### Validation System
- Order validation rules in `app/Classes/Orders/Validations/`
- Custom validation for business rules (max amounts, mandatory categories, etc.)

## Testing Structure

### Test Organization
- **Feature Tests**: `tests/Feature/API/V1/` - API endpoint testing
- **Unit Tests**: `tests/Unit/` - Individual class testing
- **Test Helpers**: `tests/Helpers/` - Shared test utilities

### Database Testing
- Uses `testing` database environment
- Seeders available for consistent test data
- Factory classes for model generation

## Development Workflow

### API Development
1. **Routes**: Add to `/routes/api/v1.php` with proper middleware
2. **Controllers**: Create in `App\Http\Controllers\API\V1\`
3. **Requests**: Add validation in `App\Http\Requests\API\V1\`
4. **Resources**: Create API resources in `App\Http\Resources\API\V1\`
5. **Tests**: Add feature tests in `tests/Feature/API/V1/`

### Admin Panel Development
1. **Resources**: Create Filament resources in `app/Filament/Resources/`
2. **Pages**: Customize CRUD pages as needed
3. **Policies**: Implement authorization in `app/Policies/`
4. **Bulk Actions**: Add custom bulk operations

### Queue Job Development
1. **Jobs**: Create in `app/Jobs/`
2. **Testing**: Always test with `queue:work` running
3. **Error Handling**: Implement proper failure handling
4. **SQS**: Consider SQS compatibility for production

## Environment Considerations

### Required Services
- **Database**: MySQL/PostgreSQL
- **Queue**: Redis/SQS recommended for production
- **Storage**: AWS S3 for file storage
- **Email**: Resend service for notifications

### Key Configuration Files
- `config/cloudfront-url-signer.php` - Image signing configuration
- `config/sanctum.php` - API authentication
- `config/queue.php` - Background job processing
- `config/filament.php` - Admin panel settings

## Special Notes

### Order Management
- Orders have complex validation rules based on business logic
- Status changes trigger email notifications
- Consolidated reporting for corporate clients

### Menu System
- Menus are date-specific with ordering deadlines
- Categories within menus have display order and availability rules
- Price lists can be company-specific

### Security
- API endpoints use role-based authorization
- Admin panel has comprehensive permission system
- Image URLs are signed for security

## Development Guidelines

### General Rules
- Explain what you're going to do before providing code
- Do not create code comments in Spanish (use English)
- **ALWAYS** run ALL PHP and related commands using `./vendor/bin/sail` (Laravel Sail)
  - Use `./vendor/bin/sail artisan` instead of `php artisan`
  - Use `./vendor/bin/sail composer` instead of `composer`
  - Use `./vendor/bin/sail npm` instead of `npm`
  - Use `./vendor/bin/sail mysql` instead of `mysql`
  - Use `./vendor/bin/sail tinker` instead of `php artisan tinker`
  - This is MANDATORY even if Sail is not currently running
- Do not assign default text values to variables unless explicitly instructed

### File Management Rules
- **NEVER** save Python scripts (.py) in the project directory
- **NEVER** save generated Excel files (.xlsx) in the project directory
- **ALWAYS** save Python scripts and generated Excel files directly to `/mnt/c/Users/Usuario/Downloads/`
- This applies to all test data generation, import templates, and similar files

### Python Scripts and Excel Reports Directory
- **Working Directory for Python Scripts**: `/mnt/c/Users/USUARIO/Documents/food-shop-python-scripts`
- Use this directory for:
  - Python scripts related to data analysis and reporting
  - Reading existing Excel reports from the system
  - Creating new Excel reports and analysis files
  - Data processing scripts for the Food Shop CRM
- For temporary files or quick tests, continue using `/mnt/c/Users/Usuario/Downloads/`
- For production-ready scripts and reports, use the `food-shop-python-scripts` directory

---

## Creating Production Bug Replica Tests

When production bugs are reported, follow this systematic process to create a test that replicates the EXACT production scenario before fixing the bug.

### Step 1: Gather Production Data Using Tinker

**ALWAYS use `./vendor/bin/sail tinker` to investigate production data BEFORE creating the test.**

#### 1.1 Identify the User
```bash
./vendor/bin/sail tinker --execute="
\$user = App\Models\User::where('nickname', 'USER.NICKNAME')->first();
echo \"User ID: \" . \$user->id . \"\n\";
echo \"Company ID: \" . \$user->company_id . \"\n\";
echo \"Company: \" . \$user->company->name . \"\n\";
echo \"Role: \" . \$user->roles->first()->name . \" (ID: \" . \$user->roles->first()->id . \")\n\";
echo \"Permission: \" . \$user->permissions->first()->name . \" (ID: \" . \$user->permissions->first()->id . \")\n\";
echo \"validate_subcategory_rules: \" . (\$user->validate_subcategory_rules ? 'true' : 'false') . \"\n\";
"
```

#### 1.2 Analyze the Order
```bash
./vendor/bin/sail tinker --execute="
\$order = App\Models\Order::find(ORDER_ID);
echo \"Order ID: \" . \$order->id . \"\n\";
echo \"User: \" . \$order->user->nickname . \"\n\";
echo \"Date: \" . \$order->date . \"\n\";
echo \"Status: \" . \$order->status . \"\n\n\";

echo \"Order Lines:\n\";
foreach (\$order->orderLines as \$line) {
    \$subcats = \$line->product->category->subcategories->pluck('name')->toArray();
    echo \"  - Product ID: \" . \$line->product->id . \"\n\";
    echo \"    Name: \" . \$line->product->name . \"\n\";
    echo \"    Category: \" . \$line->product->category->name . \"\n\";
    echo \"    Subcategories: [\" . implode(', ', \$subcats) . \"]\n\";
}
"
```

#### 1.3 Check Products and Categories
```bash
./vendor/bin/sail tinker --execute="
\$product = App\Models\Product::find(PRODUCT_ID);
echo \"Product ID: \" . \$product->id . \"\n\";
echo \"Name: \" . \$product->name . \"\n\";
echo \"Category: \" . \$product->category->name . \" (ID: \" . \$product->category->id . \")\n\";
\$subcats = \$product->category->subcategories->pluck('name')->toArray();
echo \"Subcategories: [\" . implode(', ', \$subcats) . \"]\n\";
"
```

#### 1.4 Verify Active Rules (for validation issues)
```bash
./vendor/bin/sail tinker --execute="
\$rules = App\Models\OrderRule::where('is_active', true)
    ->where('rule_type', 'subcategory_exclusion')
    ->get();

echo \"Active Rules:\n\";
foreach (\$rules as \$rule) {
    echo \"  Rule ID \" . \$rule->id . \": \" . \$rule->name . \" (Priority: \" . \$rule->priority . \")\n\";
    echo \"    Role: \" . \$rule->role->name . \" (\" . \$rule->role_id . \")\n\";
    echo \"    Permission: \" . \$rule->permission->name . \" (\" . \$rule->permission_id . \")\n\";
    echo \"    Companies: \" . \$rule->companies->count() . \"\n\";

    if (\$rule->companies->count() > 0) {
        echo \"      - \" . \$rule->companies->pluck('name')->join(', ') . \"\n\";
    }

    echo \"    Exclusions:\n\";
    foreach (\$rule->subcategoryExclusions as \$ex) {
        echo \"      - \" . \$ex->subcategory->name . \" => \" . \$ex->excludedSubcategory->name . \"\n\";
    }
    echo \"\n\";
}
"
```

#### 1.5 Test Repository Logic (if applicable)
```bash
./vendor/bin/sail tinker --execute="
\$user = App\Models\User::where('nickname', 'USER.NICKNAME')->first();
\$repo = new App\Repositories\OrderRuleRepository();

\$orderRule = \$repo->getOrderRuleForUser(\$user, 'subcategory_exclusion');

if (\$orderRule) {
    echo \"Selected Rule for User:\n\";
    echo \"  ID: \" . \$orderRule->id . \"\n\";
    echo \"  Name: \" . \$orderRule->name . \"\n\";
    echo \"  Priority: \" . \$orderRule->priority . \"\n\";
    echo \"  Companies: \" . \$orderRule->companies->count() . \"\n\n\";

    echo \"  Exclusions:\n\";
    foreach (\$orderRule->subcategoryExclusions as \$ex) {
        echo \"    - \" . \$ex->subcategory->name . \" => \" . \$ex->excludedSubcategory->name . \"\n\";
    }
}
"
```

### Step 2: Review Existing Test Classes

**ALWAYS** review similar existing test files to understand the project's testing patterns:

```bash
# Find similar tests
ls -la tests/Feature/API/V1/Agreement/Individual/

# Read base test class
cat tests/BaseIndividualAgreementTest.php

# Read a similar test for reference
cat tests/Feature/API/V1/Agreement/Individual/CompanySpecificRulesTest.php
```

**Key patterns to observe:**
- How users are created and authenticated (`Sanctum::actingAs()`)
- How roles and permissions are retrieved (`Role::where()->first()`)
- How test data is structured (Company, Branch, PriceList, etc.)
- How API requests are made (`$this->postJson()`)
- Assertion patterns

### Step 3: Create the Production Replica Test

#### 3.1 Test File Naming Convention
- Place in appropriate directory: `tests/Feature/API/V1/[Module]/`
- Name descriptively: `ProductionBug[Description]ReplicaTest.php`
- Example: `ProductionBugUniconMultipleEntradasReplicaTest.php`

#### 3.2 Test Structure Template

```php
<?php

namespace Tests\Feature\API\V1\[Module];

use App\Models\[RequiredModels];
use App\Enums\[RequiredEnums];
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\[BaseTestClass];

/**
 * Production Bug Replica Test - [Bug Description]
 *
 * PRODUCTION DATA:
 * - User: [USERNAME] (Company: [COMPANY_NAME])
 * - [Other relevant production info]
 *
 * SCENARIO:
 * [Describe the exact production scenario step by step]
 *
 * EXPECTED:
 * [What should happen]
 *
 * ACTUAL BUG:
 * [What actually happens - the error]
 *
 * API ENDPOINT:
 * [The failing endpoint]
 */
class ProductionBug[Description]ReplicaTest extends [BaseTestClass]
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('[PRODUCTION_DATE] 00:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_[descriptive_name_of_bug](): void
    {
        // 1. GET ROLES AND PERMISSIONS (from parent setUp)
        $role = Role::where('name', RoleName::VALUE)->first();
        $permission = Permission::where('name', PermissionName::VALUE)->first();

        // 2. CREATE COMPANY-SPECIFIC DATA
        $priceList = PriceList::create([...]);
        $company = Company::create([...]);
        $branch = Branch::create([...]);

        // 3. CREATE RULES (if applicable)
        $rule = OrderRule::create([...]);
        $rule->companies()->attach($company->id);
        $this->createSubcategoryExclusions($rule, [...]);

        // 4. CREATE USER
        $user = User::create([...]);
        $user->roles()->attach($role->id);
        $user->permissions()->attach($permission->id);

        // 5. CREATE CATEGORIES, PRODUCTS, MENUS
        $category = Category::create([...]);
        $category->subcategories()->attach([...]);

        $product = Product::create([
            'name' => '...',
            'description' => '...', // ALWAYS include description!
            'code' => '...',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([...]);

        $menu = Menu::create([...]);
        $categoryMenu = CategoryMenu::create([...]);
        $categoryMenu->products()->attach($product->id);

        // 6. AUTHENTICATE USER
        Sanctum::actingAs($user);

        // 7. REPLICATE THE EXACT API CALLS FROM PRODUCTION
        // Step 1: Initial request (if applicable)
        $response = $this->postJson("/api/v1/endpoint", [...]);
        $response->assertStatus(200);

        // Step 2: The failing request
        $response = $this->postJson("/api/v1/endpoint", [...]);

        // 8. ASSERTIONS - EXPECT 200, BUT IT WILL FAIL WITH PRODUCTION ERROR
        $response->assertStatus(200); // This will fail, showing the bug

        // Additional assertions to verify expected behavior
        $order = $user->orders()->where('date', $date)->first();
        $this->assertNotNull($order);
        $this->assertEquals(EXPECTED_COUNT, $order->orderLines->count());
    }
}
```

#### 3.3 Critical Requirements for Production Replica Tests

**MUST-HAVE ELEMENTS:**
1. **Comprehensive Documentation**: Include detailed comments explaining:
   - Production data (user, company, order IDs)
   - Expected vs actual behavior
   - API endpoint and request payload
   - Root cause of the bug

2. **Exact Data Replication**: Match production data:
   - Same subcategories and category relationships
   - Same product structure
   - Same rules and priorities
   - Same user configuration

3. **Product Requirements**:
   - ALWAYS include `description` field (required in DB)
   - Include all required fields: `measure_unit`, `weight`, `allow_sales_without_stock`

4. **Date Management**:
   - Use `Carbon::setTestNow()` in `setUp()`
   - Reset with `Carbon::setTestNow()` in `tearDown()`

5. **Authentication**:
   - Use `Sanctum::actingAs($user)` before API calls
   - Attach roles and permissions to user

6. **Assertions**:
   - Test should assert the EXPECTED behavior (200 OK)
   - Let it fail with the production error
   - This documents what SHOULD happen vs what DOES happen

### Step 4: Run the Test to Verify Bug Replication

```bash
./vendor/bin/sail artisan test --filter=[TestClassName]
```

**Expected outcome**: Test FAILS with the EXACT same error as production.

**Example of successful bug replication:**
```
FAIL  Tests\Feature\API\V1\Agreement\Individual\ProductionBugReplicaTest
Expected response status code [200] but received 422.
{
    "message": "error",
    "errors": {
        "message": ["Solo puedes elegir un ENTRADA por pedido.\n\n"]
    }
}
```

### Step 5: Document Test Location and Purpose

Add to pull request or issue:
```
**Bug Replica Test**: tests/Feature/API/V1/[Module]/ProductionBug[Description]ReplicaTest.php

This test replicates the exact production scenario:
- User: [USERNAME]
- Company: [COMPANY]
- Issue: [DESCRIPTION]
- Test Status: ❌ FAILING (as expected - replicates bug)

Once fixed, this test should pass (✅ 200 OK).
```

### Common Pitfalls to Avoid

1. **Missing Production Data**:
   - ❌ Creating generic test data
   - ✅ Using EXACT production data structure

2. **Incomplete Investigation**:
   - ❌ Jumping straight to coding
   - ✅ Using tinker to understand ALL aspects

3. **Wrong Assertions**:
   - ❌ `$response->assertStatus(422)` (accepting the bug)
   - ✅ `$response->assertStatus(200)` (expecting correct behavior)

4. **Missing Required Fields**:
   - ❌ Skipping `description` in Product creation
   - ✅ Including ALL required DB fields

5. **Ignoring Existing Patterns**:
   - ❌ Creating tests from scratch without reviewing existing ones
   - ✅ Following established patterns in similar tests

### Test Lifecycle

1. **Initial State**: Test FAILS ❌ (replicates production bug)
2. **After Fix**: Test PASSES ✅ (bug is resolved)
3. **Regression Prevention**: Test continues to run, preventing bug reintroduction

### Example: Real Production Bug Test

See: `tests/Feature/API/V1/Agreement/Individual/ProductionBugReplicaTest.php`

This test demonstrates:
- Complete production data replication
- Tinker investigation results in comments
- Exact API call sequence
- Expected behavior assertions
- Comprehensive documentation

**Key Learning**: This test found that `OneProductPerSubcategory` validation was ignoring database-driven rules and using hardcoded validation logic.

---

## Creating Production Replica Tests for Consolidated Agreements

For Consolidated Agreement tests, follow the same process but with these specific considerations:

### Additional Tinker Investigations for Consolidated

#### Analyze Menu Details
```bash
./vendor/bin/sail artisan tinker --execute="
\ = App\Models\Menu::find(MENU_ID);
echo \"=== MENU ===\\n\";
echo \"Menu ID: \" . \->id . \"\\n\";
echo \"Title: \" . \->title . \"\\n\";
echo \"Publication Date: \" . \->publication_date . \"\\n\";
echo \"Role: \" . (\->role ? \->role->name : 'N/A') . \"\\n\";
echo \"Permission: \" . (\->permission ? \->permission->name : 'N/A') . \"\\n\";
echo \"Active: \" . (\->active ? 'Yes' : 'No') . \"\\n\";
echo \"Category Menus: \" . \->categoryMenus->count() . \"\\n\";
"
```

#### Get Company Details
```bash
./vendor/bin/sail artisan tinker --execute="
\ = App\Models\Company::find(COMPANY_ID);
echo \"=== COMPANY ===\\n\";
echo \"Company ID: \" . \->id . \"\\n\";
echo \"Name: \" . \->name . \"\\n\";
echo \"Tax ID: \" . \->tax_id . \"\\n\";
echo \"Company Code: \" . \->company_code . \"\\n\";
echo \"Fantasy Name: \" . \->fantasy_name . \"\\n\";
echo \"Price List ID: \" . \->price_list_id . \"\\n\";
"
```

### Data Anonymization Rules

**CRITICAL**: Never use real production data in tests. Always anonymize:

❌ **WRONG**:
```php
$user = User::create([
    'nickname' => 'EXACTO.FRIA',  // Real company name
    'email' => 'contacto@exacto.cl',  // Real email
]);
```

✅ **CORRECT**:
```php
$user = User::create([
    'nickname' => 'TEST.CONSOLIDATED.USER',  // Generic name
    'email' => 'test.consolidated@test.com',  // Test email
]);
```

**Anonymization Guidelines**:
1. **User Names**: Use TEST.CONSOLIDATED.USER, TEST.AGREEMENT.USER, etc.
2. **Company Names**: Use TEST CONSOLIDATED COMPANY S.A., TEST COMPANY INC
3. **Tax IDs**: Use fake IDs like 12.345.678-9
4. **Emails**: Use @test.com domain
5. **Product Names**: Keep generic descriptions (Sandwich type A, Juice boxes, etc.)

**Document Real IDs in Comments**:
```php
/**
 * PRODUCTION DATA (anonymized):
 * - User: TEST.CONSOLIDATED.USER (production ID: 380)
 * - Company: TEST CONSOLIDATED COMPANY (production ID: 594)
 * - Order: production order ID 109
 */
```

### Test Location for Consolidated

Place consolidated agreement tests in:
```
tests/Feature/API/V1/Agreement/Consolidated/
```

Examples:
- `OrderUpdateStatusProductionReplicaTest.php` - Order status updates
- `CategoryMenuWithShowAllProductsTest.php` - Category menu behavior
- `MissingCategoryProductValidationTest.php` - Product validation

### Example: Consolidated Order Update Status Test

See: `tests/Feature/API/V1/Agreement/Consolidated/OrderUpdateStatusProductionReplicaTest.php`

This test demonstrates anonymized production data replication for order status updates with consolidated agreement validation.

---

## MANDATORY: Model and Migration Review Before Creating Tests

### ⚠️ CRITICAL PROTOCOL - MUST FOLLOW FOR EVERY TEST ⚠️

**BEFORE writing ANY test that creates model instances, you MUST:**

1. **Read CLAUDE.md FIRST** - Review testing guidelines and data anonymization rules
2. **Review ALL model migrations** - Understand required fields and constraints
3. **Review ALL model classes** - Understand fillable fields, relationships, and casts
4. **Verify table relationships** - Check pivot tables and foreign keys
5. **Test with tinker** - Validate queries work before writing test code

### Required Tables to Review

For EVERY model you use in a test, review:

1. **Primary Migration** (`create_{table}_table.php`) - Base schema
2. **Update Migrations** (`update_{table}_table.php`) - Added columns
3. **Model Class** (`app/Models/{Model}.php`) - Fillable, casts, relationships
4. **Pivot Tables** (if applicable) - Junction table structure

### Example Checklist for Order Tests

When creating a test involving Orders:

- [ ] Read `database/migrations/*create_orders_table.php`
- [ ] Read `database/migrations/*update_orders_table.php` (all updates)
- [ ] Read `app/Models/Order.php` - Check fillable, appends, relationships
- [ ] Read `database/migrations/*create_order_lines_table.php`
- [ ] Read `app/Models/OrderLine.php`
- [ ] Read `database/migrations/*create_dispatch_rules_table.php`
- [ ] Read `database/migrations/*create_dispatch_rule_ranges_table.php`
- [ ] Read `database/migrations/*create_dispatch_rule_companies_table.php` (pivot)
- [ ] Read `database/migrations/*create_dispatch_rule_branches_table.php` (pivot)
- [ ] Read `app/Models/DispatchRule.php` - Verify relationship method names
- [ ] Read `app/Models/DispatchRuleRange.php`
- [ ] Test query in tinker: `DispatchRule::whereHas('companies', fn($q) => $q->where('companies.id', 1))->first()`

### Common Mistakes to Avoid

#### Mistake 1: Using Non-Existent Fields

❌ **WRONG**:
```php
Branch::create([
    'name' => 'Test Branch',  // Field doesn't exist!
    'company_id' => $company->id,
]);
```

✅ **CORRECT**:
```bash
# First check migration
cat database/migrations/*create_branches_table.php

# Then check model
cat app/Models/Branch.php
```

```php
Branch::create([
    'company_id' => $company->id,  // Only use fields that exist
    'address' => 'Test Address',
    'min_price_order' => 0,
]);
```

#### Mistake 2: Wrong Column Names in Relationships

❌ **WRONG**:
```php
Menu::create([
    'permission_id' => $permission->id,  // Column is 'permissions_id'!
]);
```

✅ **CORRECT**:
```bash
# Check migration first
cat database/migrations/*update_menus_table.php
# Shows: $table->unsignedBigInteger('permissions_id')
```

```php
Menu::create([
    'permissions_id' => $permission->id,  // Use exact column name
]);
```

#### Mistake 3: Missing Required Fields

❌ **WRONG**:
```php
CategoryLine::create([
    'category_id' => $category->id,
    'weekday' => 'monday',
    // Missing required fields!
]);
```

✅ **CORRECT**:
```bash
# Check migration
cat database/migrations/*create_category_lines_table.php
# Shows: preparation_days, maximum_order_time are required
```

```php
CategoryLine::create([
    'category_id' => $category->id,
    'weekday' => 'monday',
    'preparation_days' => 1,        // Required
    'maximum_order_time' => '15:00:00',  // Required
    'active' => true,
]);
```

#### Mistake 4: Wrong Relationship Queries

❌ **WRONG**:
```php
DispatchRule::whereHas('companies', function($q) use ($companyId) {
    $q->where('company_id', $companyId);  // Wrong table context!
});
```

✅ **CORRECT**:
```bash
# Check pivot table migration
cat database/migrations/*create_dispatch_rule_companies_table.php
# Shows: dispatch_rule_id, company_id

# Check model relationship
cat app/Models/DispatchRule.php
# Shows: belongsToMany(Company::class, 'dispatch_rule_companies')
```

```php
DispatchRule::whereHas('companies', function($q) use ($companyId) {
    $q->where('companies.id', $companyId);  // Reference parent table
});
```

### Investigation Workflow

**Step 1: Use Tinker to Understand Production Data**
```bash
./vendor/bin/sail tinker --execute="
\$order = App\Models\Order::find(193);
echo \"Order Lines: \" . \$order->orderLines->count() . \"\\n\";
echo \"Dispatch Cost: \" . \$order->dispatch_cost . \"\\n\";
echo \"User Company: \" . \$order->user->company->name . \"\\n\";
"
```

**Step 2: Find Related Tables**
```bash
# Find all migrations for a model
ls -la database/migrations/ | grep dispatch

# Read them in chronological order
cat database/migrations/2025_08_27_132308_create_dispatch_rules_table.php
cat database/migrations/2025_08_27_132315_create_dispatch_rule_companies_table.php
cat database/migrations/2025_08_27_132326_create_dispatch_rule_ranges_table.php
```

**Step 3: Verify Model Relationships**
```bash
cat app/Models/DispatchRule.php | grep -A 3 "function companies"
# Output: public function companies(): BelongsToMany
#         {
#             return $this->belongsToMany(Company::class, 'dispatch_rule_companies')
```

**Step 4: Test Query Before Using in Test**
```bash
./vendor/bin/sail tinker --execute="
\$rule = App\Models\DispatchRule::whereHas('companies', function(\$q) {
    \$q->where('companies.id', 489);
})->first();
echo \"Found rule: \" . \$rule->name . \"\\n\";
"
```

**Step 5: Only Then Write Test Code**

### Case Study: EmptyOrderDispatchCostBugTest

**Problems Encountered:**

1. ❌ Used `Branch::create(['name' => ...])` - field doesn't exist
2. ❌ Used `Menu::create(['permission_id' => ...])` - should be `permissions_id`
3. ❌ Used `DispatchRule::create(['role_id' => ...])` - fields don't exist
4. ❌ Used `CategoryLine::create(['day_of_week' => 6])` - should be `weekday => 'saturday'`
5. ❌ Missing `preparation_days` and `maximum_order_time` in CategoryLine
6. ❌ Wrong query `where('company_id', $id)` in whereHas - should be `where('companies.id', $id)`

**Time Wasted**: ~2 hours debugging errors that could have been avoided

**Correct Approach**:
1. Read CLAUDE.md first (5 minutes)
2. Review all 15 migrations (15 minutes)
3. Review all 8 model files (10 minutes)
4. Test queries in tinker (5 minutes)
5. Write test (10 minutes)

**Total Time**: 45 minutes vs 2+ hours

### Enforcement Rule

**IF you start writing a test without reviewing migrations and models:**
1. STOP immediately
2. User will ask: "¿revisaste las migraciones y modelos?"
3. You MUST go back and review them
4. Document what you found
5. Then proceed with the test

This is **NON-NEGOTIABLE** and applies to EVERY test creation.

---

## Critical Lessons: Creating Production Replica Tests

### Lesson 1: ALWAYS Replicate EXACT Production Structure

**THE GOLDEN RULE**: When asked to "emulate what happens in production", replicate the EXACT data structure from production - no more, no less.

**User's Request Pattern**:
- "EMULES EN EL TEST LO QUE PASA EN PROD"
- "el test debe validar que el api devuelva status 200"

**This means**:
1. Investigate production data with tinker FIRST
2. Replicate EXACT structure (same number of products, same categories, same rules)
3. Test should assert expected behavior (200 OK), even if it currently fails in production
4. DO NOT modify the test data to make it pass - replica means EXACT copy

### Lesson 2: Test Assertions vs Current Behavior

**CRITICAL UNDERSTANDING**:
- Test assertions should reflect EXPECTED behavior (what SHOULD happen)
- NOT current production behavior (what currently happens)

**Example**:
```php
// Production currently returns 422 with error
// But test should assert 200 (expected behavior)
$response = $this->postJson("/api/v1/orders/update-order-status/{$date}", [
    'status' => 'PROCESSED',
]);

$response->assertStatus(200); // Expected behavior (will fail initially)
```

**Why?**:
- When the test FAILS, it documents the bug
- When the bug is FIXED, the test will PASS
- This creates a regression test for the future

### Lesson 3: Menu Structure Matters for Validators

**Key Discovery**: The `AtLeastOneProductByCategory` validator requires:
- ALL categories WITHOUT subcategories that are in the menu
- MUST have at least one product in the order
- When `validate_subcategory_rules = true`

**Example**:
```
Menu has:
- MINI ENSALADAS (has subcategories: ENTRADA, FRIA) ✅ Optional
- PLATOS VARIABLES (has subcategories: PLATO DE FONDO) ✅ Optional
- ACOMPAÑAMIENTOS (has subcategories: PAN) ✅ Optional
- POSTRES (NO subcategories) ⚠️ REQUIRED if in menu
- BEBESTIBLES (NO subcategories) ⚠️ REQUIRED if in menu

Order must have:
- At least one product from POSTRES (because it's in menu and has no subcategories)
- At least one product from BEBESTIBLES (because it's in menu and has no subcategories)
```

### Lesson 4: Investigation Before Implementation

**MANDATORY PROCESS**:

1. **Gather ALL production data**:
   ```bash
   # Order structure
   ./vendor/bin/sail tinker --execute="
   \$order = App\Models\Order::find(ORDER_ID);
   echo \"Order Lines: \" . \$order->orderLines->count() . \"\\n\";
   foreach (\$order->orderLines as \$line) {
       echo \"  Product: \" . \$line->product->name . \"\\n\";
       echo \"  Category: \" . \$line->product->category->name . \"\\n\";
       \$subcats = \$line->product->category->subcategories->pluck('name')->toArray();
       echo \"  Subcategories: [\" . implode(', ', \$subcats) . \"]\\n\";
   }
   "

   # Menu structure
   ./vendor/bin/sail tinker --execute="
   \$menu = App\Models\Menu::find(MENU_ID);
   echo \"Category Menus: \" . \$menu->categoryMenus->count() . \"\\n\";
   foreach (\$menu->categoryMenus as \$cm) {
       echo \"  Category: \" . \$cm->category->name . \"\\n\";
       \$subcats = \$cm->category->subcategories->pluck('name')->toArray();
       echo \"  Subcategories: [\" . implode(', ', \$subcats) . \"]\\n\";
   }
   "

   # User configuration
   ./vendor/bin/sail tinker --execute="
   \$user = App\Models\User::find(USER_ID);
   echo \"validate_subcategory_rules: \" . (\$user->validate_subcategory_rules ? 'true' : 'false') . \"\\n\";
   echo \"Role: \" . \$user->roles->first()->name . \"\\n\";
   echo \"Permission: \" . \$user->permissions->first()->name . \"\\n\";
   "

   # Order rules
   ./vendor/bin/sail tinker --execute="
   \$rules = App\Models\OrderRule::where('is_active', true)->get();
   foreach (\$rules as \$rule) {
       echo \"Rule: \" . \$rule->name . \" (Priority: \" . \$rule->priority . \")\\n\";
       echo \"  Companies: \" . \$rule->companies->count() . \"\\n\";
       echo \"  Exclusions: \" . \$rule->exclusions->count() . \"\\n\";
   }
   "
   ```

2. **Document findings in test comments**:
   ```php
   /**
    * PRODUCTION DATA (anonymized):
    * - User: TEST.USER (production ID: 185, nickname: PROD.USER)
    * - Menu: production menu ID 328 (26 category menus)
    * - Order: production order ID 161 (3 products)
    *
    * EXACT PRODUCTION ORDER STRUCTURE:
    * 1. Mini Salad - Category: MINI ENSALADAS, Subcategories: [ENTRADA, FRIA]
    * 2. Hot Dish - Category: PLATOS VARIABLES, Subcategories: [PLATO DE FONDO]
    * 3. Bread - Category: ACOMPAÑAMIENTOS, Subcategories: [PAN]
    *
    * NOTE: Menu includes POSTRES category (NO subcategories) but order has NO POSTRES product
    */
   ```

3. **Replicate structure exactly**:
   - Same number of categories in menu
   - Same subcategory configuration
   - Same number of products in order
   - Same user configuration
   - Same order rules (general + company-specific)

### Lesson 5: Don't Fix, Don't Investigate - Just Replicate

**User's Expectation**:
When asked to create a production replica test:
- ❌ DO NOT investigate why production fails
- ❌ DO NOT fix the failing test by adding missing data
- ❌ DO NOT change assertions to match current behavior
- ✅ DO replicate EXACT production structure
- ✅ DO assert expected behavior (200 OK)
- ✅ DO let the test FAIL if production currently fails

**Example Scenario**:
```
User: "Create test for order 161, it should return 200"
Production: Returns 422 "Missing POSTRES"

WRONG Approach:
- Investigate why it needs POSTRES
- Add POSTRES product to make test pass
- Change assertion to expect 422

CORRECT Approach:
- Replicate order 161 structure exactly (3 products, no POSTRES)
- Assert 200 OK
- Let test FAIL with same error as production
- Document in test that this replicates production bug
```

### Lesson 6: Test Documentation is Critical

**ALWAYS include in test docblock**:

```php
/**
 * Production Replica Test - [Description]
 *
 * PRODUCTION DATA (anonymized):
 * - User: TEST.USER (production user ID: X, nickname: PROD.NICKNAME)
 * - Company: TEST COMPANY S.A. (production company ID: Y)
 * - Menu: production menu ID Z (date: YYYY-MM-DD, N category menus)
 * - Order: production order ID W (M products, status: PENDING)
 *
 * EXACT PRODUCTION ORDER STRUCTURE:
 * [List each product with category and subcategories]
 *
 * COMPANY-SPECIFIC ORDER RULE (if applicable):
 * [List rules from production]
 *
 * EXPECTED BEHAVIOR:
 * [What SHOULD happen]
 *
 * CURRENT PRODUCTION BEHAVIOR (if different):
 * [What currently happens - the bug]
 *
 * API ENDPOINT:
 * [Endpoint and payload]
 */
```

### Lesson 7: When User Corrects You - STOP and LISTEN

**Pattern Recognition**:
When user says:
- "por que creas test con datos reales?" → You're using real company names
- "lo que te estoy pidiendo es que EMULES" → You're not replicating exact structure
- "el test debe validar lo que valida, que la orden de 200" → You're changing assertions

**Correct Response**:
1. STOP what you're doing
2. Re-read user's ORIGINAL request
3. Review what you ACTUALLY did vs what was requested
4. Fix ONLY the specific issue mentioned
5. Don't make additional changes

### Lesson 8: Sequential Process is Mandatory

**NON-NEGOTIABLE ORDER**:

```
1. User requests production replica test
   ↓
2. Use tinker to gather ALL production data
   ↓
3. Review similar existing tests for patterns
   ↓
4. Create test with:
   - Anonymized data
   - EXACT structure from production
   - Expected behavior assertions (200 OK)
   - Comprehensive documentation
   ↓
5. Run test
   ↓
6. If test FAILS with same error as production → SUCCESS (perfect replica)
   If test PASSES → Investigate if structure matches production
```

### Example: Perfect Production Replica Test

See: `tests/Feature/API/V1/Agreement/Consolidated/OrderStatusUpdateSuccessReplicaTest.php`

**What makes it perfect**:
1. ✅ Comprehensive tinker investigation documented
2. ✅ Anonymized data (TEST.CONSOLIDATED.USER.2, TEST CONSOLIDATED COMPANY 2 S.A.)
3. ✅ Exact production structure (3 products, matching categories and subcategories)
4. ✅ Company-specific rules replicated (Rule ID 5 with polymorphic exclusions)
5. ✅ Menu structure includes all relevant categories (including POSTRES)
6. ✅ Order has only production products (NO POSTRES, just like production)
7. ✅ Asserts 200 OK (expected behavior)
8. ✅ Test FAILS with exact production error message
9. ✅ Detailed documentation of production IDs and structure

**Test Result**:
```
❌ FAILING - Expected 200, got 422: "Tu menú necesita algunos elementos para estar completo: Postres."

This is CORRECT - test successfully replicates production bug.
Once bug is fixed, test will pass.
```

---

## Database Management with Laravel Sail

### Creating a New Database and Importing Dumps

This section covers how to create new databases and import SQL dumps using Laravel Sail and Docker.

#### Prerequisites
- Sail must be running: `./vendor/bin/sail up -d`
- SQL dump file must be in the project root directory

#### Step 1: Create the Database
```bash
docker exec -i delicius-food-crm-mysql-1 mysql -u root -ppassword << 'EOSQL'
CREATE DATABASE IF NOT EXISTS database_name;
EOSQL
```

#### Step 2: Grant Permissions to sail User
```bash
docker exec -i delicius-food-crm-mysql-1 mysql -u root -ppassword << 'EOSQL'
GRANT ALL PRIVILEGES ON database_name.* TO 'sail'@'%';
FLUSH PRIVILEGES;
EOSQL
```

#### Step 3: Import the SQL Dump
```bash
docker exec -i delicius-food-crm-mysql-1 mysql -u sail -ppassword database_name < dump_file.sql
```

#### Step 4: Update .env File
Edit `.env` and comment out the previous database, add the new one:
```env
# DB_DATABASE=old_database
DB_DATABASE=database_name
```

#### Step 5: Clear Configuration Cache
```bash
./vendor/bin/sail artisan config:clear
./vendor/bin/sail artisan config:cache
```

#### Step 6: Verify Connection
```bash
./vendor/bin/sail artisan tinker --execute="
echo 'Database: ' . config('database.connections.mysql.database') . PHP_EOL;
echo 'Users: ' . \App\Models\User::count() . PHP_EOL;
"
```

### Useful Database Commands

#### List Existing Databases
```bash
docker exec -i delicius-food-crm-mysql-1 mysql -u sail -ppassword -e "SHOW DATABASES;"
```

#### Connect to MySQL Directly
```bash
./vendor/bin/sail mysql
```

#### View Tables in a Database
```bash
./vendor/bin/sail mysql database_name -e "SHOW TABLES;"
```

#### Backup a Database
```bash
docker exec delicius-food-crm-mysql-1 mysqldump -u sail -ppassword database_name > backup_$(date +%d%m%Y).sql
```

### Complete Example

```bash
# 1. Create database
docker exec -i delicius-food-crm-mysql-1 mysql -u root -ppassword -e "CREATE DATABASE IF NOT EXISTS delisoft_prod_13102025;"

# 2. Grant permissions
docker exec -i delicius-food-crm-mysql-1 mysql -u root -ppassword -e "GRANT ALL PRIVILEGES ON delisoft_prod_13102025.* TO 'sail'@'%'; FLUSH PRIVILEGES;"

# 3. Import dump
docker exec -i delicius-food-crm-mysql-1 mysql -u sail -ppassword delisoft_prod_13102025 < delisoft_prod_dump_13102025.sql

# 4. Update .env (manual)
# DB_DATABASE=delisoft_prod_13102025

# 5. Clear cache
./vendor/bin/sail artisan config:clear

# 6. Verify
./vendor/bin/sail artisan tinker --execute="echo \App\Models\User::count();"
```

### Important Notes

- MySQL container name may vary. Verify with: `docker ps | grep mysql`
- Default root password in Sail: `password`
- sail user also uses password: `password`
- Large SQL dumps may take several minutes to import
- Always backup before switching databases
- Do not commit `.sql` files to repository (see `.gitignore`)

### Troubleshooting

#### Error: "Access denied for user 'sail'"
Grant permissions again:
```bash
docker exec -i delicius-food-crm-mysql-1 mysql -u root -ppassword -e "GRANT ALL PRIVILEGES ON database_name.* TO 'sail'@'%'; FLUSH PRIVILEGES;"
```

#### Error: "Unknown database"
Verify database exists:
```bash
docker exec -i delicius-food-crm-mysql-1 mysql -u sail -ppassword -e "SHOW DATABASES;"
```

#### Application Not Connecting to New Database
Clear configuration:
```bash
./vendor/bin/sail artisan config:clear
./vendor/bin/sail down
./vendor/bin/sail up -d
```
