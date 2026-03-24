<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

use RuntimeException;

final class InteractivePrompter
{
    /**
     * @param resource $input
     * @param resource $output
     */
    public function __construct(
        private readonly mixed $input = STDIN,
        private readonly mixed $output = STDOUT,
    ) {
    }

    public static function canPrompt(mixed $stream = STDIN): bool
    {
        if (! is_resource($stream)) {
            return false;
        }

        if (function_exists('stream_isatty')) {
            return @stream_isatty($stream);
        }

        if (function_exists('posix_isatty')) {
            /** @var resource $stream */
            return @posix_isatty($stream);
        }

        return false;
    }

    public function ask(string $question, ?string $default = null): string
    {
        $suffix = $default !== null ? sprintf(' [%s]', $default) : '';
        fwrite($this->output, $question . $suffix . ': ');
        $line = fgets($this->input);

        if ($line === false) {
            if ($default !== null) {
                return $default;
            }

            throw new RuntimeException(sprintf('Interactive input ended while prompting for %s.', $question));
        }

        $value = trim($line);

        if ($value === '' && $default !== null) {
            return $default;
        }

        if ($value === '') {
            throw new RuntimeException(sprintf('A value is required for %s.', $question));
        }

        return $value;
    }

    /**
     * @param list<string> $options
     */
    public function choose(string $question, array $options, ?string $default = null): string
    {
        fwrite($this->output, $question . "\n");

        foreach ($options as $index => $option) {
            fwrite($this->output, sprintf("  %d. %s\n", $index + 1, $option));
        }

        $choice = $this->ask('Enter choice', $default !== null ? (string) (array_search($default, $options, true) + 1) : null);
        $choiceIndex = (int) $choice - 1;

        if (! isset($options[$choiceIndex])) {
            throw new RuntimeException(sprintf('Invalid choice for %s.', $question));
        }

        return $options[$choiceIndex];
    }

    public function confirm(string $question, bool $default = false): bool
    {
        $answer = strtolower($this->ask($question . ' [y/n]', $default ? 'y' : 'n'));
        return in_array($answer, ['y', 'yes'], true);
    }
}
