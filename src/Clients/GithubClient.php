<?php

declare(strict_types=1);

namespace RafaelYon\PhpInsightsReviewer\Clients;

use Exception;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class GithubClient
{
    public const REVIEW_COMMENT_LEFT_SIDE = 'LEFT';
    public const REVIEW_COMMENT_RIGHT_SIDE = 'RIGHT';

    public const REVIEW_EVENT_ACTION_APPROVE = 'APPROVE';
    public const REVIEW_EVENT_ACTION_COMMENT = 'COMMENT';
    public const REVIEW_EVENT_ACTION_REQUEST_CHANGES = 'REQUEST_CHANGES';

    private const USER_AGENT = 'PhpInsightsReviewer/1.0 (symfony/http-client)';

    private HttpClientInterface $client;

    public function __construct(
        string $apiUrl,
        string $apiBearerToken
    ) {
        $this->client = HttpClient::createForBaseUri(
            $apiUrl,
            [
                'auth_bearer' => $apiBearerToken,
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                ],
            ]
        );
    }

    /**
     * Create a review comment for pull request (PR).
     *
     * @see https://docs.github.com/en/rest/pulls/comments#create-a-review-comment-for-a-pull-request
     *
     * @throws Exception
     */
    public function createPullRequestReviewComment(
        string $fullRepositoryName,
        int $pullRequestNumber,
        FileComment $comment,
        int $timeout = 10
    ): void {
        $this->request(
            $timeout,
            'POST',
            "repos/{$fullRepositoryName}/pulls/{$pullRequestNumber}/comments",
            [
                'json' => $comment->toArray(),
            ],
            201
        );
    }

    /**
     * Create a review for pull request (PR).
     *
     * @see https://docs.github.com/en/rest/pulls/reviews#create-a-review-for-a-pull-request
     * 
     * @param null|array<int, FileComment> $filesComments
     *
     * @throws Exception
     */
    public function createPullRequestReview(
        string $fullRepositoryName,
        int $pullRequestNumber,
        string $commitId,
        string $event,
        string $body,
        ?array $filesComments = null,
        int $timeout = 10
    ): void {
        $requestBody = [
            'commit_id' => $commitId,
            'event' => $event,
            'body' => $body,
        ];

        if ($filesComments !== null && count($filesComments) > 0) {
            $requestBody['comments'] = array_map(
                static function (FileComment $comment): array {
                    return $comment->toArray();
                },
                $filesComments
            );
        }

        $this->request(
            $timeout,
            'POST',
            "repos/{$fullRepositoryName}/pulls/{$pullRequestNumber}/reviews",
            [
                'json' => $requestBody,
            ]
        );
    }

    /**
     * @throws Exception
     */
    private function request(
        int $timeout,
        string $method,
        string $url,
        array $options = [],
        int $expectedStatusCode = 200
    ): ResponseInterface {
        $options['timeout'] = $timeout;

        if (! isset($options['headers'])) {
            $options['headers'] = [];
        }

        if (! isset($options['headers']['Accept'])) {
            $options['headers']['Accept'] = 'application/vnd.github+json';
        }

        $response = $this->client->request($method, $url, $options);

        if ($response->getStatusCode() !== $expectedStatusCode) {
            throw new Exception(
                'Can\'t create pull request comment. GitHub return ['
                . $response->getStatusCode()
                . '] "'
                . $response->getContent(false)
                . '".'
            );
        }

        return $response;
    }
}