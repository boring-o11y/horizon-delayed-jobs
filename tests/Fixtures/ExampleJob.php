<?php

namespace BoringO11y\HorizonDelayedJobs\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ExampleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue;

    public $tries = 3;

    public function __construct(public int $id = 1)
    {
    }

    public function handle()
    {
        //
    }
}
