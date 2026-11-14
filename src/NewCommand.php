<?php

declare(strict_types=1);

namespace Omnitrix\Installer;

use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

use function array_diff;
use function array_map;
use function getcwd;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function preg_match;
use function rtrim;
use function scandir;
use function str_ends_with;
use function str_starts_with;

use const PHP_BINARY;

/**
 * `omnitrix new my-api`: checks the platform, then creates the project with
 * `composer create-project omnitrix/omnitrix my-api`.
 */
#[AsCommand(name: 'new', description: 'Create a new Omnitrix application')]
final class NewCommand extends Command
{
    public const string VERSION = '0.1.0';

    public const string PACKAGE = 'omnitrix/omnitrix';

    /** @var (callable(list<string>, string, OutputInterface): int)|null */
    private $runner;

    /**
     * @param Requirements|null                                      $requirements platform check (replaceable in tests)
     * @param (callable(list<string>, string, OutputInterface): int)|null $runner   runs a command line in a directory
     */
    public function __construct(private readonly ?Requirements $requirements = null, ?callable $runner = null)
    {
        $this->runner = $runner;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Directory of the new application')
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Install the latest development version')
            ->addOption('composer', null, InputOption::VALUE_REQUIRED, 'Path to the Composer executable')
            ->addOption('repository', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Extra Composer repository for the skeleton and its dependencies (added to the new project)')
            ->addOption('skip-requirements', null, InputOption::VALUE_NONE, 'Create the project even if the platform check fails');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name');
        if (!is_string($name) || preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1 || str_starts_with($name, '.')) {
            $io->error('The application name may contain letters, digits, dots, dashes and underscores.');

            return self::INVALID;
        }
        $cwd = getcwd();
        $directory = rtrim($cwd === false ? '.' : $cwd, '/') . '/' . $name;
        if (is_file($directory) || (is_dir($directory) && !self::isEmptyDirectory($directory))) {
            $io->error("{$directory} already exists and is not empty.");

            return self::FAILURE;
        }

        $requirements = $this->requirements ?? new Requirements();
        $io->section('Checking requirements');
        $rows = array_map(static fn(array $r): array => [$r['name'], $r['required'], $r['ok'] ? "<info>{$r['found']}</info>" : "<error>{$r['found']}</error>"], $requirements->check());
        $io->table(['Requirement', 'Needed', 'Found'], $rows);
        if (!$requirements->satisfied()) {
            if ($input->getOption('skip-requirements') !== true) {
                $io->error([
                    'This PHP installation does not meet the requirements above.',
                    'Install the missing PHP version or extensions (for the PHP binary running this installer), then try again.',
                    'Use --skip-requirements to create the project anyway.',
                ]);

                return self::FAILURE;
            }
            $io->warning('Continuing although the requirements are not met.');
        }

        $composer = $this->composer($input);
        $command = [...$composer, 'create-project', self::PACKAGE, $name, '--prefer-dist', '--no-interaction'];
        if ($input->getOption('dev') === true) {
            $command[] = '--stability=dev';
        }
        $repositories = $input->getOption('repository');
        $added = false;
        foreach (is_array($repositories) ? $repositories : [] as $repository) {
            if (is_string($repository) && $repository !== '') {
                $command[] = '--repository=' . $repository;
                $added = true;
            }
        }
        if ($added) {
            // Keep the repositories in the new project so its dependencies resolve from them too.
            $command[] = '--add-repository';
        }
        if ($output->isDecorated()) {
            $command[] = '--ansi';
        }

        $io->section('Creating the application');
        $code = ($this->runner ?? self::runProcess(...))($command, $cwd === false ? '.' : $cwd, $output);
        if ($code !== 0) {
            $io->error('Composer could not create the project (exit code ' . $code . ').');

            return self::FAILURE;
        }

        $io->success("Omnitrix application created in {$name}");
        $io->writeln([
            'Next steps:',
            '',
            "  cd {$name}",
            '  # set the database and Redis credentials in .env',
            '  php omnitrix migrate',
            '  php omnitrix serve',
            '',
        ]);

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function composer(InputInterface $input): array
    {
        $option = $input->getOption('composer');
        if (is_string($option) && $option !== '') {
            return str_ends_with($option, '.phar') ? [PHP_BINARY, $option] : [$option];
        }
        $cwd = getcwd();
        if ($cwd !== false && is_file($cwd . '/composer.phar')) {
            return [PHP_BINARY, $cwd . '/composer.phar'];
        }
        $found = (new ExecutableFinder())->find('composer');
        if ($found === null) {
            throw new RuntimeException('Composer was not found. Install it (https://getcomposer.org) or pass --composer=/path/to/composer.');
        }

        return [$found];
    }

    /** @param list<string> $command */
    private static function runProcess(array $command, string $cwd, OutputInterface $output): int
    {
        $process = new Process($command, $cwd, null, null, null);
        if (Process::isTtySupported() && $output->isDecorated()) {
            $process->setTty(true);
        }

        return $process->run(static function (string $type, string $buffer) use ($output): void {
            $output->write($buffer);
        });
    }

    private static function isEmptyDirectory(string $directory): bool
    {
        $entries = scandir($directory);

        return $entries !== false && array_diff($entries, ['.', '..']) === [];
    }
}
