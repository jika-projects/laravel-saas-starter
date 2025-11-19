<?php

namespace Ebrook\SaasStarter\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ebrook-saas:install 
                            {--force : Overwrite existing files}
                            {--composer=global : Absolute path to the Composer binary which should be used to install packages}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install eBrook SaaS Starter by publishing files to the main project';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Installing eBrook SaaS Starter...');
        $this->newLine();

        // 1. 安装必需的依赖
        if (!$this->installRequiredDependencies()) {
            $this->error('Failed to install required dependencies.');
            $this->error('Installation aborted.');
            return self::FAILURE;
        }

        $this->newLine();

        // 1.5. 安装 Filament General Settings
        $this->info('Installing Filament General Settings...');
        if (!$this->installFilamentGeneralSettings()) {
            $this->error('Failed to install Filament General Settings.');
            $this->error('Installation aborted.');
            return self::FAILURE;
        }

        $this->newLine();

        // 重新发现包，确保新安装的包可用
        $this->info('Discovering packages...');
        $this->call('package:discover');
        $this->newLine();

        // 2. 安装 Filament 面板
        $this->info('Installing Filament panels...');
        if (!$this->runArtisanCommand(['filament:install', '--panels', '--no-interaction'])) {
            $this->error('Failed to install Filament panels.');
            return self::FAILURE;
        }
        $this->newLine();

        // 3. 发布权限系统配置
        $this->info('Publishing permission configurations...');
        if (!$this->runArtisanCommand([
            'vendor:publish',
            '--tag=permission-migrations',
            '--tag=permission-config',
            '--tag=filament-shield-config',
            '--force'
        ])) {
            $this->error('Failed to publish permission configurations.');
            return self::FAILURE;
        }
        $this->newLine();

        // 4. 安装 Shield
        $this->info('Installing Filament Shield...');
        if (!$this->runArtisanCommand(['shield:install', 'admin'])) {
            $this->error('Failed to install Shield.');
            return self::FAILURE;
        }
        $this->newLine();

        // 5. 安装 Tenancy
        $this->info('Installing Tenancy...');
        if (!$this->runArtisanCommand(['tenancy:install', '--no-interaction'])) {
            $this->error('Failed to install Tenancy.');
            return self::FAILURE;
        }
        $this->newLine();

        // 8. 安装 Filament Payments 和 Media Library
        $this->info('Installing Filament Payments and Media Library...');
        if (!$this->installFilamentPayments()) {
            $this->error('Failed to install Filament Payments.');
            return self::FAILURE;
        }
        $this->newLine();

        // 5.5. 配置Lago和禁用TomatoPHP插件
        $this->info('Configuring Lago and updating AdminPanelProvider...');
        if (!$this->configureLagoAndAdminPanel()) {
            $this->error('Failed to configure Lago and update AdminPanelProvider.');
            return self::FAILURE;
        }

        $this->newLine();

        // 6. 发布 ActivityLog 配置和迁移
        $this->info('Publishing ActivityLog configurations...');
        if (!$this->runArtisanCommand([
            'vendor:publish',
            '--provider=Spatie\Activitylog\ActivitylogServiceProvider',
            '--tag=activitylog-migrations',
            '--tag=activitylog-config',
        ])) {
            $this->error('Failed to publish ActivityLog configurations.');
            return self::FAILURE;
        }
        $this->newLine();

        // 7. 发布 eBrook SaaS Starter 文件
        $this->info('Publishing eBrook SaaS Starter files...');
        $force = $this->option('force');

        // 复制迁移文件
        $this->publishMigrations($force);

        // 复制 Filament 资源
        $this->publishFilamentResources($force);

        // 复制模型文件
        $this->publishModels($force);

        // 复制 Providers 文件
        $this->publishProviders($force);

        // 复制 Jobs 文件
        $this->publishJobs($force);

        // 复制 Services 文件
        $this->publishServices($force);

        // 复制 Tenant Controllers 文件
        $this->publishTenantControllers($force);

        // 复制 Routes 文件
        $this->publishRoutes($force);

        // 复制 Pricing 页面视图
        $this->publishPricingView($force);

        // 更新服务提供者配置
        $this->updateServiceProviders();

        // 更新 AdminPanelProvider 添加 FilamentGeneralSettingsPlugin
        $this->updateAdminPanelProvider();

        // 配置 Filament General Settings
        $this->configureFilamentGeneralSettings();

        // 更新认证配置
        $this->updateAuthConfig();

        // 更新租户配置
        $this->updateTenancyConfig();

        // 更新 TenancyServiceProvider 添加 SeedTenantShield Job
        $this->updateTenancyServiceProvider();

        // 清除配置缓存以确保新配置生效
        $this->info('Clearing configuration cache...');
        $this->runArtisanCommand(['config:clear']);
        $this->runArtisanCommand(['config:cache']);

        $this->newLine();
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('✓ eBrook SaaS Starter has been installed successfully!');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();
        $this->comment('All files have been published to your project.');
        $this->newLine();
        $this->info('Next steps:');
        $this->line('  1. Run: php artisan migrate');
        $this->line('  2. Run: php artisan shield:generate --all');
        $this->line('  3. Run: php artisan shield:super-admin');
        $this->newLine();
        $this->info('You can now remove the "ebrook/b2b-saas-starter" package from');
        $this->info('your composer.json if desired, as all files have been published.');
        $this->newLine();
    }

    /**
     * 复制文件并替换命名空间
     */
    protected function copyAndReplaceFile(string $sourcePath, string $targetPath, bool $force = false): void
    {
        if (!$force && File::exists($targetPath)) {
            $fileName = basename($targetPath);
            if (!$this->confirm("File {$fileName} already exists. Overwrite?", false)) {
                $this->warn("Skipping file {$fileName}...");
                return;
            }
        }

        $content = File::get($sourcePath);
        // The user has confirmed that the namespace does not need to be replaced.

        File::put($targetPath, $content);
        $this->info("Published file " . basename($targetPath));
    }

    /**
     * 复制 database 目录（包括 migrations 和 seeders）
     */
    protected function publishMigrations(bool $force = false): void
    {
        $this->publishDirectory(
            __DIR__ . '/../../../database',
            base_path('database'),
            $force,
            'Database'
        );
    }

    /**
     * 复制 Filament 资源文件
     */
    protected function publishFilamentResources(bool $force = false): void
    {
        $this->publishDirectory(
            __DIR__ . '/../../../src/Filament',
            base_path('app/Filament'),
            $force,
            'Filament'
        );
    }

    /**
     * 复制模型文件
     */
    protected function publishModels(bool $force = false): void
    {
        $this->publishDirectory(
            __DIR__ . '/../../../src/Models',
            base_path('app/Models'),
            $force,
            'Models'
        );
    }

    /**
     * 复制 Providers 文件
     */
    protected function publishProviders(bool $force = false): void
    {
        $this->publishDirectory(
            __DIR__ . '/../../Providers',
            base_path('app/Providers'),
            $force,
            'Providers'
        );
    }

    /**
     * 复制 Jobs 文件
     */
    protected function publishJobs(bool $force = false): void
    {
        $this->publishDirectory(
            __DIR__ . '/../../Jobs',
            base_path('app/Jobs'),
            $force,
            'Jobs'
        );
    }

    /**
     * 复制 Services 文件
     */
    protected function publishServices(bool $force = false): void
    {
        $this->publishDirectory(
            __DIR__ . '/../../Services',
            base_path('app/Services'),
            $force,
            'Services'
        );
    }

    /**
     * 复制 Tenant Controllers 文件
     */
    protected function publishTenantControllers(bool $force = false): void
    {
        $this->publishDirectory(
            __DIR__ . '/../../Http/Controllers/Tenant',
            base_path('app/Http/Controllers/Tenant'),
            $force,
            'Tenant Controllers'
        );
    }

    /**
     * 复制 Routes 文件
     */
    protected function publishRoutes(bool $force = false): void
    {
        $sourcePath = __DIR__ . '/../../../routes/tenant.php';
        $targetPath = base_path('routes/tenant.php');

        if (!File::exists($sourcePath)) {
            $this->warn("Routes file not found at {$sourcePath}");
            return;
        }

        if (!$force && File::exists($targetPath)) {
            $this->info('Routes file already exists, skipping...');
            return;
        }

        $content = File::get($sourcePath);

        // 确保目标目录存在
        $targetDir = dirname($targetPath);
        if (!File::exists($targetDir)) {
            File::makeDirectory($targetDir, 0755, true);
        }

        File::put($targetPath, $content);
        $this->info('Published routes/tenant.php');
    }

    /**
     * 通用的目录发布方法
     */
    protected function publishDirectory(string $sourcePath, string $targetPath, bool $force = false, string $name = 'Directory'): void
    {
        if (!File::exists($sourcePath)) {
            $this->warn("{$name} directory not found at {$sourcePath}");
            return;
        }

        // 确保目标目录存在
        if (!File::exists($targetPath)) {
            File::makeDirectory($targetPath, 0755, true);
        }

        // 递归复制目录
        $this->copyDirectoryRecursively($sourcePath, $targetPath, $force);
    }

    /**
     * 递归复制目录
     */
    protected function copyDirectoryRecursively(string $sourceDir, string $targetDir, bool $force = false): void
    {
        // 如果目标目录不存在，创建它
        if (!File::exists($targetDir)) {
            File::makeDirectory($targetDir, 0755, true);
        }

        // 获取源目录中的所有文件
        $files = File::files($sourceDir);
        foreach ($files as $file) {
            $targetFile = $targetDir . '/' . $file->getFilename();
            $this->copyAndReplaceFile($file->getPathname(), $targetFile, $force);
        }

        // 递归复制子目录
        $subDirs = File::directories($sourceDir);
        foreach ($subDirs as $subDir) {
            $subDirName = basename($subDir);
            $targetSubDir = $targetDir . '/' . $subDirName;
            $this->copyDirectoryRecursively($subDir, $targetSubDir, $force);
        }
    }

    /**
     * 更新服务提供者配置
     */
    protected function updateServiceProviders(): void
    {
        $bootstrapProvidersPath = base_path('bootstrap/providers.php');

        if (!File::exists($bootstrapProvidersPath)) {
            $this->warn('bootstrap/providers.php not found, skipping service provider update...');
            return;
        }

        $content = File::get($bootstrapProvidersPath);
        $modified = false;

        // 需要添加的 Service Providers
        $providersToAdd = [
            'App\Providers\TenancyServiceProvider::class',
            'App\Providers\Filament\AppPanelProvider::class',
            'App\Providers\ActivityLogServiceProvider::class',
        ];

        foreach ($providersToAdd as $provider) {
            // 检查是否已经存在（转义反斜杠）
            $escapedProvider = str_replace('\\', '\\\\', $provider);
            if (strpos($content, $provider) === false) {
                // 在 ]; 之前添加新的 Provider
                // 使用简单的字符串替换
                $newLine = "    {$provider},\n";
                $content = str_replace("];", $newLine . "];", $content);
                
                $this->info("Added {$provider} to bootstrap/providers.php");
                $modified = true;
            } else {
                $this->info("{$provider} already exists in bootstrap/providers.php");
            }
        }

        if ($modified) {
            File::put($bootstrapProvidersPath, $content);
            $this->info('Service providers updated successfully');
        }
    }

    /**
     * 更新认证配置
     */
    protected function updateAuthConfig(): void
    {
        $authConfigPath = config_path('auth.php');

        if (!File::exists($authConfigPath)) {
            $this->warn('config/auth.php not found, skipping auth config update...');
            return;
        }

        $content = File::get($authConfigPath);
        $modified = false;

        // 检查是否已经存在 tenant guard
        if (!preg_match("/'tenant'\s*=>/", $content)) {
            // 在 'web' guard 之后添加 'tenant' guard
            $tenantGuard = "\n        'tenant' => [\n            'driver' => 'session',\n            'provider' => 'tenant_users',\n        ],";
            
            // 查找 'web' guard 的结束位置
            $pattern = "/('web'\s*=>\s*\[[^\]]*\],)/s";
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, "$1{$tenantGuard}", $content);
                $this->info("Added 'tenant' guard to config/auth.php");
                $modified = true;
            }
        } else {
            $this->info("'tenant' guard already exists in config/auth.php");
        }

        // 检查是否已经存在 tenant_users provider
        if (!preg_match("/'tenant_users'\s*=>/", $content)) {
            // 在 'users' provider 之后添加 'tenant_users' provider
            $tenantProvider = "\n\n        'tenant_users' => [\n            'driver' => 'eloquent',\n            'model' => App\\Models\\Tenant\\User::class,\n        ],";
            
            // 查找 'users' provider 的结束位置（在 providers 数组中）
            $pattern = "/('providers'\s*=>\s*\[[^\[]*'users'\s*=>\s*\[[^\]]*\],)/s";
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, "$1{$tenantProvider}", $content);
                $this->info("Added 'tenant_users' provider to config/auth.php");
                $modified = true;
            }
        } else {
            $this->info("'tenant_users' provider already exists in config/auth.php");
        }

        if ($modified) {
            File::put($authConfigPath, $content);
            $this->info('Auth config updated successfully');
        }
    }

    /**
     * 更新租户配置
     */
    protected function updateTenancyConfig(): void
    {
        $tenancyConfigPath = config_path('tenancy.php');

        if (!File::exists($tenancyConfigPath)) {
            $this->warn('config/tenancy.php not found, skipping tenancy config update...');
            return;
        }

        $content = File::get($tenancyConfigPath);

        // 替换 Tenant 模型类
        $oldTenantModel = 'Stancl\Tenancy\Database\Models\Tenant::class';
        $newTenantModel = 'App\Models\Tenant::class';

        if (strpos($content, $oldTenantModel) !== false) {
            $content = str_replace($oldTenantModel, $newTenantModel, $content);
            File::put($tenancyConfigPath, $content);
            $this->info("Updated tenant model from {$oldTenantModel} to {$newTenantModel} in config/tenancy.php");
        } else if (strpos($content, $newTenantModel) !== false) {
            $this->info("Tenant model already set to {$newTenantModel} in config/tenancy.php");
        } else {
            $this->warn("Could not find tenant model configuration in config/tenancy.php");
        }
    }

    /**
     * 更新 TenancyServiceProvider 添加 SeedTenantShield Job
     */
    protected function updateTenancyServiceProvider(): void
    {
        $providerPath = base_path('app/Providers/TenancyServiceProvider.php');

        if (!File::exists($providerPath)) {
            $this->warn('app/Providers/TenancyServiceProvider.php not found, skipping TenancyServiceProvider update...');
            return;
        }

        $content = File::get($providerPath);

        // 检查是否已经存在 SeedTenantShield
        if (strpos($content, 'SeedTenantShield::class') !== false) {
            $this->info('SeedTenantShield::class already exists in TenancyServiceProvider.php');
            return;
        }

        // 在 Jobs\MigrateDatabase::class 之后添加 \App\Jobs\SeedTenantShield::class
        $pattern = '/(Jobs\\\\MigrateDatabase::class,)/';
        if (preg_match($pattern, $content)) {
            $replacement = "$1\n                    \\App\\Jobs\\SeedTenantShield::class,";
            $content = preg_replace($pattern, $replacement, $content);
            
            File::put($providerPath, $content);
            $this->info('Added \\App\\Jobs\\SeedTenantShield::class to TenancyServiceProvider.php JobPipeline');
        } else {
            $this->warn('Could not find Jobs\\MigrateDatabase::class in TenancyServiceProvider.php');
        }
    }

    /**
     * 安装必需的 Composer 依赖包
     * 
     * 确保这些依赖在项目的 composer.json 中（而不仅仅是通过 dev 依赖间接引入）
     * 这样用户在移除 ebrook/b2b-saas-starter 包后，项目仍然可以正常运行
     */
    protected function installRequiredDependencies(): bool
    {
        $this->info('Checking dependencies...');
        $this->newLine();

        // 必需的依赖及其版本
        $packages = [
            'filament/filament:^4.2',
            'bezhansalleh/filament-shield:^4.0',
            'stancl/tenancy:dev-master',
            'spatie/laravel-activitylog:^4.10',
            'joaopaulolndev/filament-general-settings:^2.0',
            'tomatophp/filament-payments:^1.0',
            'spatie/laravel-medialibrary:^11.0',
            'spatie/laravel-translatable:^6.0'
        ];

        // 检查哪些包需要在项目 composer.json 中显式声明
        $packagesToInstall = [];
        
        foreach ($packages as $package) {
            [$name, $version] = explode(':', $package);
            
            if (!$this->isPackageInstalled($name)) {
                $packagesToInstall[] = $package;
                $this->comment("  • {$name} will be added to composer.json");
            } else {
                $this->info("  ✓ {$name} is already in composer.json");
            }
        }

        if (empty($packagesToInstall)) {
            $this->info('All required dependencies are already declared in composer.json!');
            $this->newLine();
            return true;
        }

        $this->newLine();
        $this->warn('The following packages need to be added to your project composer.json:');
        foreach ($packagesToInstall as $package) {
            [$name] = explode(':', $package);
            $this->line("  • {$name}");
        }
        $this->newLine();
        $this->comment('This ensures your project will work correctly after removing the starter package.');
        $this->newLine();

        // 如果使用了 --force 选项，直接安装，不询问
        $force = $this->option('force');
        
        if (!$force && !$this->confirm('Do you want to add these packages now?', true)) {
            $this->warn('Skipping package installation. You may need to add them manually later.');
            return true;
        }

        $this->newLine();
        $this->info('Installing ' . count($packagesToInstall) . ' package(s)...');
        $this->newLine();

        return $this->requireComposerPackages($packagesToInstall);
    }

    /**
     * 检查包是否已安装
     */
    protected function isPackageInstalled(string $package): bool
    {
        $composerJsonPath = base_path('composer.json');
        
        if (!File::exists($composerJsonPath)) {
            return false;
        }

        $composerJson = json_decode(File::get($composerJsonPath), true);
        
        return isset($composerJson['require'][$package]) || isset($composerJson['require-dev'][$package]);
    }

    /**
     * 使用 Composer 安装指定的包
     */
    protected function requireComposerPackages(array $packages, bool $asDev = false): bool
    {
        $composer = $this->option('composer');

        if ($composer !== 'global') {
            $command = ['php', $composer, 'require'];
        }

        $command = array_merge(
            $command ?? ['composer', 'require'],
            $packages,
            $asDev ? ['--dev'] : [],
        );

        return (new Process($command, base_path(), ['COMPOSER_MEMORY_LIMIT' => '-1']))
            ->setTimeout(null)
            ->run(function ($type, $output) {
                $this->output->write($output);
            }) === 0;
    }

    /**
     * 安装 Filament General Settings
     */
    protected function installFilamentGeneralSettings(): bool
    {
        // 1. 发布 migrations
        $this->info('  Publishing Filament General Settings migrations...');
        if (!$this->runArtisanCommand([
            'vendor:publish',
            '--tag=filament-general-settings-migrations',
            '--force'
        ])) {
            $this->error('Failed to publish Filament General Settings migrations.');
            return false;
        }

        // 2. 运行迁移
        $this->info('  Running database migrations...');
        if (!$this->runArtisanCommand(['migrate'])) {
            $this->error('Failed to run database migrations.');
            return false;
        }

        // 3. 发布配置文件
        $this->info('  Publishing Filament General Settings config...');
        if (!$this->runArtisanCommand([
            'vendor:publish',
            '--tag=filament-general-settings-config',
            '--force'
        ])) {
            $this->error('Failed to publish Filament General Settings config.');
            return false;
        }

        $this->info('Filament General Settings installed successfully!');
        return true;
    }

    /**
     * 更新 AdminPanelProvider 添加 FilamentGeneralSettingsPlugin
     */
    protected function updateAdminPanelProvider(): void
    {
        $adminPanelProviderPath = base_path('app/Providers/Filament/AdminPanelProvider.php');

        if (!File::exists($adminPanelProviderPath)) {
            $this->warn('AdminPanelProvider.php not found, skipping AdminPanelProvider update...');
            return;
        }

        $content = File::get($adminPanelProviderPath);
        $modified = false;

        // 1. 添加 FilamentGeneralSettingsPlugin 的 use 语句
        $useStatement = 'use Joaopaulolndev\FilamentGeneralSettings\FilamentGeneralSettingsPlugin;';
        if (strpos($content, $useStatement) === false) {
            // 在 use Illuminate\View\Middleware\ShareErrorsFromSession; 后面添加
            $content = preg_replace(
                '/(use Illuminate\\\\View\\\\Middleware\\\\ShareErrorsFromSession;)/',
                '$1' . "\n" . $useStatement,
                $content
            );

            $this->info("Added FilamentGeneralSettingsPlugin use statement to AdminPanelProvider.php");
            $modified = true;
        }

        // 2. 更新 FilamentShieldPlugin 添加 navigationGroup
        if (!preg_match('/FilamentShieldPlugin::make\(\)\s*->navigationGroup/', $content)) {
            // 为 FilamentShieldPlugin 添加 navigationGroup
            $content = preg_replace(
                '/(FilamentShieldPlugin::make\(\))/',
                '$1->navigationGroup(fn() => __(\'System\'))',
                $content
            );
            $this->info("Added navigationGroup to FilamentShieldPlugin in AdminPanelProvider.php");
            $modified = true;
        }

        // 3. 添加 FilamentGeneralSettingsPlugin 到 plugins 配置中
        if (!preg_match('/->plugins\(\s*\[.*FilamentGeneralSettingsPlugin::make\(\).*?\]\s*\)/s', $content)) {
            // 在 FilamentShieldPlugin 之后添加
            $pluginConfig = "                FilamentGeneralSettingsPlugin::make()
                    ->setSort(3)
                    ->setIcon('heroicon-o-cog')
                    ->setNavigationGroup('System')
                    ->setTitle('General Settings')
                    ->canAccess(fn() => auth()->user()?->can('View:GeneralSettingsPage'))
                    ->setNavigationLabel('General Settings'),";

            // 查找 FilamentShieldPlugin 及其配置，并在之后添加新的插件
            $pattern = '/(\s*FilamentShieldPlugin::make\(\)[^,]*),(\s*\])/s';
            if (preg_match($pattern, $content, $matches)) {
                $replacement = $matches[1] . ',' . "\n" . $pluginConfig . "\n" . $matches[2];
                $content = preg_replace($pattern, $replacement, $content);
                $this->info("Added FilamentGeneralSettingsPlugin to AdminPanelProvider.php");
                $modified = true;
            } else {
                $this->warn("Could not find FilamentShieldPlugin::make() in AdminPanelProvider.php plugins array");
            }
        }

        if ($modified) {
            File::put($adminPanelProviderPath, $content);
            $this->info('AdminPanelProvider.php has been updated successfully!');
        } else {
            $this->info('AdminPanelProvider.php is already up to date.');
        }
    }
    /**
     * 安装 Filament Payments 及其依赖（包括 Spatie Media Library）
     */
    protected function installFilamentPayments(): bool
    {
        // 1. 发布 Spatie Media Library 迁移文件
        $this->info('  Publishing Spatie Media Library migrations...');
        if (!$this->runArtisanCommand([
            'vendor:publish',
            '--provider=Spatie\\MediaLibrary\\MediaLibraryServiceProvider',
            '--tag=medialibrary-migrations',
            '--force'
        ])) {
            $this->error('Failed to publish Media Library migrations.');
            return false;
        }

        // 2. 应用 patch 文件
        $this->info('  Applying filament4.0_payment.patch...');
        if (!$this->applyPaymentPatch()) {
            $this->error('Failed to apply filament4.0_payment.patch.');
            return false;
        }

        $this->info('Filament Payments installed successfully!');
        return true;
    }

    /**
     * 应用 filament4.0_payment.patch 补丁文件
     */
    protected function applyPaymentPatch(): bool
    {
        $patchFile = __DIR__ . '/../../../patches/filament4.0_payment.patch';

        if (!File::exists($patchFile)) {
            $this->warn("Patch file not found at {$patchFile}");
            return false;
        }

        // 检查系统是否安装了 `patch` 命令
        $process = new Process(['which', 'patch']);
        $process->run();
        if (!$process->isSuccessful()) {
            $this->error("The 'patch' command is not available. Please install it (e.g., apt install patch on Ubuntu).");
            return false;
        }

        // 执行 patch 命令（在项目根目录应用）
        // 先尝试应用patch，如果失败则检查是否已经应用过
        $command = ['patch', '-p1', '-N', '-i', $patchFile];
        $process = new Process($command, base_path());
        $process->setTimeout(null);

        $exitCode = $process->run(function ($type, $output) {
            $this->output->write($output);
        });

        if ($exitCode !== 0) {
            // 检查是否patch已经被应用过
            $checkCommand = ['patch', '-p1', '-R', '--dry-run', '-i', $patchFile];
            $checkProcess = new Process($checkCommand, base_path());
            $checkExitCode = $checkProcess->run();

            if ($checkExitCode === 0) {
                // patch已经被应用过
                $this->info("Patch already applied, skipping...");
                return true;
            }

            $this->error("Failed to apply patch. Exit code: {$exitCode}");
            $this->error("Patch command output:");
            $this->line($process->getOutput());
            $this->line($process->getErrorOutput());
            return false;
        }

        $this->info("Patch applied successfully!");
        return true;
    }

    /**
     * 复制 Pricing 页面视图文件
     */
    protected function publishPricingView(bool $force = false): void
    {
        $sourcePath = __DIR__ . '/../../../resources/views/filament/app/pages/pricing.blade.php';
        $targetPath = resource_path('views/filament/app/pages/pricing.blade.php');

        // 确保目标目录存在
        $targetDir = dirname($targetPath);
        if (!File::exists($targetDir)) {
            File::makeDirectory($targetDir, 0755, true);
        }

        if (!File::exists($sourcePath)) {
            $this->warn("Pricing view file not found at {$sourcePath}");
            return;
        }

        $this->copyAndReplaceFile($sourcePath, $targetPath, $force);
    }

    /**
     * 运行 Artisan 命令（使用 Process，确保重新加载服务提供者）
     */
    protected function runArtisanCommand(array $command): bool
    {
        $command = array_merge(['php', 'artisan'], $command);

        return (new Process($command, base_path()))
            ->setTimeout(null)
            ->run(function ($type, $output) {
                $this->output->write($output);
            }) === 0;
    }

    /**
     * 配置Lago服务并更新AdminPanelProvider
     */
    protected function configureLagoAndAdminPanel(): bool
    {
        // 1. 配置services.php添加Lago设置
        $servicesPath = config_path('services.php');
        if (!File::exists($servicesPath)) {
            $this->warn("Services config file not found at {$servicesPath}");
            return false;
        }

        $content = File::get($servicesPath);

        // 检查是否已经配置了Lago
        if (!str_contains($content, "'lago' =>")) {
            $this->info('  Adding Lago configuration to services.php...');

            // 在stripe配置后添加lago配置
            $lagoConfig = "
    'lago' => [
        'base_url' => env('LAGO_BASE_URL'),
        'api_key' => env('LAGO_API_KEY'),
        'timeout' => env('LAGO_TIMEOUT', 30),
    ],";

            // 查找stripe配置的结束位置
            $pattern = '/(\s*\'stripe\'\s*=>\s*\[[^\]]+\],?\s*)/s';
            if (preg_match($pattern, $content, $matches)) {
                $replacement = $matches[1] . "\n" . $lagoConfig . "\n";
                $content = preg_replace($pattern, $replacement, $content);
                File::put($servicesPath, $content);
                $this->info('  Lago configuration added to services.php');
            } else {
                $this->warn('  Could not find stripe configuration in services.php');
            }
        } else {
            $this->info('  Lago configuration already exists in services.php');
        }

        // 2. 更新AdminPanelProvider禁用TomatoPHP插件
        $adminPanelProviderPath = app_path('Providers/Filament/AdminPanelProvider.php');
        if (!File::exists($adminPanelProviderPath)) {
            $this->warn("AdminPanelProvider not found at {$adminPanelProviderPath}");
            return false;
        }

        $content = File::get($adminPanelProviderPath);

        // 检查是否需要禁用TomatoPHP插件
        if (str_contains($content, "\\TomatoPHP\\FilamentPayments\\FilamentPaymentsPlugin::make()")) {
            $this->info('  Disabling TomatoPHP FilamentPaymentsPlugin...');

            // 将TomatoPHP插件注释掉
            $content = str_replace(
                "                \\TomatoPHP\\FilamentPayments\\FilamentPaymentsPlugin::make(),",
                "                // \\TomatoPHP\\FilamentPayments\\FilamentPaymentsPlugin::make(), // 禁用TomatoPHP的payment插件，使用自定义的Payments资源",
                $content
            );

            File::put($adminPanelProviderPath, $content);
            $this->info('  TomatoPHP FilamentPaymentsPlugin disabled in AdminPanelProvider.php');
        } else {
            $this->info('  TomatoPHP plugin already disabled or not present');
        }

        return true;
    }

    /**
     * 配置 Filament General Settings
     */
    protected function configureFilamentGeneralSettings(): bool
    {
        $configPath = config_path('filament-general-settings.php');

        // 检查配置文件是否存在
        if (!File::exists($configPath)) {
            $this->warn('filament-general-settings.php config file not found, creating default configuration...');

            // 创建默认配置文件
            $defaultConfig = "<?php

return [
    'show_application_tab' => false,
    'show_logo_and_favicon' => false,
    'show_analytics_tab' => false,
    'show_seo_tab' => false,
    'show_email_tab' => false,
    'show_social_networks_tab' => false,
    'expiration_cache_config_time' => 60,

    // 启用自定义标签页
    'show_custom_tabs' => true,

    // 自定义配置标签页 - API和支付设置
    'custom_tabs' => [
        'api_payment_settings' => [
            'label' => 'API & Payment Settings',
            'icon' => 'heroicon-o-key',
            'columns' => 2,
            'fields' => [
                'lago_base_url' => [
                    'type' => 'text',
                    'label' => 'Lago Base URL',
                    'placeholder' => 'https://api.lago.dev',
                    'required' => false,
                    'rules' => [],
                ],
                'lago_api_key' => [
                    'type' => 'password',
                    'label' => 'Lago API Key',
                    'placeholder' => 'Enter your Lago API key',
                    'required' => false,
                    'rules' => [],
                ],
                'lago_timeout' => [
                    'type' => 'text',
                    'label' => 'Lago Timeout (seconds)',
                    'placeholder' => '30',
                    'required' => false,
                    'rules' => [],
                ],
                'stripe_secret_key' => [
                    'type' => 'password',
                    'label' => 'Stripe Secret Key',
                    'placeholder' => 'Place your Stripe Secret Key here',
                    'required' => false,
                    'rules' => [],
                ],
                'stripe_publishable_key' => [
                    'type' => 'text',
                    'label' => 'Stripe Publishable Key',
                    'placeholder' => 'Place your Stripe Publishable Key here',
                    'required' => false,
                    'rules' => [],
                ],
            ],
        ],
    ],
];
";

            File::put($configPath, $defaultConfig);
            $this->info('Created filament-general-settings.php with API & Payment configuration');
            return true;
        }

        // 如果文件已存在，检查是否需要更新
        $content = File::get($configPath);

        // 检查是否已经有custom_tabs配置
        if (strpos($content, "'custom_tabs' =>") === false) {
            $this->info('Updating filament-general-settings.php to add custom tabs configuration...');

            // 在expiration_cache_config_time后面添加自定义配置
            $customConfig = "

    // 启用自定义标签页
    'show_custom_tabs' => true,

    // 自定义配置标签页 - API和支付设置
    'custom_tabs' => [
        'api_payment_settings' => [
            'label' => 'API & Payment Settings',
            'icon' => 'heroicon-o-key',
            'columns' => 2,
            'fields' => [
                'lago_base_url' => [
                    'type' => 'text',
                    'label' => 'Lago Base URL',
                    'placeholder' => 'https://api.lago.dev',
                    'required' => false,
                    'rules' => [],
                ],
                'lago_api_key' => [
                    'type' => 'password',
                    'label' => 'Lago API Key',
                    'placeholder' => 'Enter your Lago API key',
                    'required' => false,
                    'rules' => [],
                ],
                'lago_timeout' => [
                    'type' => 'text',
                    'label' => 'Lago Timeout (seconds)',
                    'placeholder' => '30',
                    'required' => false,
                    'rules' => [],
                ],
                'stripe_secret_key' => [
                    'type' => 'password',
                    'label' => 'Stripe Secret Key',
                    'placeholder' => 'sk_test_...',
                    'required' => false,
                    'rules' => [],
                ],
                'stripe_publishable_key' => [
                    'type' => 'text',
                    'label' => 'Stripe Publishable Key',
                    'placeholder' => 'pk_test_...',
                    'required' => false,
                    'rules' => [],
                ],
            ],
        ],
    ],";

            // 在expiration_cache_config_time后添加配置
            $content = preg_replace(
                '/(\'expiration_cache_config_time\' => \d+,)/',
                '$1' . $customConfig,
                $content
            );

            File::put($configPath, $content);
            $this->info('Updated filament-general-settings.php with custom tabs configuration');
        } else {
            $this->info('filament-general-settings.php already has custom tabs configuration');
        }

        return true;
    }

}
