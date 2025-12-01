<?php

namespace Ebrook\SaasStarter\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
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
        $this->displayWelcome();

        $totalSteps = 5;
        $currentStep = 1;

        // Phase 1: 环境准备
        if (!$this->runPhase($currentStep++, $totalSteps, '环境准备', fn() => $this->setupEnvironment())) {
            return self::FAILURE;
        }

        // Phase 2: 核心组件安装
        if (!$this->runPhase($currentStep++, $totalSteps, '核心组件安装', fn() => $this->installCoreComponents())) {
            return self::FAILURE;
        }

        // Phase 3: 发布文件和资源
        if (!$this->runPhase($currentStep++, $totalSteps, '发布文件和资源', fn() => $this->publishFiles())) {
            return self::FAILURE;
        }

        // Phase 4: 配置应用
        if (!$this->runPhase($currentStep++, $totalSteps, '配置应用', fn() => $this->configureApplication())) {
            return self::FAILURE;
        }

        // Phase 5: 完成安装
        if (!$this->runPhase($currentStep++, $totalSteps, '完成安装', fn() => $this->finalizeInstallation())) {
            return self::FAILURE;
        }

        $this->displaySuccess();

        return self::SUCCESS;
    }

    /**
     * 显示欢迎信息
     */
    protected function displayWelcome(): void
    {
        $this->newLine();
        $this->info('╔══════════════════════════════════════════════════════════╗');
        $this->info('║         eBrook SaaS Starter Installation                ║');
        $this->info('╚══════════════════════════════════════════════════════════╝');
        $this->newLine();
    }

    /**
     * 运行安装阶段
     */
    protected function runPhase(int $current, int $total, string $phaseName, callable $action): bool
    {
        $this->info("┌─ Phase {$current}/{$total}: {$phaseName}");
        $this->newLine();

        $result = $action();

        if ($result) {
            $this->info("└─ ✓ {$phaseName} completed");
        } else {
            $this->error("└─ ✗ {$phaseName} failed");
            $this->error('Installation aborted.');
        }

        $this->newLine();

        return $result;
    }

    /**
     * Phase 1: 环境准备
     */
    protected function setupEnvironment(): bool
    {
        // 1. 添加自定义 Composer 仓库
        $this->info('  → Adding custom Composer repositories...');
        if (!$this->addCustomComposerRepositories()) {
            $this->error('    Failed to add custom Composer repositories.');
            return false;
        }

        // 2. 安装必需的依赖
        $this->info('  → Installing required dependencies...');
        if (!$this->installRequiredDependencies()) {
            $this->error('    Failed to install required dependencies.');
            return false;
        }

        // 3. 重新发现包，确保新安装的包可用
        $this->info('  → Discovering packages...');
        $this->call('package:discover');

        return true;
    }

    /**
     * Phase 2: 核心组件安装
     */
    protected function installCoreComponents(): bool
    {
        // 安装 Filament General Settings
        if (!$this->installComponent('Filament General Settings', 'installFilamentGeneralSettings')) {
            return false;
        }

        // 安装 Filament Panels
        if (!$this->installComponent('Filament Panels', function() {
            return $this->runArtisanCommand(['filament:install', '--panels', '--no-interaction']);
        })) {
            return false;
        }

        // 发布权限系统配置
        if (!$this->installComponent('Permission System', function() {
            return $this->runArtisanCommand([
                'vendor:publish',
                '--tag=permission-migrations',
                '--tag=permission-config',
                '--tag=filament-shield-config',
                '--force'
            ]);
        })) {
            return false;
        }

        // 安装 Filament Shield
        if (!$this->installComponent('Filament Shield', function() {
            return $this->runArtisanCommand(['shield:install', 'admin']);
        })) {
            return false;
        }

        // 安装 Tenancy
        if (!$this->installComponent('Tenancy', function() {
            return $this->runArtisanCommand(['tenancy:install', '--no-interaction']);
        })) {
            return false;
        }

        // 安装 Filament Payments & Media Library
        if (!$this->installComponent('Filament Payments & Media Library', 'installFilamentPayments')) {
            return false;
        }

        // 发布 ActivityLog 配置和迁移
        if (!$this->installComponent('ActivityLog', function() {
            return $this->runArtisanCommand([
                'vendor:publish',
                '--provider=Spatie\Activitylog\ActivitylogServiceProvider',
                '--tag=activitylog-migrations',
                '--tag=activitylog-config',
            ]);
        })) {
            return false;
        }

        // 配置Lago和禁用TomatoPHP插件
        $this->info('  → Configuring Lago and AdminPanel...');
        if (!$this->configureLagoAndAdminPanel()) {
            $this->error('    Failed to configure Lago.');
            return false;
        }

        // 配置 Tailwind CSS
        $this->info('  → Setting up Tailwind CSS...');
        if (!$this->setupTailwindCss()) {
            $this->warn('    Failed to setup Tailwind CSS. You can configure it manually later.');
        }

        return true;
    }

    /**
     * 安装组件的辅助方法
     */
    protected function installComponent(string $name, $method): bool
    {
        $this->info("  → Installing {$name}...");

        $result = is_callable($method) ? $method() : $this->{$method}();

        if (!$result) {
            $this->error("    Failed to install {$name}.");
            return false;
        }

        return true;
    }

    /**
     * Phase 3: 发布文件和资源
     */
    protected function publishFiles(): bool
    {
        $this->info('  → Publishing application files...');

        $force = $this->option('force');

        $publishMethods = [
            'Migrations' => 'publishMigrations',
            'Filament Resources' => 'publishFilamentResources',
            'Models' => 'publishModels',
            'Providers' => 'publishProviders',
            'Jobs' => 'publishJobs',
            'Services' => 'publishServices',
            'Traits' => 'publishTraits',
            'Tenant Controllers' => 'publishTenantControllers',
            'Routes' => 'publishRoutes',
            'Pricing View' => 'publishPricingView',
            'Middleware' => 'publishMiddleware',
            'Helpers' => 'publishHelpers',
            'Observers' => 'publishObservers',
        ];

        foreach ($publishMethods as $name => $method) {
            $this->comment("    • Publishing {$name}...");
            $this->{$method}($force);
        }

        return true;
    }

    /**
     * Phase 4: 配置应用
     */
    protected function configureApplication(): bool
    {
        $this->info('  → Updating application configurations...');

        $configurations = [
            'Service Providers' => 'updateServiceProviders',
            'AdminPanel Provider' => 'updateAdminPanelProvider',
            'Filament General Settings' => 'configureFilamentGeneralSettings',
            'Auth Config' => 'updateAuthConfig',
            'Tenancy Config' => 'updateTenancyConfig',
            'Tenancy Service Provider' => 'updateTenancyServiceProvider',
            'Middleware Registration' => 'updateMiddlewareConfig',
        ];

        foreach ($configurations as $name => $method) {
            $this->comment("    • Configuring {$name}...");
            $this->{$method}();
        }

        return true;
    }

    /**
     * Phase 5: 完成安装
     */
    protected function finalizeInstallation(): bool
    {
        $this->info('  → Clearing and caching configuration...');
        $this->runArtisanCommand(['config:clear']);
        $this->runArtisanCommand(['config:cache']);

        return true;
    }

    /**
     * 显示成功信息
     */
    protected function displaySuccess(): void
    {
        $this->newLine();
        $this->info('╔══════════════════════════════════════════════════════════╗');
        $this->info('║     ✓ Installation Completed Successfully!              ║');
        $this->info('╚══════════════════════════════════════════════════════════╝');
        $this->newLine();

        $this->comment('All files have been published to your project.');
        $this->newLine();

        $this->info('Next Steps:');
        $this->line('  1. Run: npm install');
        $this->line('  2. Run: php artisan migrate');
        $this->line('  3. Run: php artisan shield:generate --all');
        $this->line('  4. Run: php artisan shield:super-admin');
        $this->newLine();

        $this->comment('Optional: You can remove "ebrook/b2b-saas-starter" from composer.json');
        $this->comment('as all files have been published to your project.');
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
     * 复制 Traits 文件
     */
    protected function publishTraits(bool $force = false): void
    {
        $this->publishDirectory(
            __DIR__ . '/../../Traits',
            base_path('app/Traits'),
            $force,
            'Traits'
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
            'tomatophp/filament-payments:dev-master',  // 使用自定义仓库的 master 分支
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
        // 发布 Spatie Media Library 迁移文件
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

        $this->info('Filament Payments installed successfully!');
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

    // 自定义加密字段
    'encrypted_fields' => [
        # 'lago_api_key',
    ],

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
                    'encrypt' => true,
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
                    'encrypt' => true,
                ],
                'stripe_publishable_key' => [
                    'type' => 'text',
                    'label' => 'Stripe Publishable Key',
                    'placeholder' => 'Place your Stripe Publishable Key here',
                    'required' => false,
                    'rules' => [],
                    'encrypt' => true,
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

        // 如果文件已存在，确保配置正确
        $content = File::get($configPath);
        $modified = false;

        // 1. 确保所有不需要的标签页都被禁用
        $replacements = [
            "'show_application_tab' => true" => "'show_application_tab' => false",
            "'show_logo_and_favicon' => true" => "'show_logo_and_favicon' => false",
            "'show_analytics_tab' => true" => "'show_analytics_tab' => false",
            "'show_seo_tab' => true" => "'show_seo_tab' => false",
            "'show_email_tab' => true" => "'show_email_tab' => false",
            "'show_social_networks_tab' => true" => "'show_social_networks_tab' => false",
        ];

        foreach ($replacements as $search => $replace) {
            if (strpos($content, $search) !== false) {
                $content = str_replace($search, $replace, $content);
                $modified = true;
            }
        }

        // 2. 确保show_custom_tabs为true
        if (strpos($content, "'show_custom_tabs' => false") !== false) {
            $content = str_replace("'show_custom_tabs' => false", "'show_custom_tabs' => true", $content);
            $modified = true;
        } elseif (strpos($content, "'show_custom_tabs'") === false) {
            // 如果没有show_custom_tabs配置，添加它
            $content = preg_replace(
                '/(\'expiration_cache_config_time\' => \d+,)/',
                '$1' . "\n    'show_custom_tabs' => true,",
                $content
            );
            $modified = true;
        }

        // 3. 检查是否已经有custom_tabs配置
        if (strpos($content, "'custom_tabs' =>") === false) {
            $this->info('Adding custom tabs configuration...');

            // 添加自定义配置
            $customConfig = "

    // 自定义加密字段
    'encrypted_fields' => [
        # 'lago_api_key',
        # 'stripe_secret_key',
        # 'stripe_publishable_key',
    ],

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
                    'encrypt' => true,
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
                    'encrypt' => true,
                ],
                'stripe_publishable_key' => [
                    'type' => 'text',
                    'label' => 'Stripe Publishable Key',
                    'placeholder' => 'pk_test_...',
                    'required' => false,
                    'rules' => [],
                    'encrypt' => true,
                ],
            ],
        ],
    ],";

            // 在show_custom_tabs后或expiration_cache_config_time后添加配置
            if (strpos($content, "'show_custom_tabs' => true") !== false) {
                $content = preg_replace(
                    '/(\'show_custom_tabs\' => true,)/',
                    '$1' . $customConfig,
                    $content
                );
            } else {
                $content = preg_replace(
                    '/(\'expiration_cache_config_time\' => \d+,)/',
                    '$1' . "\n    'show_custom_tabs' => true," . $customConfig,
                    $content
                );
            }

            $modified = true;
            $this->info('Added custom tabs configuration');
        }

        if ($modified) {
            File::put($configPath, $content);
            $this->info('Updated filament-general-settings.php configuration');
        } else {
            $this->info('filament-general-settings.php configuration is already correct');
        }

        return true;
    }

    /**
     * 添加自定义 Composer 仓库配置
     */
    protected function addCustomComposerRepositories(): bool
    {
        $this->info('Adding custom Composer repositories...');
        $this->newLine();

        $composerJsonPath = base_path('composer.json');

        if (!File::exists($composerJsonPath)) {
            $this->error('composer.json not found.');
            return false;
        }

        $composerJson = json_decode(File::get($composerJsonPath), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Failed to parse composer.json: ' . json_last_error_msg());
            return false;
        }

        // 初始化 repositories 数组（如果不存在）
        if (!isset($composerJson['repositories'])) {
            $composerJson['repositories'] = [];
        }

        // 定义自定义仓库
        $customRepository = [
            'type' => 'vcs',
            'url' => 'https://github.com/jika-projects/filament-payments.git'
        ];

        // 检查是否已经存在该仓库配置
        $repositoryExists = false;
        foreach ($composerJson['repositories'] as $repo) {
            if (isset($repo['url']) && $repo['url'] === $customRepository['url']) {
                $repositoryExists = true;
                break;
            }
        }

        if ($repositoryExists) {
            $this->info('Custom repository for filament-payments already exists in composer.json');
        } else {
            // 添加自定义仓库到 repositories 数组开头
            array_unshift($composerJson['repositories'], $customRepository);

            // 保存修改后的 composer.json
            $jsonContent = json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            File::put($composerJsonPath, $jsonContent . "\n");

            $this->info('Added custom repository: ' . $customRepository['url']);
        }

        $this->newLine();
        return true;
    }

    /**
     * 更新 AppServiceProvider 注册 GeneralSettingObserver
     */
    protected function updateAppServiceProvider(): void
    {
        $appServiceProviderPath = base_path('app/Providers/AppServiceProvider.php');

        if (!File::exists($appServiceProviderPath)) {
            $this->warn('AppServiceProvider.php not found, skipping AppServiceProvider update...');
            return;
        }

        $content = File::get($appServiceProviderPath);
        $modified = false;

        // 1. 添加 use 语句
        $useStatements = [
            'use App\Observers\GeneralSettingObserver;',
            'use Joaopaulolndev\FilamentGeneralSettings\Models\GeneralSetting;',
        ];

        foreach ($useStatements as $useStatement) {
            if (strpos($content, $useStatement) === false) {
                // 在 namespace 声明后添加
                $pattern = '/(namespace App\\\\Providers;)/';
                if (preg_match($pattern, $content)) {
                    $content = preg_replace($pattern, "$1\n\n{$useStatement}", $content);
                    $this->info("Added {$useStatement} to AppServiceProvider.php");
                    $modified = true;
                }
            }
        }

        // 2. 在 boot 方法中注册 Observer
        $observerRegistration = 'GeneralSetting::observe(GeneralSettingObserver::class);';

        if (strpos($content, $observerRegistration) === false) {
            // 查找 boot 方法
            $pattern = '/(public function boot\(\): void\s*\{)/';
            if (preg_match($pattern, $content)) {
                $replacement = "$1\n        // Register the GeneralSetting observer for encryption/decryption\n        {$observerRegistration}";
                $content = preg_replace($pattern, $replacement, $content);
                $this->info("Registered GeneralSettingObserver in AppServiceProvider.php");
                $modified = true;
            } else {
                 $this->warn("Could not find boot method in AppServiceProvider.php");
            }
        }

        if ($modified) {
            File::put($appServiceProviderPath, $content);
            $this->info('AppServiceProvider.php has been updated successfully!');
        } else {
            $this->info('AppServiceProvider.php is already up to date.');
        }
    }



    /**
     * 复制 Middleware 文件
     */
    protected function publishMiddleware(bool $force = false): void
    {
        $this->publishDirectory(
            __DIR__ . '/../../Http/Middleware',
            base_path('app/Http/Middleware'),
            $force,
            'Middleware'
        );
    }

    /**
     * 复制 Helpers 文件
     */
    protected function publishHelpers(bool $force = false): void
    {
        $this->publishDirectory(
            __DIR__ . '/../../Helpers',
            base_path('app/Helpers'),
            $force,
            'Helpers'
        );
    }

    /**
     * 复制 Observers 文件
     */
    protected function publishObservers(bool $force = false): void
    {
        $this->publishDirectory(
            __DIR__ . '/../../Observers',
            base_path('app/Observers'),
            $force,
            'Observers'
        );
    }

    /**
     * 更新 Middleware 配置 (bootstrap/app.php)
     */
    protected function updateMiddlewareConfig(): void
    {
        $bootstrapAppPath = base_path('bootstrap/app.php');

        if (!File::exists($bootstrapAppPath)) {
            $this->warn('bootstrap/app.php not found, skipping middleware update...');
            return;
        }

        $content = File::get($bootstrapAppPath);
        $modified = false;

        // 需要添加的 Middleware
        $middlewareToAdd = [
            '\App\Http\Middleware\ForceHttps::class',
            '\App\Http\Middleware\SecurityHeaders::class',
        ];

        // 检查是否已经存在
        $missingMiddleware = [];
        foreach ($middlewareToAdd as $middleware) {
            if (strpos($content, $middleware) === false) {
                $missingMiddleware[] = $middleware;
            }
        }

        if (empty($missingMiddleware)) {
            $this->info('Middleware already registered in bootstrap/app.php');
            return;
        }

        // 构造要添加的代码块
        $middlewareCode = "";
        foreach ($missingMiddleware as $middleware) {
            $middlewareCode .= "            {$middleware},\n";
        }

        // 尝试找到 ->withMiddleware(function (Middleware $middleware): void { ... })
        // 并添加 $middleware->append([...]);

        // 匹配 withMiddleware 闭包的开始
        $pattern = '/(->withMiddleware\s*\(\s*function\s*\(\s*Middleware\s*\$middleware\s*\)\s*:\s*void\s*\{)/';

        if (preg_match($pattern, $content)) {
            // 检查是否已经有 append 调用
            if (strpos($content, '$middleware->append([') !== false) {
                // 如果已有 append，尝试插入到数组中
                $appendPattern = '/(\$middleware->append\(\[)/';
                $content = preg_replace($appendPattern, "$1\n{$middlewareCode}", $content);
                $modified = true;
            } else {
                // 如果没有 append，添加新的 append 调用
                $replacement = "$1\n        \$middleware->append([\n{$middlewareCode}        ]);";
                $content = preg_replace($pattern, $replacement, $content);
                $modified = true;
            }
        } else {
            $this->warn('Could not find withMiddleware callback in bootstrap/app.php');
        }

        if ($modified) {
            File::put($bootstrapAppPath, $content);
            $this->info('Registered middleware in bootstrap/app.php');
        }
    }

    /**
     * 配置 Tailwind CSS
     */
    protected function setupTailwindCss(): bool
    {
        try {
            // 1. 配置 package.json
            if (!$this->configurePackageJson()) {
                $this->error('Failed to configure package.json');
                return false;
            }

            // 2. 配置 vite.config.js
            if (!$this->configureViteConfig()) {
                $this->error('Failed to configure vite.config.js');
                return false;
            }

            // 3. 配置 resources/css/app.css
            if (!$this->configureAppCss()) {
                $this->error('Failed to configure resources/css/app.css');
                return false;
            }

            $this->info('Tailwind CSS configuration completed successfully!');
            $this->comment('Remember to run: npm install');
            return true;
        } catch (\Exception $e) {
            $this->error('Error setting up Tailwind CSS: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 配置 package.json 添加 Tailwind CSS 依赖
     */
    protected function configurePackageJson(): bool
    {
        $packageJsonPath = base_path('package.json');

        // 如果 package.json 不存在，创建它
        if (!File::exists($packageJsonPath)) {
            $defaultPackageJson = [
                '$schema' => 'https://www.schemastore.org/package.json',
                'private' => true,
                'type' => 'module',
                'scripts' => [
                    'build' => 'vite build',
                    'dev' => 'vite',
                ],
                'devDependencies' => [],
            ];
            File::put($packageJsonPath, json_encode($defaultPackageJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            $this->info('Created package.json');
        }

        // 读取现有的 package.json
        $content = File::get($packageJsonPath);
        $packageJson = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Failed to parse package.json: ' . json_last_error_msg());
            return false;
        }

        // 确保 devDependencies 存在
        if (!isset($packageJson['devDependencies'])) {
            $packageJson['devDependencies'] = [];
        }

        // 确保 scripts 存在
        if (!isset($packageJson['scripts'])) {
            $packageJson['scripts'] = [];
        }

        $modified = false;

        // 添加 Tailwind CSS 相关依赖
        $dependencies = [
            '@tailwindcss/vite' => '^4.0.0',
            'tailwindcss' => '^4.0.0',
            'vite' => '^7.0.7',
            'laravel-vite-plugin' => '^2.0.0',
        ];

        foreach ($dependencies as $package => $version) {
            if (!isset($packageJson['devDependencies'][$package])) {
                $packageJson['devDependencies'][$package] = $version;
                $this->info("Added {$package} to package.json");
                $modified = true;
            } else {
                $this->info("{$package} already exists in package.json");
            }
        }

        // 确保必要的 scripts 存在
        if (!isset($packageJson['scripts']['dev'])) {
            $packageJson['scripts']['dev'] = 'vite';
            $modified = true;
        }
        if (!isset($packageJson['scripts']['build'])) {
            $packageJson['scripts']['build'] = 'vite build';
            $modified = true;
        }

        if ($modified) {
            // 排序 devDependencies（可选，但更整洁）
            ksort($packageJson['devDependencies']);

            $jsonContent = json_encode($packageJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            File::put($packageJsonPath, $jsonContent . "\n");
            $this->info('Updated package.json');
        }

        return true;
    }

    /**
     * 配置 vite.config.js
     */
    protected function configureViteConfig(): bool
    {
        $viteConfigPath = base_path('vite.config.js');

        // 如果文件已存在，检查是否已配置 Tailwind
        if (File::exists($viteConfigPath)) {
            $content = File::get($viteConfigPath);

            // 检查是否已包含 tailwindcss 插件
            if (strpos($content, '@tailwindcss/vite') !== false && strpos($content, 'tailwindcss()') !== false) {
                $this->info('vite.config.js already configured with Tailwind CSS');
                return true;
            }
        }

        // 创建或更新 vite.config.js
        $viteConfig = <<<'JS'
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
JS;

        File::put($viteConfigPath, $viteConfig);
        $this->info('Configured vite.config.js');
        return true;
    }

    /**
     * 配置 resources/css/app.css
     */
    protected function configureAppCss(): bool
    {
        $cssDir = resource_path('css');
        $appCssPath = $cssDir . '/app.css';

        // 确保目录存在
        if (!File::exists($cssDir)) {
            File::makeDirectory($cssDir, 0755, true);
        }

        // 如果文件已存在，检查是否已配置 Tailwind
        if (File::exists($appCssPath)) {
            $content = File::get($appCssPath);

            // 检查是否已包含 Tailwind 导入
            if (strpos($content, '@import \'tailwindcss\';') !== false || strpos($content, '@import "tailwindcss";') !== false) {
                $this->info('resources/css/app.css already configured with Tailwind CSS');
                return true;
            }
        }

        // 创建或更新 app.css
        $appCss = <<<'CSS'
@import 'tailwindcss';

@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';
@source '../**/*.blade.php';
@source '../**/*.js';

@theme {
    --font-sans: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji',
        'Segoe UI Symbol', 'Noto Color Emoji';
}
CSS;

        File::put($appCssPath, $appCss);
        $this->info('Configured resources/css/app.css');
        return true;
    }

}
