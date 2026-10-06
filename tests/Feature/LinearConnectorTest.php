<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Uccello\Hub\Models\ConnectorCredential;
use Uccello\LinearConnector\Connectors\LinearConnector;
use Uccello\Testing\Models\User;

beforeEach(function (): void {
    Sleep::fake(); // retry backoff must not actually sleep in tests
});

/**
 * @param  array<string, mixed>  $credentials
 */
function linearTeamWithCredential(array $credentials = ['type' => 'api_key', 'api_key' => 'lin_api_abc']): object
{
    $team = User::factory()->create()->currentTeam;

    ConnectorCredential::query()->create([
        'team_id' => $team->id,
        'connector_slug' => 'linear',
        'credentials' => $credentials,
    ]);

    return $team;
}

/**
 * @param  list<array<string, mixed>>  $nodes
 * @return array<string, mixed>
 */
function linearIssuesPage(array $nodes, bool $hasNextPage = false, ?string $endCursor = null): array
{
    return ['data' => ['issues' => [
        'nodes' => $nodes,
        'pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $endCursor],
    ]]];
}

test('linear issue poll hydrates entities and advances the watermark', function (): void {
    Http::fake(['api.linear.app/graphql' => Http::response(linearIssuesPage([
        [
            'id' => 'iss-1', 'identifier' => 'ENG-1', 'title' => 'First', 'priority' => 2, 'updatedAt' => '2026-03-01T10:00:00.000Z',
            'state' => ['name' => 'In Progress', 'type' => 'started'],
            'team' => ['key' => 'ENG', 'name' => 'Engineering'],
            'labels' => ['nodes' => [['name' => 'bug'], ['name' => 'api']]],
        ],
        ['id' => 'iss-2', 'identifier' => 'ENG-2', 'title' => 'Second', 'updatedAt' => '2026-03-05T10:00:00.000Z'],
    ]))]);

    $result = app(LinearConnector::class)->poll('issue', linearTeamWithCredential(), null);

    expect($result->entities)->toHaveCount(2)
        ->and($result->entities[0]->externalId)->toBe('iss-1')
        ->and($result->entities[0]->data['identifier'])->toBe('ENG-1')
        ->and($result->entities[0]->data['state'])->toBe('In Progress')
        ->and($result->entities[0]->data['labels'])->toBe('bug, api')
        ->and($result->hasMore)->toBeFalse()
        ->and($result->nextCursor)->toBeNull()
        ->and($result->syncToken)->toBe('2026-03-05T10:00:00.000Z');
});

test('linear poll sends the since date as an inclusive updatedAt filter', function (): void {
    Http::fake(['api.linear.app/graphql' => Http::response(linearIssuesPage([]))]);

    $result = app(LinearConnector::class)->poll('issue', linearTeamWithCredential(), '2026-01-01T00:00:00+00:00');

    Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'variables.filter.updatedAt.gte') === '2026-01-01T00:00:00+00:00');

    // Nothing new: the watermark is held at the window start.
    expect($result->entities)->toBe([])->and($result->syncToken)->toBe('2026-01-01T00:00:00+00:00');
});

test('linear poll paginates with a json cursor and only sets the syncToken on the last page', function (): void {
    Http::fake(['api.linear.app/graphql' => Http::sequence()
        ->push(linearIssuesPage([['id' => 'a', 'updatedAt' => '2026-04-02T00:00:00.000Z']], true, 'cur-1'))
        ->push(linearIssuesPage([['id' => 'b', 'updatedAt' => '2026-04-01T00:00:00.000Z']]))]);

    $team = linearTeamWithCredential();
    $connector = app(LinearConnector::class);

    $first = $connector->poll('issue', $team, '2026-01-01T00:00:00+00:00');

    expect($first->hasMore)->toBeTrue()
        ->and($first->syncToken)->toBeNull()
        ->and(json_decode((string) $first->nextCursor, true))->toBe([
            'since' => '2026-01-01T00:00:00+00:00', 'after' => 'cur-1', 'max' => '2026-04-02T00:00:00.000Z',
        ]);

    $second = $connector->poll('issue', $team, $first->nextCursor);

    Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'variables.after') === 'cur-1');

    expect($second->hasMore)->toBeFalse()->and($second->syncToken)->toBe('2026-04-02T00:00:00.000Z');
});

test('linear poll rejects an unknown object type', function (): void {
    app(LinearConnector::class)->poll('nope', linearTeamWithCredential(), null);
})->throws(InvalidArgumentException::class);

test('linear sends a personal api key raw and an oauth token as bearer', function (): void {
    Http::fake(['api.linear.app/graphql' => Http::response(['data' => ['viewer' => ['id' => 'u1']]])]);

    app(LinearConnector::class)->authenticate(linearTeamWithCredential());

    Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['lin_api_abc']);

    $oauthTeam = linearTeamWithCredential(['type' => 'oauth2', 'access_token' => 'oauth-tok', 'refresh_token' => 'r', 'expires_at' => time() + 3600]);
    app(LinearConnector::class)->authenticate($oauthTeam);

    Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer oauth-tok']);
});

test('linear refreshes an expired oauth token once on 401 then retries', function (): void {
    config()->set('services.linear.client_id', 'cid');
    config()->set('services.linear.client_secret', 'csecret');

    Http::fake([
        'api.linear.app/oauth/token' => Http::response(['access_token' => 'fresh', 'refresh_token' => 'r2', 'expires_in' => 3600]),
        'api.linear.app/graphql' => Http::sequence()
            ->push('unauthorized', 401)
            ->push(['data' => ['viewer' => ['id' => 'u1']]]),
    ]);

    $team = linearTeamWithCredential(['type' => 'oauth2', 'access_token' => 'stale', 'refresh_token' => 'r', 'expires_at' => time() + 3600]);

    app(LinearConnector::class)->authenticate($team);

    Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer fresh']);
});

test('linear does not retry a rejected api key and asks to reconnect', function (): void {
    Http::fake(['api.linear.app/graphql' => Http::response('unauthorized', 401)]);

    app(LinearConnector::class)->authenticate(linearTeamWithCredential());
})->throws(RuntimeException::class, 'reconnect');

test('linear retries a rate limited response then succeeds', function (): void {
    Http::fake(['api.linear.app/graphql' => Http::sequence()
        ->push('slow down', 429)
        ->push(['data' => ['viewer' => ['id' => 'u1']]])]);

    app(LinearConnector::class)->authenticate(linearTeamWithCredential());

    Http::assertSentCount(2);
});

test('linear never uses another tenant credentials', function (): void {
    Http::fake(['api.linear.app/graphql' => Http::response(linearIssuesPage([]))]);

    linearTeamWithCredential(['type' => 'api_key', 'api_key' => 'tenant-a-key']);
    $otherTeam = User::factory()->create()->currentTeam;

    expect(fn () => app(LinearConnector::class)->poll('issue', $otherTeam, null))
        ->toThrow(RuntimeException::class, 'connect the account');

    Http::assertNothingSent();
});

test('linear create_issue operation sends the mutation and returns the issue', function (): void {
    Http::fake(['api.linear.app/graphql' => Http::response(['data' => ['issueCreate' => [
        'success' => true,
        'issue' => ['id' => 'iss-9', 'identifier' => 'ENG-9', 'title' => 'Hello', 'url' => 'https://linear.app/x/issue/ENG-9'],
    ]]])]);

    $team = linearTeamWithCredential();

    $result = app(LinearConnector::class)->executeOperation('create_issue', ['team_id' => 't1', 'title' => 'Hello', 'priority' => 2], $team);

    Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'variables.input.teamId') === 't1'
        && data_get($request->data(), 'variables.input.priority') === 2);

    expect($result['ok'])->toBeTrue()->and($result['result']['identifier'])->toBe('ENG-9');
});

test('linear operations error cleanly when the connection is ambiguous', function (): void {
    $team = linearTeamWithCredential();

    ConnectorCredential::query()->create([
        'team_id' => $team->id,
        'connector_slug' => 'linear',
        'credentials' => ['type' => 'api_key', 'api_key' => 'second'],
    ]);

    $result = app(LinearConnector::class)->executeOperation('list_teams', [], $team);

    expect($result['ok'])->toBeFalse()->and($result['error']['code'])->toBe('connection_unresolved');
});
