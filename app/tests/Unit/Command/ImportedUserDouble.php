<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Command;

/**
 * IUser double for the user import: records the email set via setSystemEMailAddress()
 * (not part of the minimal IUser stub, which other anonymous test users implement).
 */
final class ImportedUserDouble implements \OCP\IUser {
    public ?string $systemEmail = null;
    public ?string $displayName = null;

    public function __construct(private string $uid) {
    }

    public function getUID(): string {
        return $this->uid;
    }

    public function getDisplayName(): string {
        return $this->displayName ?? $this->uid;
    }

    public function getEMailAddress(): ?string {
        return $this->systemEmail;
    }

    public function setDisplayName(string $displayName): bool {
        $this->displayName = $displayName;
        return true;
    }

    public function setSystemEMailAddress(string $mailAddress): void {
        $this->systemEmail = $mailAddress;
    }
}
