<?php
declare(strict_types=1);

namespace OCA\Learning\Repair;

/**
 * The legacy AI task cleanup of the upgrade failed (MigrateLlmConfiguration).
 *
 * An \Error on purpose, like ProbeInjectedFailure: Nextcloud's Repair::run() catches every
 * \Exception of a step and lets the upgrade finish, after which cron and the task worker would
 * send the queued legacy prompts outside the gateway and the 5.7 consent. An \Error ends
 * `occ upgrade` before installed_version is bumped and leaves maintenance mode on. In that state
 * cron does not run (Nextcloud 33 CronService), and a newly started occ process loads no apps, so
 * no app-provided AI backend picks up the tasks. Running `occ upgrade` again retries the cleanup.
 * Not covered: a dedicated task worker process that was already running before the upgrade.
 */
final class LegacyTaskCleanupFailed extends \Error {
}
