<?php

namespace App\Agents\Reviewer;

/**
 * The Reviewer's structured verdict. Parsing is defensive with a hard bias:
 * anything unparseable is treated as changes_requested — a garbled reviewer
 * must never wave a diff through.
 */
final readonly class ReviewVerdict
{
    public function __construct(
        public bool $approved,
        /** @var array<int, array{file: ?string, comment: string}> */
        public array $comments,
        public string $summary,
        /** Non-empty = the Builder cannot fix this without the owner. */
        public array $questions = [],
    ) {}

    public function needsClarification(): bool
    {
        return ! $this->approved && $this->questions !== [];
    }

    public static function fromContent(string $content): self
    {
        $candidate = trim($content);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $candidate, $m)) {
            $candidate = $m[1];
        }

        $data = json_decode($candidate, true);

        if (! is_array($data) || ! array_key_exists('verdict', $data)) {
            return new self(
                approved: false,
                comments: [],
                summary: 'Reviewer response was malformed; treating as changes requested. Raw: '
                    .mb_substr($content, 0, 500),
            );
        }

        $comments = [];
        foreach (is_array($data['comments'] ?? null) ? $data['comments'] : [] as $c) {
            if (is_string($c) && $c !== '') {
                $comments[] = ['file' => null, 'comment' => $c];
            } elseif (is_array($c) && is_string($c['comment'] ?? null)) {
                $comments[] = [
                    'file' => is_string($c['file'] ?? null) ? $c['file'] : null,
                    'comment' => $c['comment'],
                ];
            }
        }

        $questions = [];
        foreach (is_array($data['questions'] ?? null) ? $data['questions'] : [] as $q) {
            if (is_string($q) && trim($q) !== '') {
                $questions[] = trim($q);
            }
        }

        return new self(
            approved: ($data['verdict'] ?? '') === 'approved',
            comments: $comments,
            summary: is_string($data['summary'] ?? null) ? $data['summary'] : '',
            questions: $questions,
        );
    }

    public function toArray(): array
    {
        return [
            'approved' => $this->approved,
            'comments' => $this->comments,
            'summary' => $this->summary,
            'questions' => $this->questions,
        ];
    }
}
