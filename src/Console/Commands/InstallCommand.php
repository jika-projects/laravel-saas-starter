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

        // 更新服务提供者配置
        $this->updateServiceProviders();

        // 更新 AdminPanelProvider 添加 FilamentGeneralSettingsPlugin
        $this->updateAdminPanelProvider();

        // 更新认证配置
        $this->updateAuthConfig();

        // 更新租户配置
        $this->updateTenancyConfig();

        // 更新 TenancyServiceProvider 添加 SeedTenantShield Job
        $this->updateTenancyServiceProvider();

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

        // 2. 添加 FilamentGeneralSettingsPlugin 到 plugins 配置中
        if (!preg_match('/->plugins\(\s*\[.*FilamentGeneralSettingsPlugin::make\(\).*?\]\s*\)/s', $content)) {
            // 在 FilamentShieldPlugin 之后添加
            $pluginConfig = "                FilamentGeneralSettingsPlugin::make()
                    ->setSort(3)
                    ->setIcon('heroicon-o-cog')
                    ->setNavigationGroup('System')
                    ->setTitle('General Settings')
                    ->canAccess(fn() => auth()->user()?->can('View:GeneralSettingsPage'))
                    ->setNavigationLabel('General Settings'),";

            // 查找 FilamentShieldPlugin::make() 并在它之后添加新的插件
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

}
