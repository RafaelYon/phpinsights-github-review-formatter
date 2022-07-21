<?php

declare(strict_types=1);

namespace RafaelYon\PhpInsightsReviewer;

use Exception;
use NunoMaduro\PhpInsights\Application\Console\Contracts\Formatter;
use NunoMaduro\PhpInsights\Domain\Contracts\HasDetails;
use NunoMaduro\PhpInsights\Domain\Details;
use NunoMaduro\PhpInsights\Domain\DetailsComparator;
use NunoMaduro\PhpInsights\Domain\Insights\InsightCollection;
use RafaelYon\PhpInsightsReviewer\Clients\GithubClient;

final class GithubFormatter implements Formatter
{
    private const DIFF_LINES_REGEX = '/\@{2} \-(\d+,?\d+) \+(\d+,?\d+) \@{2}/m';

    private string $repository;
    private int $prNumber;
    private string $commitId;
    private string $pathPrefixToIgnore;

    private GithubClient $client;

    public function __construct()
    {
        $this->repository = $this->getEnvOrFail('GITHUB_REPOSITORY');
        $this->prNumber = (int) $this->getEnvOrFail('GITHUB_PR_NUMBER');
        $this->commitId = $this->getEnvOrFail('GITHUB_COMMIT_ID');
        $this->pathPrefixToIgnore = $this->getEnvOrFail('PATH_PREFIX_TO_IGNORE');

        $this->client = new GithubClient(
            $this->getEnvOrFail('GITHUB_API_URL'),
            $this->getEnvOrFail('GITHUB_TOKEN')
        );
    }

    /**
     * @inheritdoc
     */
    public function format(InsightCollection $insightCollection, array $metrics): void
    {
        $this->writeMetricsInsights($insightCollection, $metrics);
    }

    /**
     * @param array<int, string> $metrics
     */
    private function writeMetricsInsights(
        InsightCollection $insightCollection,
        array $metrics
    ): void {
        $detailsComparator = new DetailsComparator();

        foreach ($metrics as $metricClass) {
            $category = explode('\\', $metricClass);
            $category = $category[count($category) - 2];

            foreach ($insightCollection->allFrom(new $metricClass()) as $insight) {
                if (! $insight->hasIssue()) {
                    continue;
                }

                if (! $insight instanceof HasDetails) {
                    continue;
                }

                $details = $insight->getDetails();
                usort($details, $detailsComparator);

                foreach ($details as $detail) {
                    if (! $detail->hasFile()) {
                        continue;
                    }

                    $fileName = str_replace(
                        $this->pathPrefixToIgnore,
                        '',
                        $detail->getFile()
                    );

                    if ($detail->hasDiff()) {
                        $this->writeDetailDiff($category, $insight->getTitle(), $fileName, $detail);
                    } elseif ($detail->hasLine()) {
                        $this->writeDetail($category, $insight->getTitle(), $fileName, $detail);
                    }
                }
            }
        }
    }

    private function writeDetail(
        string $category,
        string $title,
        string $fileName,
        Details $detail
    ): void {
        $this->client->createReviewCommentForPR(
            $this->repository,
            $this->prNumber,
            $this->commitId,
            implode(
                "\n",
                [
                    '### PHP Insights',
                    "#### [{$category}] {$title}",
                    $detail->getMessage(),
                ]
            ),
            $fileName,
            $detail->getLine(),
            GithubClient::REVIEW_COMMENT_RIGHT_SIDE
        );
    }

    private function writeDetailDiff(
        string $category,
        string $title,
        string $fileName,
        Details $detail
    ): void {
        $diffs = $this->extractDiffLines($detail->getDiff());
        foreach ($diffs as $diff) {
            $this->client->createReviewCommentForPR(
                $this->repository,
                $this->prNumber,
                $this->commitId,
                implode(
                    "\n",
                    [
                        '### PHP Insights',
                        "#### [{$category}] {$title}",
                        '```diff',
                        $diff['diff'],
                        '```',
                    ]
                ),
                $fileName,
                $diff['line'],
                GithubClient::REVIEW_COMMENT_RIGHT_SIDE,
                $diff['startLine'],
                GithubClient::REVIEW_COMMENT_RIGHT_SIDE
            );
        }
    }

    private function extractDiffLines(string $diff): array
    {
        $matches = [];

        // One Details can mention several parts
        if (! preg_match_all(self::DIFF_LINES_REGEX, $diff, $matches, PREG_SET_ORDER)) {
            // @todo: Throw custom exection
            throw new Exception("Can't retrive line range from diff: \"{$diff}\"");
        }

        if (count($matches) < 1) {
            throw new Exception("The diff does not have the expected line indication: \"{$diff}\"");
        }

        $parts = [];

        $lastIndex = 0;
        $totalMatches = count($matches);

        for ($i = 1; $i <= $totalMatches; $i++) {
            if ($i < $totalMatches) {
                $currentIndex = mb_strpos($diff, $matches[$i][0], $lastIndex);
            } else {
                $currentIndex = mb_strlen($diff);
            }

            $parts[] = [
                // Write the previous diff section
                'lineCursor' => $matches[$i - 1][1],
                // The current line indicator position is the threshold for the previous diff
                'diff' => mb_substr($diff, $lastIndex, $currentIndex),
            ];

            $lastIndex = $currentIndex;
        }

        // Extract line range from every diff
        foreach ($parts as &$part) {
            $lineParts = explode(',', $part['lineCursor']);
            $lineParts[0] = (int) $lineParts[0];

            // If there is a second value we have a diff for several lines
            if (count($lineParts) === 2) {
                $lineParts[1] = $lineParts[0] + ((int) $lineParts[1]);
            } else {
                $lineParts[1] = $lineParts[0];
            }

            $part['startLine'] = $lineParts[0];
            $part['line'] = $lineParts[1];
        }

        return $parts;
    }

    private function getEnvOrFail(string $envName): string
    {
        if (
            isset($_ENV[$envName])
            && is_string($_ENV[$envName])
            && mb_strlen($_ENV[$envName]) > 0
        ) {
            return $_ENV[$envName];
        }

        // @todo: Throw custom exection
        throw new Exception("The ENV \"{$envName}\" is not defined");
    }
}