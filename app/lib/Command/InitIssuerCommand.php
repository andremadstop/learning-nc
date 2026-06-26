<?php
declare(strict_types=1);

namespace OCA\Learning\Command;

use OCA\Learning\Service\KeyService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * OCC command to initialise (or rotate) the instance's issuer signing key.
 *
 * Usage:
 *   php occ learning:cert:init-issuer            # generate the first active Ed25519 key
 *   php occ learning:cert:init-issuer --rotate   # retire the active key, generate a new one
 *
 * The private key is generated via ext-sodium and stored ICrypto-encrypted at rest (CERT-03).
 * Rotation never deletes a key — retired keys keep verifying past certificates (CERT-04).
 */
class InitIssuerCommand extends Command {
    private KeyService $keyService;

    public function __construct(KeyService $keyService) {
        parent::__construct();
        $this->keyService = $keyService;
    }

    protected function configure(): void {
        $this
            ->setName('learning:cert:init-issuer')
            ->setDescription('Generate the issuer Ed25519 signing key (private key encrypted at rest)')
            ->addOption(
                'rotate',
                null,
                InputOption::VALUE_NONE,
                'Retire the current active key and generate a new one (never deletes keys)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $rotate = (bool)$input->getOption('rotate');

        try {
            $key = $rotate ? $this->keyService->rotate() : $this->keyService->init();
        } catch (\RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            if (!$rotate && str_contains($e->getMessage(), 'already exists')) {
                $output->writeln('<comment>Run with --rotate to retire it and generate a new key.</comment>');
            }
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $output->writeln('<error>Issuer key initialisation failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>' . ($rotate ? 'Rotated issuer key.' : 'Issuer key generated.') . '</info>');
        $output->writeln('  key_id : ' . $key->getKeyId());
        $output->writeln('  did    : ' . $this->keyService->hostDid());
        $output->writeln('  kid    : ' . $this->keyService->hostDid() . '#' . $key->getKeyId());
        $output->writeln('Public key is published at /apps/learning/did.json');

        return Command::SUCCESS;
    }
}
