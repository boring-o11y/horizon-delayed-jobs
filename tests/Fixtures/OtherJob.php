<?php

namespace BoringO11y\HorizonDelayedJobs\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class OtherJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue;

    public function handle()
    {
        //
    }
}
