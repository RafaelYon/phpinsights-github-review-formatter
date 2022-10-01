<?php

declare(strict_types=1);

namespace RafaelYon\PhpInsightsReviewer\Clients;

final class FileComment
{
    public const LEFT_SIDE = 'LEFT';
    public const RIGHT_SIDE = 'RIGHT';

    private string $path;
    private string $body;

    private int $line;
    private string $side;

    private ?int $startLine;
    private ?string $startSide;

    private ?string $commitId;

    public function __construct(
        string $path,
        string $body,
        int $line,
        string $side,
        ?int $startLine = null,
        ?string $startSide = null,
        ?string $commitId = null
    ) {
        $this->path = $path;
        $this->body = $body;
        $this->line = $line;
        $this->side = $side;
        $this->startLine = $startLine;
        $this->startSide = $startSide;
        $this->commitId = $commitId;
    }

    /**
     * @return array<string, string|int>
     */
    public function toArray(): array
    {
        $result = [
            'path' => $this->path,
            'body' => $this->body,
            'line' => $this->line,
            'side' => $this->side,
        ];

        if ($this->startLine !== null && $this->startSide !== null) {
            $result['start_line'] = $this->startLine;
            $result['start_side'] = $this->startSide;
        }

        if ($this->commitId !== null) {
            $result['commit_id'] = $this->commitId;
        }

        return $result;
    }
}