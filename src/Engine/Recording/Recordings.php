<?php

declare(strict_types=1);

namespace App\Engine\Recording;

/**
 * Model answers recorded per reflex, in a JSON file each, keyed by the moment of the run they answer.
 */
final readonly class Recordings
{
    public function __construct(
        private string $directory,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $reflex, string $key): ?array
    {
        $answer = $this->load($reflex)[$key] ?? null;
        if (!\is_array($answer)) {
            return null;
        }

        $result = [];
        foreach ($answer as $field => $value) {
            $result[(string) $field] = $value;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $answer
     */
    public function save(string $reflex, string $key, array $answer): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(\sprintf('Cannot create "%s".', $this->directory));
        }

        $answers = $this->load($reflex);
        $answers[$key] = $answer;
        ksort($answers);
        file_put_contents($this->file($reflex), json_encode($answers, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n", \LOCK_EX);
    }

    public function has(string $reflex): bool
    {
        return is_file($this->file($reflex));
    }

    /**
     * @return array<mixed>
     */
    private function load(string $reflex): array
    {
        if (!$this->has($reflex)) {
            return [];
        }

        $answers = json_decode((string) file_get_contents($this->file($reflex)), true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($answers) ? $answers : [];
    }

    private function file(string $reflex): string
    {
        return $this->directory.'/'.trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($reflex)), '-').'.json';
    }
}
