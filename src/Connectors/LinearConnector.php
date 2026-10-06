<?php

declare(strict_types=1);

namespace Uccello\LinearConnector\Connectors;

use Uccello\Core\Connectors\Contracts\ConnectorOperations;
use Uccello\Core\Connectors\Data\CanonicalEntity;
use Uccello\Core\Connectors\Data\ConnectorApiKeyField;
use Uccello\Core\Connectors\Data\ConnectorAuthCapabilities;
use Uccello\Core\Connectors\Data\ConnectorFieldSchema;
use Uccello\Core\Connectors\Data\ConnectorObjectSchema;
use Uccello\Core\Connectors\Data\PollResult;
use Uccello\Core\Connectors\Data\PushResult;
use Uccello\Core\Connectors\IngestMethod;
use Uccello\Core\Contracts\Tenant;
use Uccello\Hub\Connectors\AbstractConnector;
use Uccello\Hub\Connectors\Concerns\HasOAuthAuthentication;

/**
 * Inbound connector for Linear (issues and projects) over its GraphQL API.
 * Each workspace connects its own Linear account, either with an OAuth2 app
 * authorization or with a personal API key. Objects are pulled by polling with an
 * `updatedAt` watermark; on-demand operations (list, create, comment) are exposed
 * as governed `connector.linear.{op}` capabilities.
 */
class LinearConnector extends AbstractConnector implements ConnectorOperations
{
    use HasOAuthAuthentication;

    private const string OBJECT_ISSUE = 'issue';

    private const string OBJECT_PROJECT = 'project';

    private const int PAGE_SIZE = 50;

    private const string ISSUE_NODE = 'id identifier title description priority priorityLabel url createdAt updatedAt dueDate completedAt '
        .'state { name type } assignee { name email } creator { name } team { key name } project { name } labels { nodes { name } }';

    private const string PROJECT_NODE = 'id name description state progress url startDate targetDate createdAt updatedAt lead { name }';

    public function slug(): string
    {
        return 'linear';
    }

    public function name(): string
    {
        return 'Linear';
    }

    public function defaultPollIntervalMinutes(): ?int
    {
        return 5;
    }

    public function authCapabilities(): ConnectorAuthCapabilities
    {
        return new ConnectorAuthCapabilities(
            supportsApiKey: true,
            supportsOAuth: true,
            apiKeyLabel: trans('linear::ui.auth.api_key_label'),
            oauthConnectLabel: trans('linear::ui.auth.oauth_connect'),
            apiKeyFields: [
                new ConnectorApiKeyField('api_key', trans('linear::ui.auth.api_key_field'), secret: true),
            ],
        );
    }

    public function getObjectSchemas(): array
    {
        return [
            new ConnectorObjectSchema(
                slug: self::OBJECT_ISSUE,
                label: trans('linear::ui.objects.issue'),
                fields: [
                    $this->field(self::OBJECT_ISSUE, 'identifier'),
                    $this->field(self::OBJECT_ISSUE, 'title'),
                    $this->field(self::OBJECT_ISSUE, 'description'),
                    $this->field(self::OBJECT_ISSUE, 'state', 'select'),
                    $this->field(self::OBJECT_ISSUE, 'state_type', 'select'),
                    $this->field(self::OBJECT_ISSUE, 'priority', 'number'),
                    $this->field(self::OBJECT_ISSUE, 'priority_label', 'select'),
                    $this->field(self::OBJECT_ISSUE, 'assignee'),
                    $this->field(self::OBJECT_ISSUE, 'assignee_email', 'email'),
                    $this->field(self::OBJECT_ISSUE, 'creator'),
                    $this->field(self::OBJECT_ISSUE, 'team'),
                    $this->field(self::OBJECT_ISSUE, 'team_name'),
                    $this->field(self::OBJECT_ISSUE, 'project'),
                    $this->field(self::OBJECT_ISSUE, 'labels'),
                    $this->field(self::OBJECT_ISSUE, 'due_date', 'date'),
                    $this->field(self::OBJECT_ISSUE, 'created_at', 'date'),
                    $this->field(self::OBJECT_ISSUE, 'updated_at', 'date'),
                    $this->field(self::OBJECT_ISSUE, 'completed_at', 'date'),
                    $this->field(self::OBJECT_ISSUE, 'url'),
                ],
                ingestMethods: [IngestMethod::Polling],
                supportsOutbound: false,
            ),
            new ConnectorObjectSchema(
                slug: self::OBJECT_PROJECT,
                label: trans('linear::ui.objects.project'),
                fields: [
                    $this->field(self::OBJECT_PROJECT, 'name'),
                    $this->field(self::OBJECT_PROJECT, 'description'),
                    $this->field(self::OBJECT_PROJECT, 'state', 'select'),
                    $this->field(self::OBJECT_PROJECT, 'progress', 'number'),
                    $this->field(self::OBJECT_PROJECT, 'lead'),
                    $this->field(self::OBJECT_PROJECT, 'start_date', 'date'),
                    $this->field(self::OBJECT_PROJECT, 'target_date', 'date'),
                    $this->field(self::OBJECT_PROJECT, 'created_at', 'date'),
                    $this->field(self::OBJECT_PROJECT, 'updated_at', 'date'),
                    $this->field(self::OBJECT_PROJECT, 'url'),
                ],
                ingestMethods: [IngestMethod::Polling],
                supportsOutbound: false,
            ),
        ];
    }

    public function supportsImportFromDate(): bool
    {
        return true;
    }

    public function initialCursorFromDate(\DateTimeInterface $date): string
    {
        return \DateTimeImmutable::createFromInterface($date)
            ->setTime(0, 0, 0)
            ->format(\DateTimeInterface::ATOM);
    }

    /**
     * Validate the stored credentials with a minimal authenticated query.
     */
    public function authenticate(Tenant $team): void
    {
        $viewer = $this->graphql($team, '{ viewer { id } }');

        if ((string) data_get($viewer, 'viewer.id', '') === '') {
            throw new \RuntimeException('Linear: authentication check returned no viewer.');
        }
    }

    /**
     * Cursor format:
     *   null / ISO-8601   first page, only objects updated at or after that date
     *   {"since","after","max"}   JSON written by this method while paginating
     *
     * The final page returns the highest `updatedAt` seen as the syncToken, which seeds
     * the next cycle. The filter is inclusive (gte) so a boundary object is never missed;
     * the sync engine dedups already-synced rows by their row link.
     */
    public function poll(string $objectType, Tenant $team, ?string $cursor): PollResult
    {
        [$field, $query] = match ($objectType) {
            self::OBJECT_ISSUE => ['issues', $this->issuesQuery()],
            self::OBJECT_PROJECT => ['projects', $this->projectsQuery()],
            default => throw new \InvalidArgumentException("Unknown Linear object type [{$objectType}]."),
        };

        ['since' => $since, 'after' => $after, 'max' => $max] = $this->parseCursor($cursor);

        $data = $this->graphql($team, $query, [
            'first' => self::PAGE_SIZE,
            'after' => $after,
            'filter' => $since !== null ? ['updatedAt' => ['gte' => $since]] : null,
        ]);

        $entities = [];

        foreach ((array) data_get($data, $field.'.nodes', []) as $node) {
            $node = (array) $node;
            $externalId = (string) data_get($node, 'id', '');

            if ($externalId === '') {
                continue;
            }

            $entities[] = $this->makeEntity($objectType, $this->normalize($objectType, $node), $externalId);
            $max = $this->laterDate($max, (string) data_get($node, 'updatedAt', ''));
        }

        $endCursor = (string) data_get($data, $field.'.pageInfo.endCursor', '');

        if ((bool) data_get($data, $field.'.pageInfo.hasNextPage', false) && $endCursor !== '') {
            return new PollResult(
                entities: $entities,
                nextCursor: json_encode(['since' => $since, 'after' => $endCursor, 'max' => $max], JSON_THROW_ON_ERROR),
                hasMore: true,
            );
        }

        return new PollResult(entities: $entities, nextCursor: null, hasMore: false, syncToken: $max ?? $since);
    }

    public function normalizeInbound(array $payload, string $objectType): CanonicalEntity
    {
        $externalId = (string) data_get($payload, 'id', '');

        return $this->makeEntity($objectType, $this->normalize($objectType, $payload), $externalId !== '' ? $externalId : null);
    }

    /**
     * Inbound-only: nothing is ever sent back to Linear through the sync engine.
     */
    public function normalizeOutbound(array $connectorData, string $objectType): array
    {
        return [];
    }

    public function push(CanonicalEntity $entity, string $objectType, Tenant $team): PushResult
    {
        return $this->pushFail('The Linear connector is read-only.');
    }

    // Operations (workflow / chat / MCP nodes)

    /**
     * @return array<string, array{description: string, rules: array<string, mixed>, sensitive: bool}>
     */
    public function operations(): array
    {
        return [
            'list_teams' => [
                'description' => 'List the Linear teams of this workspace (id, key, name). Use the returned id with create_issue or list_issues.',
                'rules' => [],
                'sensitive' => true,
            ],
            'list_issues' => [
                'description' => 'List recent Linear issues, most recently updated first. Optionally filter by "team_id" and cap the count with "limit" (default 20).',
                'rules' => [
                    'team_id' => ['sometimes', 'nullable', 'string', 'max:120'],
                    'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
                ],
                'sensitive' => true,
            ],
            'create_issue' => [
                'description' => 'Create a Linear issue in a team. "priority" is 0 (none), 1 (urgent), 2 (high), 3 (medium) or 4 (low).',
                'rules' => [
                    'team_id' => ['required', 'string', 'max:120'],
                    'title' => ['required', 'string', 'max:255'],
                    'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
                    'priority' => ['sometimes', 'integer', 'min:0', 'max:4'],
                ],
                'sensitive' => true,
            ],
            'add_comment' => [
                'description' => 'Add a comment to a Linear issue, identified by its id or its identifier (e.g. ENG-123).',
                'rules' => [
                    'issue_id' => ['required', 'string', 'max:120'],
                    'body' => ['required', 'string', 'max:20000'],
                ],
                'sensitive' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function executeOperation(string $key, array $input, Tenant $tenant): array
    {
        if ($this->bindOperationConnection($tenant, isset($input['connection']) ? (string) $input['connection'] : null) === null) {
            return ['ok' => false, 'error' => ['code' => 'connection_unresolved', 'message' => 'No single Linear connection; specify one.']];
        }

        return match ($key) {
            'list_teams' => $this->opListTeams($tenant),
            'list_issues' => $this->opListIssues($input, $tenant),
            'create_issue' => $this->opCreateIssue($input, $tenant),
            'add_comment' => $this->opAddComment($input, $tenant),
            default => ['ok' => false, 'error' => ['code' => 'unknown_operation', 'message' => $key]],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function opListTeams(Tenant $team): array
    {
        $data = $this->graphql($team, '{ teams { nodes { id key name } } }');
        $teams = array_values((array) data_get($data, 'teams.nodes', []));

        return ['ok' => true, 'result' => ['teams' => $teams, 'count' => count($teams)]];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function opListIssues(array $input, Tenant $team): array
    {
        $limit = max(1, min((int) ($input['limit'] ?? 20), 100));
        $teamId = trim((string) ($input['team_id'] ?? ''));

        $data = $this->graphql($team, $this->issuesQuery(), [
            'first' => $limit,
            'after' => null,
            'filter' => $teamId !== '' ? ['team' => ['id' => ['eq' => $teamId]]] : null,
        ]);

        $issues = array_map(
            fn ($node): array => $this->normalizeIssue((array) $node),
            array_values((array) data_get($data, 'issues.nodes', [])),
        );

        return ['ok' => true, 'result' => ['issues' => $issues, 'count' => count($issues)]];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function opCreateIssue(array $input, Tenant $team): array
    {
        $payload = array_filter([
            'teamId' => trim((string) ($input['team_id'] ?? '')),
            'title' => trim((string) ($input['title'] ?? '')),
            'description' => isset($input['description']) ? (string) $input['description'] : null,
            'priority' => isset($input['priority']) ? (int) $input['priority'] : null,
        ], static fn ($value): bool => $value !== null && $value !== '');

        if (! isset($payload['teamId'], $payload['title'])) {
            return ['ok' => false, 'error' => ['code' => 'missing_input', 'message' => 'team_id and title are required.']];
        }

        $data = $this->graphql(
            $team,
            'mutation($input: IssueCreateInput!) { issueCreate(input: $input) { success issue { id identifier title url } } }',
            ['input' => $payload],
        );

        if (! (bool) data_get($data, 'issueCreate.success', false)) {
            return ['ok' => false, 'error' => ['code' => 'create_failed', 'message' => 'Linear refused to create the issue.']];
        }

        return ['ok' => true, 'result' => (array) data_get($data, 'issueCreate.issue', [])];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function opAddComment(array $input, Tenant $team): array
    {
        $issueId = trim((string) ($input['issue_id'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));

        if ($issueId === '' || $body === '') {
            return ['ok' => false, 'error' => ['code' => 'missing_input', 'message' => 'issue_id and body are required.']];
        }

        $data = $this->graphql(
            $team,
            'mutation($input: CommentCreateInput!) { commentCreate(input: $input) { success comment { id url } } }',
            ['input' => ['issueId' => $issueId, 'body' => $body]],
        );

        if (! (bool) data_get($data, 'commentCreate.success', false)) {
            return ['ok' => false, 'error' => ['code' => 'comment_failed', 'message' => 'Linear refused to add the comment.']];
        }

        return ['ok' => true, 'result' => (array) data_get($data, 'commentCreate.comment', [])];
    }

    // Normalization

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function normalize(string $objectType, array $node): array
    {
        return match ($objectType) {
            self::OBJECT_ISSUE => $this->normalizeIssue($node),
            self::OBJECT_PROJECT => $this->normalizeProject($node),
            default => $node,
        };
    }

    /**
     * @param  array<string, mixed>  $issue
     * @return array<string, mixed>
     */
    private function normalizeIssue(array $issue): array
    {
        $labels = array_map(
            static fn ($label): string => (string) data_get($label, 'name', ''),
            (array) data_get($issue, 'labels.nodes', []),
        );

        return [
            'identifier' => (string) data_get($issue, 'identifier', ''),
            'title' => (string) data_get($issue, 'title', ''),
            'description' => (string) data_get($issue, 'description', ''),
            'state' => (string) data_get($issue, 'state.name', ''),
            'state_type' => (string) data_get($issue, 'state.type', ''),
            'priority' => data_get($issue, 'priority'),
            'priority_label' => (string) data_get($issue, 'priorityLabel', ''),
            'assignee' => (string) data_get($issue, 'assignee.name', ''),
            'assignee_email' => (string) data_get($issue, 'assignee.email', ''),
            'creator' => (string) data_get($issue, 'creator.name', ''),
            'team' => (string) data_get($issue, 'team.key', ''),
            'team_name' => (string) data_get($issue, 'team.name', ''),
            'project' => (string) data_get($issue, 'project.name', ''),
            'labels' => implode(', ', array_filter($labels, static fn (string $name): bool => $name !== '')),
            'due_date' => (string) data_get($issue, 'dueDate', ''),
            'created_at' => (string) data_get($issue, 'createdAt', ''),
            'updated_at' => (string) data_get($issue, 'updatedAt', ''),
            'completed_at' => (string) data_get($issue, 'completedAt', ''),
            'url' => (string) data_get($issue, 'url', ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $project
     * @return array<string, mixed>
     */
    private function normalizeProject(array $project): array
    {
        return [
            'name' => (string) data_get($project, 'name', ''),
            'description' => (string) data_get($project, 'description', ''),
            'state' => (string) data_get($project, 'state', ''),
            'progress' => data_get($project, 'progress'),
            'lead' => (string) data_get($project, 'lead.name', ''),
            'start_date' => (string) data_get($project, 'startDate', ''),
            'target_date' => (string) data_get($project, 'targetDate', ''),
            'created_at' => (string) data_get($project, 'createdAt', ''),
            'updated_at' => (string) data_get($project, 'updatedAt', ''),
            'url' => (string) data_get($project, 'url', ''),
        ];
    }

    private function field(string $object, string $slug, string $type = 'text'): ConnectorFieldSchema
    {
        return new ConnectorFieldSchema($slug, trans("linear::ui.fields.{$object}.{$slug}"), $type);
    }

    private function issuesQuery(): string
    {
        return 'query($first: Int!, $after: String, $filter: IssueFilter) { issues(first: $first, after: $after, filter: $filter, orderBy: updatedAt, includeArchived: true) '
            .'{ nodes { '.self::ISSUE_NODE.' } pageInfo { hasNextPage endCursor } } }';
    }

    private function projectsQuery(): string
    {
        return 'query($first: Int!, $after: String, $filter: ProjectFilter) { projects(first: $first, after: $after, filter: $filter, orderBy: updatedAt, includeArchived: true) '
            .'{ nodes { '.self::PROJECT_NODE.' } pageInfo { hasNextPage endCursor } } }';
    }

    // Cursor

    /**
     * @return array{since: ?string, after: ?string, max: ?string}
     */
    private function parseCursor(?string $cursor): array
    {
        $empty = ['since' => null, 'after' => null, 'max' => null];

        if ($cursor === null || $cursor === '') {
            return $empty;
        }

        if (! str_starts_with($cursor, '{')) {
            return ['since' => $cursor, 'after' => null, 'max' => null];
        }

        $decoded = json_decode($cursor, true);

        if (! is_array($decoded)) {
            return $empty;
        }

        return [
            'since' => isset($decoded['since']) ? (string) $decoded['since'] : null,
            'after' => isset($decoded['after']) ? (string) $decoded['after'] : null,
            'max' => isset($decoded['max']) ? (string) $decoded['max'] : null,
        ];
    }

    /**
     * Keep the most recent of two ISO-8601 dates (timezone-aware), preserving the raw string.
     */
    private function laterDate(?string $a, ?string $b): ?string
    {
        if ($b === null || $b === '') {
            return $a === '' ? null : $a;
        }

        if ($a === null || $a === '') {
            return $b;
        }

        return (strtotime($b) ?: 0) > (strtotime($a) ?: 0) ? $b : $a;
    }

    // HTTP and auth plumbing

    /**
     * Run a GraphQL operation for the team's connection. On a 401, an OAuth connection
     * with a refresh token is refreshed and the request retried once.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function graphql(Tenant $team, string $query, array $variables = []): array
    {
        $client = new LinearClient;

        try {
            return $client->query($this->authorization($team), $query, $variables);
        } catch (LinearUnauthorizedException $e) {
            if (! $this->canRefresh($team)) {
                throw new \RuntimeException('Linear: credentials rejected, reconnect the account.', 0, $e);
            }

            $this->refreshAccessToken($team);

            return $client->query($this->authorization($team), $query, $variables);
        }
    }

    /**
     * The Authorization header value for the connection: the raw key for a personal API
     * key, "Bearer {token}" for OAuth. Always resolved from the tenant's own connection.
     */
    private function authorization(Tenant $team): string
    {
        $row = $this->resolveConnection($team);

        if ($row === null) {
            throw new \RuntimeException('Linear: connect the account first.');
        }

        $type = (string) ($row->credentials['type'] ?? '');

        if ($type === 'api_key') {
            $key = trim((string) ($row->credentials['api_key'] ?? ''));

            if ($key === '') {
                throw new \RuntimeException('Linear: API key missing.');
            }

            return $key;
        }

        if ($type === 'oauth2') {
            $credentials = $this->loadCredentials($team);

            if ($credentials === null || $credentials['access_token'] === '') {
                throw new \RuntimeException('Linear: access token missing, reconnect the account.');
            }

            if ($this->isTokenExpired($credentials) && $credentials['refresh_token'] !== '') {
                return 'Bearer '.$this->refreshAccessToken($team);
            }

            return 'Bearer '.$credentials['access_token'];
        }

        throw new \RuntimeException('Linear: unsupported credential type.');
    }

    private function canRefresh(Tenant $team): bool
    {
        $credentials = $this->loadCredentials($team);

        return $credentials !== null && $credentials['refresh_token'] !== '';
    }

    /**
     * @return array{client_id: string, client_secret: string, scopes: string[], token_url: string, auth_url: string}
     */
    protected function getOAuthConfig(Tenant $team): array
    {
        return [
            'client_id' => (string) config('services.linear.client_id', ''),
            'client_secret' => (string) config('services.linear.client_secret', ''),
            // Linear expects a comma separated scope list, sent here as a single entry.
            'scopes' => ['read,write'],
            'token_url' => 'https://api.linear.app/oauth/token',
            'auth_url' => 'https://linear.app/oauth/authorize',
        ];
    }
}
