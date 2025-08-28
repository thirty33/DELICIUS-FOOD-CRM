# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

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
- explica que es lo que vas a hacer, no me des código sin explicación
- no crees comentarios en el código en español