<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\UsageTracker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * UsageTopCommand
 *
 * Purpose:
 * - Displays the most-used usage keys (top N) based on the counters stored by UsageTracker.
 * - Intended as a quick CLI overview to see which tracked actions/services are used most frequently.
 *
 * Data source:
 * - UsageTracker::top($limit) which:
 *   - reads the known key list from usage.__index
 *   - fetches each counter value from usage.<key>
 *   - sorts by count descending
 *   - returns the first N rows
 *
 * Options:
 * - --limit / -l:
 *   - number of entries to show
 *   - default: 20
 *   - minimum enforced: 1
 *
 * Output format:
 * - One line per key:
 *   "<count padded to 6 chars>  <key>"
 *
 * Usage examples:
 * - Default top 20:
 *   - php bin/console dashtk:usage:top
 * - Top 50:
 *   - php bin/console dashtk:usage:top --limit=50
 *   - php bin/console dashtk:usage:top -l 50
 *
 * Notes:
 * - If usage.__index is empty (no tracked keys were ever incremented or TTL expired),
 *   the output will be empty.
 * - This command is read-only; it does not modify counters.
 */
#[AsCommand(name: 'dashtk:usage:top', description: 'Zeigt die meistgenutzten Usage-Keys.')]
final class UsageTopCommand extends Command
{
    /**
     * @param UsageTracker $usage Usage tracker cache abstraction.
     */
    public function __construct(private readonly UsageTracker $usage)
    {
        parent::__construct();
    }

    /**
     * Configure CLI options.
     *
     * - limit: number of entries to print (default: 20)
     */
    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Anzahl Einträge', '20');
    }

    /**
     * Execute the "top usage keys" listing.
     *
     * @param InputInterface  $input  CLI input.
     * @param OutputInterface $output CLI output.
     *
     * @return int Command::SUCCESS always (read-only operation).
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, (int) $input->getOption('limit'));
        $rows = $this->usage->top($limit);

        foreach ($rows as $r) {
            $output->writeln(sprintf('%6d  %s', $r['count'], $r['key']));
        }

        return Command::SUCCESS;
    }
}
