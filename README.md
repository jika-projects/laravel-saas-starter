# Laravel B2B SaaS Starter

A Laravel-based B2B SaaS application starter kit with integrated Filament admin panel, multi-tenancy system, and complete permission management.

## ✨ Features

- 🏢 **Multi-Tenancy Architecture** - Complete multi-tenant system based on Stancl/Tenancy
- 🎨 **Filament Admin Panel** - Modern admin interface out of the box
- 🔐 **Permission Management** - Fine-grained permission control with Filament Shield
- 👥 **User Management** - Complete user and tenant user management system
- 🚀 **Quick Start** - One-command installation for rapid SaaS deployment

## 📋 Requirements

- PHP 8.4 or higher
- Laravel 12.0 or higher
- Composer 2.0+
- MySQL 5.7+ or PostgreSQL 9.6+

## 🚀 Quick Installation (3 Steps)

### 1. Create a New Laravel Project

```bash
composer create-project laravel/laravel your-project-name
cd your-project-name
```

### 2. Install the SaaS Starter Package

```bash
composer config repositories.laravel-saas-starter vcs https://github.com/jika-projects/laravel-saas-starter
composer require ebrook/b2b-saas-starter --dev
```

### 3. One-Command Installation (Automates Everything)

```bash
php artisan ebrook-saas:install --force
```

> **✨ This command automatically:**
> - ✓ Installs Filament, Shield, and Tenancy dependencies
> - ✓ Configures Filament panels
> - ✓ Sets up permission system
> - ✓ Configures multi-tenancy architecture
> - ✓ Publishes all files to your project

### 4. Run Migrations and Create Admin

```bash
php artisan migrate
php artisan shield:generate --all
php artisan shield:super-admin
```

### 5. Run npm to build view

```bash
npm install
npm run build
```

### 6. Done!

```bash
php artisan serve
```

Visit `http://localhost:8000/admin` and login with your admin account.

---

## 📚 Detailed Documentation

### What Does the Install Command Do?

`php artisan ebrook-saas:install` executes the following operations in sequence:

1. **Install Dependencies**
   - `filament/filament` (^4.2)
   - `bezhansalleh/filament-shield` (^4.0)
   - `stancl/tenancy` (dev-master)

2. **Configure Filament**
   - Run `filament:install --panels`

3. **Configure Permission System**
   - Publish Spatie Permission configuration
   - Install Filament Shield

4. **Configure Multi-Tenancy**
   - Run `tenancy:install`

5. **Publish Project Files**
   - Models (Tenant, User, etc.)
   - Filament Resources (tenant management, user management)
   - Migrations (database schema)
   - Service Providers (multi-tenancy service provider)
   - Jobs (tenant initialization tasks)

6. **Update Configuration Files**
   - Update `bootstrap/providers.php`
   - Update `config/auth.php` (add tenant guard)
   - Update `config/tenancy.php` (use custom Tenant model)

### Optional Configuration

**Remove the Starter Package:**

After installation, all files are copied to your project and you can safely remove the starter package:
```bash
composer remove ebrook/b2b-saas-starter --dev
```

---

## 📚 Next Steps

- Configure database connection and other environment variables in `.env` file
- Modify and extend Filament resources according to your business needs
- Configure tenant domain or subdomain strategy
- Set up mail service for user notifications

## 🛠 Troubleshooting

### What if I encounter errors during installation?

**Dependency installation failed:**
```bash
# Manually install dependencies
composer require filament/filament:^4.2 bezhansalleh/filament-shield:^4.0 stancl/tenancy:dev-master
# Then re-run the install command
php artisan ebrook-saas:install --force
```

**File conflicts:**
- Using the `--force` option will automatically overwrite all files
- Without `--force`, you'll be prompted for each file conflict

**Permission issues:**
```bash
chmod -R 775 storage bootstrap/cache
```

### How to Customize?

After installation, all files are in your project and can be modified directly:
- **Models**: `app/Models/`
- **Filament Resources**: `app/Filament/Resources/` and `app/Filament/App/Resources/`
- **Migrations**: `database/migrations/`
- **Service Providers**: `app/Providers/`

## 📝 License

This project is licensed under the MIT License. See the LICENSE file for details.

## 🤝 Contributing

Issues and Pull Requests are welcome!
