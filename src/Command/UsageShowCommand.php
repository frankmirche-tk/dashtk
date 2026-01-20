<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\UsageTracker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * UsageShowCommand
 *
 * Purpose:
 * - Simple inspection command to read usage counters from the UsageTracker cache storage.
 * - Useful for quick debugging ("was this service/method called?") and for verifying that tracking works.
 *
 * What it can display:
 * - A single counter:
 *   - reads the integer value from cache key "usage.<key>"
 *   - prints "usage.<key> = <count>"
 * - The index list of known keys:
 *   - special argument "__index" prints the list stored under "usage.__index"
 *   - this list is maintained by UsageTracker::rememberKey() when increment() is called
 *
 * Input argument:
 * - key (required):
 *   - example: "support_chat.ask"
 *   - special value: "__index" prints the list of known usage keys
 *
 * Usage examples:
 * - Show a single counter:
 *   - php bin/console dashtk:usage:show support_chat.ask
 * - Show all known keys:
 *   - php bin/console dashtk:usage:show __index
 *
 * Notes:
 * - This command does not modify counters; it only reads.
 * - If a key was never incremented (or TTL expired), it will return 0.
 * - The prefix "usage." is added automatically; pass only the logical key name.
 */
#[AsCommand(name: 'dashtk:usage:show', description: 'Zeigt Usage Counter an.')]
final class UsageShowCommand extends Command
{
    /**
     * @param UsageTracker $usage Usage tracker cache abstraction.
     */
    public function __construct(private readonly UsageTracker $usage)
    {
        parent::__construct();
    }

    /**
     * Configure CLI arguments.
     *
     * - key: logical usage key, without "usage." prefix.
     *   Example: support_chat.ask
     *   Special: __index to display the list of known keys.
     */
    protected function configure(): void
    {
        $this->addArgument('key', InputArgument::REQUIRED, 'Usage-Key, z.B. support_chat.ask');
    }

    /**
     * Execute the usage counter read.
     *
     * Behavior:
     * - If key == "__index":
     *   - prints the key registry (usage.__index)
     * - Else:
     *   - prints the counter value (usage.<key>)
     *
     * @param InputInterface  $input  CLI input.
     * @param OutputInterface $output CLI output.
     *
     * @return int Command::SUCCESS always (no destructive behavior).
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = (string) $input->getArgument('key');

        if ($key === '__index') {
            $keys = $this->usage->keys();

            $output->writeln('usage.__index:');
            if ($keys === []) {
                $output->writeln(' (empty)');
                return Command::SUCCESS;
            }

            foreach ($keys as $k) {
                $output->writeln(' - ' . $k);
            }

            return Command::SUCCESS;
        }

        $val = $this->usage->get($key);
        $output->writeln(sprintf('usage.%s = %d', $key, $val));

        return Command::SUCCESS;
    }
}
