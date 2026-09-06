{{--
    Overrides horizon::layout.

    Shipping a copy of Horizon's layout would mean re-syncing it on every
    Horizon release, so this renders Horizon's real layout out of the aliased
    horizon-original namespace and splices this package's page into the result.

    Whatever data Horizon's HomeController passed is forwarded verbatim, so a
    Horizon release that adds a variable to its layout keeps working here.
--}}
@php
    $__hdjData = collect(get_defined_vars())
        ->reject(fn ($value, $key) => str_starts_with($key, '__') || in_array($key, ['app', 'errors', 'obLevel'], true))
        ->all();
@endphp
{!! app(\BoringO11y\HorizonDelayedJobs\LayoutDecorator::class)->decorate(
    view('horizon-original::layout', $__hdjData)->render()
) !!}
