<?php

namespace Ebrook\SaasStarter;

use Illuminate\Support\ServiceProvider;
use Ebrook\SaasStarter\Console\Commands\InstallCommand;

class EbrookSaasServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 配置文件将通过 ebrook-saas:install 命令发布到项目中
        // 不使用 mergeConfigFrom，因为这是一个 starter 包，需要用户先运行安装命令
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);
        }
    }
}
