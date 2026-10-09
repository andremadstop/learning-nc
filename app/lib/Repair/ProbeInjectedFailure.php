<?php
declare(strict_types=1);

namespace OCA\Learning\Repair;

/**
 * Raised only by the lifecycle probe's fault injection (env PROBE_FAIL_AFTER on `occ upgrade`).
 *
 * An \Error on purpose: Nextcloud's Repair::run() catches every \Exception of a step, logs it and
 * lets the upgrade finish with exit code 0 and the new installed_version, so an \Exception would
 * never interrupt the upgrade the probe wants to see interrupted.
 */
final class ProbeInjectedFailure extends \Error {
}
