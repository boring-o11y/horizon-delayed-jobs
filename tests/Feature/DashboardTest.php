<?php

namespace BoringO11y\HorizonDelayedJobs\Tests\Feature;

use BoringO11y\HorizonDelayedJobs\LayoutDecorator;
use BoringO11y\HorizonDelayedJobs\Tests\TestCase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

class DashboardTest extends TestCase
{
    public function test_the_dashboard_still_renders_horizons_own_layout()
    {
        $html = $this->get('horizon')->assertOk()->getContent();

        $this->assertStringContainsString('<div id="horizon"', $html);
        $this->assertStringContainsString('<router-view></router-view>', $html);
        $this->assertStringContainsString('window.Horizon =', $html);
    }

    public function test_it_adds_the_mount_the_sidebar_link_and_the_script()
    {
        $html = $this->get('horizon')->assertOk()->getContent();

        $this->assertStringContainsString('<div id="hdj-page"></div>', $html);
        $this->assertStringContainsString('data-hdj-nav', $html);
        $this->assertStringContainsString('>Retries</span>', $html);
        $this->assertStringContainsString('window.HorizonDelayedJobs =', $html);
    }

    public function test_the_mount_sits_after_horizons_router_outlet()
    {
        $html = $this->get('horizon')->assertOk()->getContent();

        $this->assertGreaterThan(
            strpos($html, '<router-view></router-view>'),
            strpos($html, '<div id="hdj-page"></div>')
        );
    }

    public function test_the_sidebar_link_points_at_the_configured_path()
    {
        config(['horizon-delayed-jobs.path' => 'waiting', 'horizon-delayed-jobs.label' => 'Waiting']);

        $html = $this->get('horizon')->assertOk()->getContent();

        $this->assertStringContainsString('/horizon/waiting"', $html);
        $this->assertStringContainsString('>Waiting</span>', $html);
    }

    public function test_it_adds_nothing_when_disabled()
    {
        $this->overrides = ['horizon-delayed-jobs.enabled' => false];

        $this->refreshApplication();

        $html = $this->get('horizon')->assertOk()->getContent();

        $this->assertStringNotContainsString('hdj-page', $html);
        $this->assertStringNotContainsString('window.HorizonDelayedJobs', $html);

        // Horizon's catch-all answers every path under its prefix, so "gone"
        // is a route that was never declared rather than a 404.
        $this->assertFalse(Route::has('horizon-delayed-jobs.index'));
    }

    public function test_the_package_path_renders_the_dashboard_shell()
    {
        // Horizon's own catch-all serves the SPA for this path, which is what
        // puts the empty router outlet and this package's mount on screen.
        $html = $this->get('horizon/retries')->assertOk()->getContent();

        $this->assertStringContainsString('<div id="hdj-page"></div>', $html);
    }

    public function test_a_missing_anchor_is_skipped_rather_than_fatal()
    {
        $log = Log::spy();

        $decorated = app(LayoutDecorator::class)->decorate(
            '<html><body><div id="horizon">Horizon moved its markup</div></body></html>'
        );

        // The one anchor that is still there is still patched.
        $this->assertStringContainsString('window.HorizonDelayedJobs =', $decorated);
        $this->assertStringNotContainsString('<div id="hdj-page"></div>', $decorated);
        $this->assertStringNotContainsString('data-hdj-nav', $decorated);

        $log->shouldHaveReceived('warning')->twice();
    }

    public function test_the_dashboard_still_renders_when_the_routes_are_missing()
    {
        // What a route cache built before the package was enabled looks like.
        $this->withoutRoutes(['horizon-delayed-jobs.index', 'horizon-delayed-jobs.perform', 'horizon-delayed-jobs.perform-many']);

        $html = $this->get('horizon')->assertOk()->getContent();

        $this->assertStringNotContainsString('hdj-page', $html);
        $this->assertStringNotContainsString('window.HorizonDelayedJobs', $html);
    }

    public function test_run_now_is_offered_only_when_its_route_exists()
    {
        $this->withoutRoutes(['horizon-delayed-jobs.perform', 'horizon-delayed-jobs.perform-many']);

        $settings = (fn () => $this->settings())->call(app(LayoutDecorator::class));

        $this->assertNull($settings['performUrl']);
        $this->assertFalse($settings['performNow']);
    }

    /**
     * Drop the named routes, as a stale route cache would.
     *
     * @param  array<int, string>  $names
     */
    protected function withoutRoutes(array $names): void
    {
        $routes = new RouteCollection;

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            if (! in_array($route->getName(), $names, true)) {
                $routes->add($route);
            }
        }

        app('router')->setRoutes($routes);
    }
}
