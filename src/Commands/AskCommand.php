<?php

declare (strict_types = 1);

namespace Rhapsody\Core\Commands;

use Rhapsody\Core\Ai\Docs\AskResult;
use Rhapsody\Core\Ai\Docs\AskServiceFactory;
use Rhapsody\Core\Contracts\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `rhapsody ask "how do I add middleware to a route group?"`
 *
 * Searches the docs bundled with core and prints the best sections; with an
 * AI key (development only) it also writes a short answer grounded in them.
 * Either way the pages used are always listed.
 *
 * The constructor only stores the container: bin/rhapsody builds every
 * command at startup to read its name, so the index, config and AI client
 * are all loaded inside execute(), and only when `ask` actually runs.
 */
class AskCommand extends Command
{
    private const BODY_LIMIT = 1400;

    /**
     * @param string|null $gapLogPath Where weak-docs questions are recorded; defaults to storage/logs/doc-gaps.jsonl.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ?string $gapLogPath = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('ask')
            ->setDescription('Answers a framework question from the bundled docs.')
            ->addArgument('question', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Your question (quotes optional).')
            ->addOption('no-ai', null, InputOption::VALUE_NONE, 'Skip the AI answer and only show matching docs sections.')
            ->addOption('top', null, InputOption::VALUE_REQUIRED, 'How many docs sections to retrieve (1-10).', '4')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $words    = $input->getArgument('question');
        $question = trim(implode(' ', is_array($words) ? array_filter($words, 'is_string') : []));
        if ($question === '') {
            $output->writeln('<error>Ask a question, e.g. rhapsody ask "how do I add middleware to a route group?"</error>');

            return Command::INVALID;
        }

        $top = filter_var($input->getOption('top'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]);
        if ($top === false) {
            $output->writeln('<error>--top must be a whole number from 1 to 10.</error>');

            return Command::INVALID;
        }

        try {
            $service = AskServiceFactory::fromContainer($this->container, $this->gapLogPath);
        } catch (\Throwable $e) {
            $output->writeln('<error>The bundled docs index could not be loaded: ' . $e->getMessage() . '</error>');
            $output->writeln('Reinstall arout/rhapsody-core, or run `rhapsody docs:build` inside the core repository.');

            return Command::FAILURE;
        }

        $gapLog = $this->gapLogPath ?? AskServiceFactory::defaultGapLogPath();

        $result = $service->ask($question, $top, $input->getOption('no-ai') !== true);

        if ($input->getOption('json') === true) {
            $output->writeln(
                json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
                OutputInterface::OUTPUT_RAW
            );

            return Command::SUCCESS;
        }

        $this->render($result, $output, $gapLog);

        return Command::SUCCESS;
    }

    private function render(AskResult $result, OutputInterface $output, string $gapLog): void
    {
        $raw = OutputInterface::OUTPUT_RAW;

        foreach ($result->notices as $notice) {
            $output->writeln('<comment>' . $this->escape($notice) . '</comment>');
        }

        if ($result->answer !== null) {
            $output->writeln('');
            $output->writeln($result->answer, $raw);
        } elseif ($result->hits !== []) {
            $output->writeln('');
            $output->writeln('<info>Best matches from the docs:</info>');

            foreach ($result->hits as $i => $hit) {
                $output->writeln('');
                $output->writeln('<info>[' . ($i + 1) . '] ' . $this->escape($hit->chunk->label()) . '</info>');
                $output->writeln($this->excerpt($hit->chunk->text, $hit->chunk->url), $raw);
            }
        }

        if ($result->hits !== []) {
            $output->writeln('');
            $output->writeln('<info>Sources:</info>');

            foreach ($result->hits as $i => $hit) {
                $n     = $i + 1;
                $mark  = in_array($n, $result->cited, true) ? ' (cited)' : '';
                $output->writeln("  [{$n}] " . $this->escape($hit->chunk->label()) . ' - ' . $hit->chunk->url . $mark);
            }
        }

        if ($result->gapReason !== null) {
            $output->writeln('');
            $output->writeln('<comment>Noted as a docs gap (' . $result->gapReason . ') in ' . $this->escape($gapLog) . '</comment>');
        }
    }

    /**
     * A section body, cut at a line boundary if it is long, with a pointer to the full page.
     */
    private function excerpt(string $text, string $url): string
    {
        if (strlen($text) <= self::BODY_LIMIT) {
            return $text;
        }

        $cut      = substr($text, 0, self::BODY_LIMIT);
        $boundary = strrpos($cut, "\n");

        return rtrim($boundary !== false && $boundary > 200 ? substr($cut, 0, $boundary) : $cut)
            . "\n... (truncated; full section at {$url})";
    }

    private function escape(string $text): string
    {
        return \Symfony\Component\Console\Formatter\OutputFormatter::escape($text);
    }
}
