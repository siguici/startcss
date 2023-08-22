<?php

namespace Sikessem\UI;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Illuminate\Support\Str;
use Illuminate\View\Compilers\BladeCompiler;
use ReflectionClass;

class UIServiceProvider extends BaseServiceProvider
{
    /**
     * Bootstrap UI services.
     *
     * @return void
     */
    public function register()
    {
        $this->registerConfig();
        $this->registerServices();
    }

    /**
     * Register UI services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerTranslations();
        $this->registerViews();
        $this->registerCompilers();
        $this->registerNamespaces();
        $this->registerDirectives();
        $this->registerComponents();
        $this->registerPublishables();
    }

    protected function registerServices(): void
    {
        $this->app->singleton(UIManager::class);
        $this->app->instance('ui.path', sikessem_ui_path());
        $this->app->alias(UIManager::class, 'sikessem.ui');
        $this->app->alias('blade.compiler', UITemplateCompiler::class);
    }

    protected function registerConfig(): void
    {
        $this->mergeConfigFrom(sikessem_ui_path('config/ui.php'), 'sikessem.ui');
    }

    protected function registerTranslations(): void
    {
        $this->loadTranslationsFrom(sikessem_ui_path('res/langs'), 'ui');
    }

    protected function registerViews(): void
    {
        $this->loadViewsFrom(sikessem_ui_path('res/views'), 'ui');
    }

    protected function registerNamespaces(): void
    {
        Blade::componentNamespace(UIManager::COMPONENT_NAMESPACE, 'ui');
        Blade::anonymousComponentNamespace(UIManager::ANONYMOUS_COMPONENT_NAMESPACE, 'ui');
    }

    protected function registerDirectives(): void
    {
        $directivesClass = new ReflectionClass(UIDirectives::class);
        foreach ($directivesClass->getMethods() as $directiveMethod) {
            if ($directiveMethod->isStatic() && $directiveMethod->isPublic()) {
                $directiveName = $directiveMethod->getName();
                /** @var callable */
                $directive = [UIDirectives::class, $directiveName];
                Blade::directive($directiveName, $directive);
            }
        }
    }

    protected function registerCompilers(): void
    {
        $app = $this->app;
        /** @var UITemplateCompiler */
        $compiler = $app->make('blade.compiler');
        if (method_exists($compiler, 'precompiler')) {
            $compiler->precompiler(function ($string) use ($app) {
                /** @var UIComponentCompiler */
                $precompiler = $app->make(UIComponentCompiler::class);

                return $precompiler->compile($string);
            });
        }
    }

    protected function registerComponents(): void
    {
        $this->callAfterResolving(BladeCompiler::class, function () {
            $this->registerComponentPath(sikessem_ui_path('src/Components'));
            $this->registerComponentPath(sikessem_ui_path('res/views/components'), anonymous: true);
        });
    }

    protected function registerPublishables(): void
    {
        $this->publishesToGroups([
            sikessem_ui_path('config/ui.php') => config_path('sikessem/ui.php'),
        ], ['sikessem', 'sikessem:ui', 'sikessem:ui.config']);

        $this->publishesToGroups([
            sikessem_ui_path('res/langs') => lang_path('sikessem/ui'),
        ], ['sikessem', 'sikessem:ui', 'sikessem:ui.langs']);

        $this->publishesToGroups([
            sikessem_ui_path('res/views') => resource_path('views/vendor/sikessem/ui'),
        ], ['sikessem', 'sikessem:ui', 'sikessem:ui.views']);
    }

    /**
     * @param  string[]|null  $groups
     */
    protected function publishesToGroups(array $paths, array $groups = null): void
    {
        if (is_null($groups)) {
            $this->publishes($paths);

            return;
        }

        foreach ($groups as $group) {
            $this->publishes($paths, $group);
        }
    }

    protected function registerComponentPath(string $path, string $base = '', bool $anonymous = false): void
    {
        if (file_exists($path)) {
            is_dir($path) ?
            $this->registerComponentDirectory($path, $base, $anonymous) :
            $this->registerComponentFile($path, $base, $anonymous);
        }
    }

    protected function registerComponentDirectory(string $directory, string $base = '', bool $anonymous = false): void
    {
        if ($files = scandir($directory)) {
            $separator = $anonymous ? '.' : '\\';
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..') {
                    $path = "$directory/$file";
                    $this->registerComponentPath($path, is_dir($path) ? "{$base}$separator{$file}" : $base, $anonymous);
                }
            }
        }
    }

    protected function registerComponentFile(string $file, string $base, bool $anonymous = false): void
    {
        if (($anonymous && Str::of($file)->is('*.blade.php')) || (! $anonymous && Str::of($file)->is('*.php'))) {
            $component = basename($file, $anonymous ? '.blade.php' : '.php');
            $separator = $anonymous ? '.' : '\\';
            $base = Str::of($base)->trim($separator);
            if (! $component || $component === 'index') {
                $component = $base;
            } else {
                $component = Str::of("{$base}$separator{$component}")->trim($separator);
            }
            $this->registerComponent($component->toString(), anonymous: $anonymous);
        }
    }

    protected function registerComponent(string $class, string $alias = null, bool $anonymous = false): void
    {
        UIFacade::component($class, $alias, $anonymous);
    }

    /**
     * @param  string[]  $aliases
     */
    protected function registerComponentAliases(string $class, array $aliases = [], string $suffix = null): void
    {
        foreach ($aliases as $alias) {
            $this->registerComponent($class, is_null($suffix) ? $alias : "$alias-$suffix");
        }
    }
}
