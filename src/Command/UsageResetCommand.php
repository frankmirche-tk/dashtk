<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\UsageTracker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * UsageResetCommand
 *
 * Purpose:
 * - Resets (deletes) usage counters stored by UsageTracker from cache without touching the rest of the cache.
 * - This is meant for reporting cycles (e.g. monthly reset) or for test/dev cleanup.
 *
 * What it deletes:
 * - Counter entries: "usage.<key>"
 * - Index entry: "usage.__index"
 *
 * How it decides what to delete:
 * - Reads all known keys from UsageTracker::keys(), which is backed by "usage.__index".
 * - Optionally filters by --prefix (e.g. "support_chat.") to reset only a subset.
 * - Prints all targeted keys.
 * - If not a dry-run, calls UsageTracker::deleteKeys($keys).
 *
 * Options:
 * - --prefix:
 *   - only delete keys that start with the provided prefix
 *   - example: --prefix=support_chat.
 * - --dry-run:
 *   - prints what would be deleted but does not delete anything
 *
 * Usage examples:
 * - Reset everything:
 *   - php bin/console dashtk:usage:reset
 * - Show what would be reset (no changes):
 *   - php bin/console dashtk:usage:reset --dry-run
 * - Reset only support chat related counters:
 *   - php bin/console dashtk:usage:reset --prefix=support_chat.
 *
 * Notes / caveats:
 * - This command only knows keys that exist in "usage.__index".
 *   If there are orphaned cache entries not present in the index, they will not be deleted.
 * - After a reset, reports like usage:top / usage:report will be empty until counters are incremented again.
 */
#[AsCommand(
    name: 'dashtk:usage:reset',
    description: 'Löscht Usage-Counter (usage.*) aus dem Cache, ohne den restlichen Cache zu leeren.'
)]
final class UsageResetCommand extends Command
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
     * - prefix: filter deletion to keys that start with this prefix
     * - dry-run: show deletion plan without performing it
     */
    protected function configure(): void
    {
        $this
            ->addOption('prefix', null, InputOption::VALUE_REQUIRED, 'Optional: nur Keys mit Prefix löschen (z.B. support_chat.)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur anzeigen, was gelöscht würde (nichts wird gelöscht)');
    }

    /**
     * Execute the reset operation.
     *
     * Steps:
     * 1) Read list of known keys from usage.__index via UsageTracker::keys().
     * 2) Optionally filter keys by --prefix.
     * 3) Print deletion plan.
     * 4) If not dry-run, delete usage.<key> entries and clear the index.
     *
     * @param InputInterface  $input  CLI input.
     * @param OutputInterface $output CLI output.
     *
     * @return int Command::SUCCESS if nothing to do or deletion succeeded.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $prefix = $input->getOption('prefix');
        $prefix = is_string($prefix) && $prefix !== '' ? $prefix : null;

        $dryRun = (bool) $input->getOption('dry-run');

        // Only keys that are known via the index can be reset.
        $keys = $this->usage->keys(); // from usage.__index
        $keys = array_values(array_unique(array_filter($keys, 'is_string')));

        if ($prefix !== null) {
            $keys = array_values(array_filter($keys, static fn(string $k) => str_starts_with($k, $prefix)));
        }

        if ($keys === []) {
            $output->writeln('<info>OK</info> Keine Usage-Keys gefunden (nichts zu tun).');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '%s %d Usage-Key(s)%s:',
            $dryRun ? '<comment>DRY-RUN:</comment> würde löschen' : '<info>RESET:</info> lösche',
            count($keys),
            $prefix !== null ? sprintf(' (prefix: %s)', $prefix) : ''
        ));

        foreach ($keys as $k) {
            $output->writeln('  - usage.' . $k);
        }

        if ($dryRun) {
            $output->writeln('<comment>DRY-RUN</comment> Keine Änderungen vorgenommen.');
            return Command::SUCCESS;
        }

        $deleted = $this->usage->deleteKeys($keys);

        $output->writeln(sprintf('<info>OK</info> %d Key(s) gelöscht.', $deleted));
        return Command::SUCCESS;
    }
}
