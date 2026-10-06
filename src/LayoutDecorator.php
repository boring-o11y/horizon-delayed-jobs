<?php

namespace BoringO11y\HorizonDelayedJobs;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Js;

/**
 * Adds this package's page to Horizon's rendered dashboard.
 *
 * Horizon inlines a compiled Vue bundle and offers no place to register a
 * route, a screen or an asset, so the page is added by splicing into the
 * rendered layout: a mount point, a sidebar link, and this package's own
 * script and styles.
 *
 * Every splice is optional. Horizon can change its markup in any release, so a
 * missing anchor logs what the dashboard will be without and leaves the rest
 * alone — a dashboard short one link beats a dashboard that will not render.
 */
class LayoutDecorator
{
    /**
     * The element the page is mounted into.
     */
    public const PAGE_ID = 'hdj-page';

    /**
     * Where the page mounts: Horizon's router outlet.
     *
     * The mount lands inside #horizon, which Vue uses as its in-DOM template,
     * so it compiles to a static node that Vue renders once and never patches.
     * On this package's own path Horizon's router matches nothing, renders an
     * empty outlet, and the mount is the only content in the column.
     */
    protected const ROUTER_VIEW_ANCHOR = '<router-view></router-view>';

    /**
     * Where the sidebar link goes: Horizon's nav list.
     */
    protected const NAV_ANCHOR = '<ul class="nav flex-column">';

    protected const NAV_CLOSE = '</ul>';

    protected const BODY_ANCHOR = '</body>';

    public function __construct(protected Config $config) {}

    /**
     * Splice this package's additions into the rendered layout.
     */
    public function decorate(string $html): string
    {
        // The routes are what the page talks to, and a route cache built while
        // the package was off, or before it was installed, will not have them.
        // Without them there is no page to add, and asking for their URLs
        // would throw and take Horizon's whole dashboard down with it.
        if (! Route::has('horizon-delayed-jobs.index')) {
            Log::warning(
                'horizon-delayed-jobs is enabled but its routes are not registered, so the ' . $this->label() . ' page will not be shown. ' .
                'If routes are cached, run "php artisan route:cache" again.'
            );

            return $html;
        }

        $html = $this->injectMount($html);
        $html = $this->injectNavItem($html);

        return $this->injectAssets($html);
    }

    /**
     * Add the page's mount point after Horizon's router outlet.
     */
    protected function injectMount(string $html): string
    {
        return $this->patch(
            $html,
            self::ROUTER_VIEW_ANCHOR,
            PHP_EOL . '<div id="' . self::PAGE_ID . '"></div>',
            'the ' . $this->label() . ' page will not be shown'
        );
    }

    /**
     * Add the sidebar link, last in Horizon's nav.
     *
     * It has to be a plain anchor. The nav is inside #horizon, so Vue compiles
     * whatever is placed there, and a <router-link> to a route the compiled
     * bundle has never heard of resolves to nothing. A real href navigates.
     */
    protected function injectNavItem(string $html): string
    {
        $missing = 'the ' . $this->label() . ' link will be missing from the sidebar';

        $start = strpos($html, self::NAV_ANCHOR);

        if ($start === false) {
            $this->warn(self::NAV_ANCHOR, $missing);

            return $html;
        }

        return $this->patch(
            $html,
            self::NAV_CLOSE,
            $this->navItem() . PHP_EOL,
            $missing,
            before: true,
            from: $start
        );
    }

    /**
     * Add the styles and script before the closing body tag.
     */
    protected function injectAssets(string $html): string
    {
        $settings = Js::from($this->settings());

        $css = $this->asset('css/delayed-jobs.css');
        $js = $this->asset('js/delayed-jobs.js');

        $assets = <<<HTML

        <style>{$css}</style>
        <script>
            window.HorizonDelayedJobs = {$settings};
            {$js}
        </script>
        HTML;

        return $this->patch($html, self::BODY_ANCHOR, $assets, 'the ' . $this->label() . ' page will not load', before: true);
    }

    /**
     * Build the sidebar link, mirroring the markup of Horizon's own items.
     */
    protected function navItem(): string
    {
        $href = e($this->pageUrl());
        $label = e($this->label());

        return <<<HTML
        <li class="nav-item">
            <a href="{$href}" class="nav-link d-flex align-items-center" data-hdj-nav>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.5 2.5a1 1 0 001.414-1.414L11 9.586V6z" clip-rule="evenodd" />
                </svg>
                <span>{$label}</span>
            </a>
        </li>
        HTML;
    }

    /**
     * The values the page's script needs.
     *
     * URLs come from the named routes rather than being built in the browser,
     * so the page keeps working behind a reverse proxy, on a moved dashboard
     * path, or on a dashboard served from its own domain.
     *
     * @return array<string, mixed>
     */
    protected function settings(): array
    {
        // Whether the page offers Run now follows the routes that were actually
        // registered, not the config, so the two cannot disagree under a stale
        // route cache and leave buttons with nowhere to post.
        $performUrl = Route::has('horizon-delayed-jobs.perform-many') ? route('horizon-delayed-jobs.perform-many') : null;

        return [
            'pageId' => self::PAGE_ID,
            'pagePath' => (string) parse_url($this->pageUrl(), PHP_URL_PATH),
            'indexUrl' => route('horizon-delayed-jobs.index'),
            'performUrl' => $performUrl,
            'label' => $this->label(),
            'pollInterval' => (int) $this->config->get('horizon-delayed-jobs.poll_interval'),
            'perPage' => (int) $this->config->get('horizon-delayed-jobs.per_page'),
            'performNow' => $performUrl !== null && (bool) $this->config->get('horizon-delayed-jobs.perform_now'),
        ];
    }

    /**
     * The absolute URL of this package's page, on Horizon's own catch-all.
     */
    protected function pageUrl(): string
    {
        return route('horizon.index', ['view' => trim((string) $this->config->get('horizon-delayed-jobs.path'), '/')]);
    }

    protected function label(): string
    {
        return (string) $this->config->get('horizon-delayed-jobs.label');
    }

    /**
     * Read one of this package's built assets.
     */
    protected function asset(string $path): string
    {
        return (string) file_get_contents(__DIR__ . '/../resources/' . $path);
    }

    /**
     * Splice content into the layout at an anchor, or warn and leave it alone.
     *
     * $from is where to start looking, which is how one anchor is located
     * relative to an earlier one.
     */
    protected function patch(
        string $html,
        string $anchor,
        string $insert,
        string $missing,
        bool $before = false,
        int $from = 0,
    ): string {
        $position = strpos($html, $anchor, $from);

        if ($position === false) {
            $this->warn($anchor, $missing);

            return $html;
        }

        return substr_replace($html, $insert, $before ? $position : $position + strlen($anchor), 0);
    }

    protected function warn(string $anchor, string $missing): void
    {
        Log::warning(
            "horizon-delayed-jobs could not find \"{$anchor}\" in Horizon's layout, so {$missing}. " .
            'This usually means Horizon changed its dashboard markup.'
        );
    }
}
