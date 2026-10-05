<?php

use App\Jobs\RecordCheckIn;
use App\Models\Job;
use Illuminate\Support\Facades\Queue;

it('returns 404 and queues nothing when the token does not match any job', function () {
    Queue::fake();

    Job::factory()->create();

    $response = $this->get('/check-in/00000000-0000-0000-0000-000000000000');

    $response->assertNotFound();
    Queue::assertNothingPushed();
});

it('still queues a check-in for a silenced job so forensic history stays complete', function () {
    Queue::fake();

    $job = Job::factory()->silenced()->create();

    $response = $this->get('/check-in/'.$job->check_in_token);

    $response->assertOk();
    Queue::assertPushed(RecordCheckIn::class, fn (RecordCheckIn $queued) => $queued->jobId === $job->id);
});

it('records the check-in against the right job and timestamp when the queued job runs', function () {
    $job = Job::factory()->alerting()->create();
    $at = now()->subSeconds(30)->startOfSecond();

    (new RecordCheckIn($job->id, '198.51.100.7', $at))->handle();

    $job->refresh();

    expect($job->checkIns)->toHaveCount(1)
        ->and($job->checkIns->first()->source_ip)->toBe('198.51.100.7')
        ->and($job->last_checked_in_at->equalTo($at))->toBeTrue()
        ->and($job->alerting_since)->toBeNull();
});

it('queues a check-in job when a job receives a ping at its token URL', function () {
    Queue::fake();

    $job = Job::factory()->create();

    $response = $this->get('/check-in/'.$job->check_in_token);

    $response->assertOk();
    Queue::assertPushed(RecordCheckIn::class, fn (RecordCheckIn $queued) => $queued->jobId === $job->id);
});

it('stores query string values as metadata on the check-in, with numeric values as numbers', function () {
    $job = Job::factory()->create();

    $response = $this->get('/check-in/'.$job->check_in_token.'?files=1234&bytes=5678901&ratio=1.5&status=clean');

    $response->assertOk();
    expect($job->checkIns()->sole()->metadata)->toBe([
        'files' => 1234,
        'bytes' => 5678901,
        'ratio' => 1.5,
        'status' => 'clean',
    ]);
});

it('stores no metadata when the ping has no query string', function () {
    $job = Job::factory()->create();

    $this->get('/check-in/'.$job->check_in_token)->assertOk();

    expect($job->checkIns()->sole()->metadata)->toBeNull();
});

it('rejects oversized or nested metadata with a 422 and queues nothing', function (string $queryString) {
    Queue::fake();

    $job = Job::factory()->create();

    $response = $this->get('/check-in/'.$job->check_in_token.'?'.$queryString);

    $response->assertUnprocessable();
    Queue::assertNothingPushed();
})->with([
    'more than 20 keys' => fn () => http_build_query(array_fill_keys(range('a', 'u'), 1)),
    'a key longer than 50 characters' => fn () => str_repeat('k', 51).'=1',
    'a value longer than 255 characters' => fn () => 'notes='.str_repeat('v', 256),
    'an array value' => 'files[]=1&files[]=2',
]);

it('accepts a bare key with no value', function () {
    $job = Job::factory()->create();

    $this->get('/check-in/'.$job->check_in_token.'?verified')->assertOk();

    expect($job->checkIns()->sole()->metadata)->toBe(['verified' => null]);
});

it('does not start a session or set any cookies when a job pings', function () {
    Queue::fake();

    $job = Job::factory()->create();

    $response = $this->get('/check-in/'.$job->check_in_token);

    $response->assertOk();
    expect($response->headers->getCookies())->toBeEmpty();
});
