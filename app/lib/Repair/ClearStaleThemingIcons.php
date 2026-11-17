<?php
declare(strict_types=1);

namespace OCA\Learning\Repair;

use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * GitHub #81: theming renders this app's favicon and touch icon once and keeps them in its app data
 * (appdata_<id>/theming/global/<cachebuster>/favIcon-learning#<colour>, touchIcon-learning#<colour>,
 * icon-learning-#<colour>…). The file name has no app version, so an app update never renders them
 * again: 5.14.0 shipped a new icon and the browser tab kept the old, empty one.
 *
 * After every update this step deletes the cached learning icons in theming's current cache folder;
 * theming renders them again from img/learning.svg on the next request. Only that one folder: theming
 * drops the other numbered folders itself whenever its cachebuster changes, and ISimpleFolder lists
 * files only, so walking "global" would not find them anyway.
 *
 * It never fails an upgrade: no theming data, no folder, a type conflict on the cachebuster or a
 * refused delete all end the step quietly with a line in the output.
 */
class ClearStaleThemingIcons implements IRepairStep {
    /** Theming's cache names for this app's icons (IconController, NC 33–35). */
    public const CACHED_ICON = '/^(favIcon-learning#|touchIcon-learning#|icon-learning-#)/';

    public function __construct(
        private IAppDataFactory $appDataFactory,
        private IAppConfig $appConfig,
    ) {
    }

    public function getName(): string {
        return 'Clear cached Learning icons in theming';
    }

    public function run(IOutput $output): void {
        try {
            $cacheBuster = $this->appConfig->getValueInt('theming', 'cachebuster', 0);
            $folder = $this->appDataFactory->get('theming')->getFolder('global/' . $cacheBuster);
        } catch (NotFoundException) {
            $output->info('No cached theming icons to clear');
            return;
        } catch (\Throwable $e) {
            $output->warning('Cached theming icons not checked: ' . $e->getMessage());
            return;
        }

        $deleted = 0;
        try {
            foreach ($folder->getDirectoryListing() as $file) {
                if (preg_match(self::CACHED_ICON, $file->getName()) !== 1) {
                    continue;
                }
                try {
                    $file->delete();
                    $deleted++;
                } catch (\Throwable $e) {
                    $output->warning('Cached theming icon ' . $file->getName() . ' not deleted: ' . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            $output->warning('Cached theming icons not fully cleared: ' . $e->getMessage());
        }
        $output->info('Cleared ' . $deleted . ' cached Learning icon(s) in theming/global/' . $cacheBuster);
    }
}
