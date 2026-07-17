<?php

namespace App\Agents\Architect;

/**
 * The structured envelope every Architect turn must come back in.
 * Parsing is defensive: frontier models occasionally wrap JSON in fences or
 * ignore json_mode entirely — a malformed envelope degrades to a plain reply,
 * never an exception (the conversation must survive a sloppy model turn).
 */
final readonly class ArchitectEnvelope
{
    public function __construct(
        public string $reply,
        /** @var array<int, array{text: string, options: ?array}> */
        public array $questions,
        public bool $consensusReached,
        /**
         * Repo context the Architect wants to inspect before deciding
         * (M14a/T-66): tracked file paths, a directory ("dir/"), or "tree".
         * @var string[]
         */
        public array $reads = [],
    ) {}

    public static function fromContent(string $content): self
    {
        $candidate = trim($content);

        // Strip a ```json ... ``` fence if present.
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $candidate, $m)) {
            $candidate = $m[1];
        }

        $data = json_decode($candidate, true);

        if (! is_array($data) || ! array_key_exists('reply', $data)) {
            return new self(reply: $content, questions: [], consensusReached: false);
        }

        $questions = [];
        foreach (is_array($data['questions'] ?? null) ? $data['questions'] : [] as $q) {
            if (is_string($q) && $q !== '') {
                $questions[] = ['text' => $q, 'options' => null];
            } elseif (is_array($q) && is_string($q['text'] ?? null) && $q['text'] !== '') {
                $options = $q['options'] ?? null;
                $questions[] = [
                    'text' => $q['text'],
                    'options' => is_array($options) && $options !== [] ? array_values(array_filter($options, 'is_string')) : null,
                ];
            }
        }

        $reads = [];
        foreach (is_array($data['reads'] ?? null) ? $data['reads'] : [] as $r) {
            if (is_string($r) && trim($r) !== '') {
                $reads[] = trim($r);
            }
        }

        return new self(
            reply: is_string($data['reply']) ? $data['reply'] : json_encode($data['reply']),
            questions: $questions,
            consensusReached: ($data['consensus_reached'] ?? false) === true,
            reads: $reads,
        );
    }
}
