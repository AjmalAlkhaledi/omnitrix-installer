<?php

declare(strict_types=1);

namespace Omnitrix\Installer\Tests;

use LogicException;
use Omnitrix\Installer\NewCommand;
use Omnitrix\Installer\Requirements;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

use function array_column;
use function chdir;
use function getcwd;
use function mkdir;
use function sys_get_temp_dir;
use function touch;
use function uniqid;

#[CoversClass(NewCommand::class)]
#[CoversClass(Requirements::class)]
final class InstallerTest extends TestCase
{
    private string $previousCwd = '.';

    private string $workspace;

    protected function setUp(): void
    {
        $cwd = getcwd();
        $this->previousCwd = $cwd === false ? '.' : $cwd;
        $this->workspace = sys_get_temp_dir() . '/omnitrix-installer-' . uniqid();
        mkdir($this->workspace);
        chdir($this->workspace);
    }

    protected function tearDown(): void
    {
        chdir($this->previousCwd);
    }

    public function testRequirementsReportVersionsAndMissingExtensions(): void
    {
        $versions = ['openswoole' => '26.2.0', 'redis' => '6.1.0', 'pdo_mysql' => false];
        $requirements = new Requirements('8.5.12', static fn(string $e): string|false => $versions[$e] ?? false, static fn(string $e): bool => $e !== 'pdo_mysql');

        $check = $requirements->check();
        self::assertSame([true, true, false, false], array_column($check, 'ok'));
        self::assertSame('6.1.0', $check[2]['found']);
        self::assertSame('missing', $check[3]['found']);
        self::assertFalse($requirements->satisfied());
        self::assertFalse((new Requirements('8.2.0', static fn(): string => '99', static fn(): bool => true))->satisfied());
        self::assertTrue((new Requirements('8.3.0', static fn(): string => '26.2.0', static fn(): bool => true))->satisfied());
    }

    public function testRunsComposerCreateProjectWithTheSkeleton(): void
    {
        $calls = [];
        $tester = $this->tester(static function (array $command, string $cwd, OutputInterface $output) use (&$calls): int {
            $calls[] = [$command, $cwd];

            return 0;
        });

        self::assertSame(0, $tester->execute(['name' => 'my-api', '--dev' => true, '--composer' => '/usr/local/bin/composer', '--repository' => ['{"type":"path","url":"/src"}']]));
        self::assertSame([[['/usr/local/bin/composer', 'create-project', 'omnitrix/omnitrix', 'my-api', '--prefer-dist', '--no-interaction', '--stability=dev', '--repository={"type":"path","url":"/src"}', '--add-repository'], $this->workspace]], $calls);
        self::assertStringContainsString('php omnitrix migrate', $tester->getDisplay());
    }

    public function testRefusesExistingDirectoriesBadNamesAndUnmetRequirements(): void
    {
        mkdir($this->workspace . '/taken');
        touch($this->workspace . '/taken/file');
        $never = static fn(): int => throw new LogicException('composer must not run');

        self::assertSame(1, $this->tester($never)->execute(['name' => 'taken', '--composer' => 'composer']));
        self::assertSame(2, $this->tester($never)->execute(['name' => '../escape', '--composer' => 'composer']));

        $missing = new Requirements('8.5.0', static fn(): false => false, static fn(): bool => false);
        $command = new NewCommand($missing, $never);
        (new Application())->addCommand($command);
        $tester = new CommandTester($command);
        self::assertSame(1, $tester->execute(['name' => 'app', '--composer' => 'composer']));
        self::assertStringContainsString('does not meet the requirements', $tester->getDisplay());
    }

    public function testComposerFailureIsReported(): void
    {
        self::assertSame(1, $this->tester(static fn(): int => 2)->execute(['name' => 'app', '--composer' => 'composer']));
    }

    /** @param callable(list<string>, string, OutputInterface): int $runner */
    private function tester(callable $runner): CommandTester
    {
        $ok = new Requirements('8.5.0', static fn(): string => '99.0.0', static fn(): bool => true);
        $command = new NewCommand($ok, $runner);
        (new Application())->addCommand($command);

        return new CommandTester($command);
    }
}
