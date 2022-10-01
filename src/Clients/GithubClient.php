<?php

declare(strict_types=1);

namespace RafaelYon\PhpInsightsReviewer\Clients;

use Exception;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class GithubClient
{
    private const USER_AGENT = 'PhpInsightsReviewer/1.0 (symfony/http-client)';

    public const REVIEW_COMMENT_LEFT_SIDE = 'LEFT';
    public const REVIEW_COMMENT_RIGHT_SIDE = 'RIGHT';

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
     * Create a review comment for pull request.
     *
     * @return string The comment url
     * 
     * @see https://docs.github.com/en/rest/pulls/comments#create-a-review-comment-for-a-pull-request
     */
    public function createReviewCommentForPR(
        string $fullRepositoryName,
        int $prNumber,
        string $commitId,
        string $comment,
        string $filePath,
        int $line,
        string $side,
        ?int $startLine = null,
        ?string $startSide = null,
        int $timeout = 10
    ): void {
        $body = [
            'body' => $comment,
            'commit_id' => $commitId,
            'path' => $filePath,
            'line' => $line,
            'side' => $side,
        ];

        if ($startLine !== null && $startSide !== null) {
            $body['start_line'] = $startLine;
            $body['start_side'] = $startSide;
        }

        $response = $this->client->request(
            'POST',
            "repos/{$fullRepositoryName}/pulls/{$prNumber}/comments",
            [
                'timeout' => $timeout,
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                ],
                'json' => $body,
            ]
        );

        if ($response->getStatusCode() !== 201) {
            throw new Exception(
                'Can\'t create pull request comment. GitHub return ['
                . $response->getStatusCode()
                . '] "'
                . $response->getContent(false)
                . '".'
            );
        }
    }
}