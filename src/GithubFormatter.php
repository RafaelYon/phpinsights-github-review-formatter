<?php

declare(strict_types=1);

namespace RafaelYon\PhpInsightsReviewer;

use Exception;
use NunoMaduro\PhpInsights\Application\Console\Contracts\Formatter;
use NunoMaduro\PhpInsights\Domain\Contracts\HasDetails;
use NunoMaduro\PhpInsights\Domain\Details;
use NunoMaduro\PhpInsights\Domain\DetailsComparator;
use NunoMaduro\PhpInsights\Domain\Insights\InsightCollection;
use NunoMaduro\PhpInsights\Domain\Results;
use RafaelYon\PhpInsightsReviewer\Clients\GithubClient;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class GithubFormatter implements Formatter
{
    private const DIFF_LINES_REGEX = '/\@{2} \-(\d+,?\d+) \+(\d+,?\d+) \@{2}/';

    private string $repository;
    private int $prNumber;
    private string $commitId;
    private string $pathPrefixToIgnore;

    private OutputInterface $output;
    private GithubClient $client;

    public function __construct(OutputInterface $output)
    {
        $this->output = $output;

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
    public function format(InsightCollection $insights, array $metrics): void
    {
        $this->writeMetricsInsights($insights, $metrics);
        $this->writeInsightsResult($insights->results());
    }

    private function writeInsightsResult(Results $results): void
    {
        $this->client->createPullRequestReview(
            $this->repository,
            $this->prNumber,
            $this->commitId,
            GithubClient::REVIEW_EVENT_ACTION_COMMENT,
            $this->formatComment(
                $this->formatTableHeader(
                    'Quality',
                    'Complexity',
                    'Architecture',
                    'Style'
                ),
                $this->formatTableRow(
                    $this->formatMetricPercentage($results->getCodeQuality()),
                    $this->formatMetricPercentage($results->getComplexity()),
                    $this->formatMetricPercentage($results->getStructure()),
                    $this->formatMetricPercentage($results->getStyle())
                )
            )
        );
    }

    /**
     * @param array<int, string> $metrics
     */
    private function writeMetricsInsights(
        InsightCollection $insights,
        array $metrics
    ): void {
        $detailsComparator = new DetailsComparator();

        foreach ($metrics as $metricClass) {
            $category = explode('\\', $metricClass);
            $category = $category[count($category) - 2];

            foreach ($insights->allFrom(new $metricClass()) as $insight) {
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

                    try {
                        if ($detail->hasDiff()) {
                            $this->writeDetailDiff($category, $insight->getTitle(), $fileName, $detail);
                        } elseif ($detail->hasLine()) {
                            $this->writeDetail($category, $insight->getTitle(), $fileName, $detail);
                        }
                    } catch (Throwable $exception) {
                        $this->output->writeln("::error ::{$exception->getMessage()}");
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
        $this->client->createPullRequestReviewComment(
            $this->repository,
            $this->prNumber,
            $this->commitId,
            $this->formatComment(
                $this->createCategoryTitle($category, $title),
                $detail->getMessage()
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
        list($startLine, $line) = $this->extractDiffLinesPosition(
            $detail->getDiff()
        );

        $this->client->createPullRequestReviewComment(
            $this->repository,
            $this->prNumber,
            $this->commitId,
            $this->formatComment(
                $this->createCategoryTitle($category, $title),
                '```diff',
                $detail->getDiff(),
                '```'
            ),
            $fileName,
            $line,
            GithubClient::REVIEW_COMMENT_RIGHT_SIDE,
            $startLine,
            GithubClient::REVIEW_COMMENT_RIGHT_SIDE
        );
    }

    /**
     * @return array<int, int>
     */
    private function extractDiffLinesPosition(string $diff): array
    {
        $matches = [];

        // One Details can mention several parts
        if (! preg_match(self::DIFF_LINES_REGEX, $diff, $matches)) {
            // @todo: Throw custom exection
            throw new Exception("Can't retrive line range from diff: \"{$diff}\"");
        }

        if (count($matches) !== 3) {
            throw new Exception("The diff does not have the expected line indication: \"{$diff}\"");
        }

        $startLine = $this->extractLineFromDiffPosition($matches[1]);
        $endLine = $this->extractLineFromDiffPosition($matches[2]);

        if ($endLine < $startLine) {
            $newEnd = $startLine;

            $startLine = $endLine;
            $endLine = $newEnd;
        }

        return [$startLine, $endLine];
    }

    /**
     * @param string $diffPosition Ex.: "41,3"
     */
    private function extractLineFromDiffPosition(string $diffPosition): int
    {
        $lineParts = explode(',', $diffPosition);
        return (int) $lineParts[0];
    }

    private function formatLines(string ...$lines): string
    {
        return implode("\n", $lines);
    }

    private function formatTableRow(string ...$columns): string
    {
        return '| ' . implode(' | ', $columns) . ' |';
    }

    private function formatTableHeader(string ...$columns): string
    {
        $headerDividerLine = [];
        foreach ($columns as $index => $column) {
            $headerDividerLine[$index] = str_repeat('-', mb_strlen($column));
        }

        return $this->formatLines(
            $this->formatTableRow(...$columns),
            $this->formatTableRow(...$headerDividerLine)
        );
    }

    private function formatPercent(float $percentage): string
    {
        return number_format($percentage, 2, '.', '') . '%';
    }

    private function formatMetricPercentage(float $percentage): string
    {
        $color = 'red';
        if ($percentage >= 80) {
            $color = 'green';
        } elseif ($percentage >= 50) {
            $color = 'orange';
        }

        return '![](https://img.shields.io/static/v1?'
            . http_build_query([
                'label' => '',
                'style' => 'for-the-badge',
                'message' => $this->formatPercent($percentage),
                'color' => $color,
            ])
            . ')';
    }

    private function formatComment(
        string ...$lines
    ): string {
        return $this->formatLines(
            '### PHP Insights',
            ...$lines
        );
    }

    private function createCategoryTitle(
        string $category,
        string $title
    ): string {
        return "#### [{$category}] {$title}";
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