<?php

namespace ErnestDefoe\GitHubReleaseBot\Console;

use ErnestDefoe\GitHubReleaseBot\Watcher;
use Flarum\Console\AbstractCommand;

/**
 * php flarum github-release-bot:poll
 *
 * 🚨 configure() + setName(), NOT a $signature property.
 *
 * Flarum's AbstractCommand is Symfony's, not Laravel's. A $signature is simply
 * ignored, which leaves the command nameless — and Symfony then throws while
 * assembling the console, taking down EVERY `php flarum` command with exit 255
 * and no output at all. Same for options: $this->input->getOption(), never
 * $this->option().
 */
class PollCommand extends AbstractCommand
{
    public function __construct(
        protected Watcher $watcher
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('github-release-bot:poll')
            ->setDescription('Check watched GitHub repositories for new releases and announce them.')
            ->addOption('dry-run', null, null, 'Report what would be posted without posting anything.');
    }

    protected function fire(): int
    {
        $dryRun = (bool) $this->input->getOption('dry-run');

        $r = $this->watcher->run($dryRun);

        $this->info(sprintf(
            '%sChecked %d — posted %d, newly watched %d, failed %d.',
            $dryRun ? '[dry run] ' : '',
            $r['checked'],
            $r['posted'],
            $r['adopted'],
            $r['failed']
        ));

        foreach ($r['details'] as $line) {
            $this->info('  '.$line);
        }

        return 0;
    }
}
