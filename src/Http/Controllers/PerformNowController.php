<?php

namespace BoringO11y\HorizonDelayedJobs\Http\Controllers;

use BoringO11y\HorizonDelayedJobs\PerformNow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PerformNowController
{
    public function __construct(protected PerformNow $performer) {}

    /**
     * Promote one delayed job onto its ready queue.
     *
     * A job that is not in any delayed set is a 404 rather than a silent
     * success: by the time someone presses the button the backoff may have
     * expired on its own, and the page should say so instead of implying it
     * did something.
     *
     * @param  string  $id
     * @return JsonResponse
     */
    public function store(Request $request, $id)
    {
        $performed = $this->performer->perform(
            $id,
            $request->input('connection'),
            $request->input('queue')
        );

        return response()->json(
            ['performed' => $performed], $performed ? 200 : 404
        );
    }

    /**
     * Promote each of the given delayed jobs.
     *
     * Each entry of "ids" is an id, or an object of the id with the
     * connection and queue hints the single-job route takes.
     *
     * The page can only select what one page of the listing shows, so a
     * request for more than that is refused rather than left to hold the
     * queue's Redis busy for as long as it likes.
     *
     * @return JsonResponse
     */
    public function storeMany(Request $request)
    {
        $ids = (array) $request->input('ids', []);
        $limit = max(1, (int) config('horizon-delayed-jobs.per_page'));

        if (count($ids) > $limit) {
            return response()->json([
                'message' => "At most {$limit} jobs can be run at once.",
            ], 422);
        }

        $performed = $this->performer->performMany($ids);

        return response()->json([
            'performed' => $performed,
            'count' => count($performed),
        ]);
    }
}
