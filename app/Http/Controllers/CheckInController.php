<?php

namespace App\Http\Controllers;

use App\Jobs\RecordCheckIn;
use App\Models\Job;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

class CheckInController extends Controller
{
    public function __invoke(Request $request, string $token): Response
    {
        $job = Job::where('check_in_token', $token)->firstOrFail();

        $validator = Validator::make([
            'metadata' => $request->query(),
            'metadata_keys' => array_keys($request->query()),
        ], [
            'metadata' => ['array', 'max:20'],
            'metadata.*' => ['nullable', 'string', 'max:255'],
            'metadata_keys.*' => ['string', 'max:50'],
        ]);

        if ($validator->fails()) {
            return response($validator->errors()->first(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        RecordCheckIn::dispatch($job->id, $request->ip(), now(), $this->metadataFrom($request));

        return response()->noContent(Response::HTTP_OK);
    }

    /**
     * Query string values become check-in metadata, with numeric-looking
     * values stored as numbers so they can be plotted.
     *
     * @return array<string, int|float|string>|null
     */
    private function metadataFrom(Request $request): ?array
    {
        if (! $request->query()) {
            return null;
        }

        return collect($request->query())
            ->map(fn ($value) => is_numeric($value) ? $value + 0 : $value)
            ->all();
    }
}
