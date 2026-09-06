<?php

namespace BoringO11y\HorizonDelayedJobs;

use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Http\Middleware\Authenticate;

class HorizonDelayedJobsServiceProvider extends ServiceProvider
{
    /**
     * Register the package's services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/horizon-delayed-jobs.php', 'horizon-delayed-jobs');

        // The bindings are registered unconditionally: they are lazy, so an
        // installation that has the package turned off never resolves them.
        // Whether the package does anything is decided at each point of use,
        // where the configuration is guaranteed to have been loaded.
        $this->app->singleton(Queues::class);
        $this->app->singleton(QueueKeys::class);
        $this->app->singleton(DelayedJobs::class);
        $this->app->singleton(PerformNow::class);
        $this->app->singleton(LayoutDecorator::class);

        // Routes are registered from a booting callback rather than from boot():
        // by then every provider has registered, so Horizon's config is merged,
        // but no provider has booted, so Horizon has not yet declared the
        // catch-all route that would otherwise swallow these paths.
        $this->app->isBooted()
            ? $this->registerRoutes()
            : $this->app->booting(fn () => $this->registerRoutes());
    }

    /**
     * Bootstrap the package.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/horizon-delayed-jobs.php' => $this->app->configPath('horizon-delayed-jobs.php'),
            ], 'horizon-delayed-jobs-config');
        }

        if (! $this->enabled()) {
            return;
        }

        // Deferred to after every provider has booted: Horizon registers its
        // own view namespace in its boot(), and the override has to find that
        // namespace in order to alias it and render the real layout from it.
        $this->app->booted(function () {
            $this->callAfterResolving('view', fn ($view) => $this->registerViewOverride($view));
        });
    }

    /**
     * Register the package's routes inside Horizon's own group.
     *
     * @return void
     */
    protected function registerRoutes()
    {
        if (! $this->enabled()) {
            return;
        }

        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        Route::group([
            'domain' => config('horizon.domain', null),
            'prefix' => config('horizon.path'),
            'middleware' => $this->middleware(),
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/delayed-jobs.php');
        });
    }

    /**
     * The middleware stack these routes run through.
     *
     * This mirrors what a Horizon route gets: the dashboard's configured
     * middleware from the route group, and the Authenticate middleware Horizon
     * applies through its base controller. Applying it here rather than by
     * extending that controller keeps the package off an internal whose shape
     * has changed across Laravel versions.
     *
     * @return array<int, string>
     */
    protected function middleware()
    {
        return array_values(array_unique(array_merge(
            (array) config('horizon.middleware', 'web'),
            [Authenticate::class]
        )));
    }

    /**
     * Take over the horizon::layout view, keeping the original reachable.
     *
     * Horizon's own view path stays available under a second namespace, which
     * is what lets the override render the real layout instead of a copy that
     * would need re-syncing on every Horizon release.
     *
     * @param  \Illuminate\View\Factory  $view
     * @return void
     */
    protected function registerViewOverride($view)
    {
        $finder = $view->getFinder();
        $hints = $finder->getHints();

        if (! isset($hints['horizon'])) {
            return;
        }

        $finder->addNamespace('horizon-original', $hints['horizon']);
        $finder->prependNamespace('horizon', __DIR__.'/../resources/views');
    }

    /**
     * @return bool
     */
    protected function enabled()
    {
        return (bool) config('horizon-delayed-jobs.enabled', true);
    }
}
