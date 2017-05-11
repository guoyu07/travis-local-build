<?php

declare(strict_types = 1);

namespace Fprochazka\TravisLocalBuild;

use Nette\Utils\Strings;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Terminal;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * @author Filip Procházka <filip@prochazka.su>
 */
class BuildExecutor
{

	const PHP_BASE_IMAGE = 'travisci/php';

	/** @var \Symfony\Component\Console\Output\OutputInterface */
	private $out;

	/** @var \Symfony\Component\Console\Output\OutputInterface */
	private $err;

	/** @var string */
	private $tempDir;

	/** @var \Symfony\Component\Filesystem\Filesystem */
	private $fs;

	/** @var \Fprochazka\TravisLocalBuild\Docker */
	private $docker;

	/** @var \Symfony\Component\Console\Terminal */
	private $terminal;

	public function __construct(OutputInterface $stdOut, string $tempDir)
	{
		$this->out = $stdOut;
		$this->err = ($stdOut instanceof ConsoleOutput) ? $stdOut->getErrorOutput() : new StreamOutput(fopen('php://stderr', 'wb'));
		$this->tempDir = $tempDir;
		$this->fs = new Filesystem();
		$this->docker = new Docker();
		$this->terminal = new Terminal();
	}

	public function execute(Job $job): void
	{
		$imageRef = $this->dockerBuild($job);
		$this->dockerRun($job, $imageRef);
	}

	private function dockerRun(Job $job, string $imageRef)
	{
		$volumes = [];
		foreach ($this->findProjectFiles($job->getProjectDir()) as $file) {
			$volumes[$file->getPathname()] = '/build/' . $file->getRelativePathname();
		}

		$process = $this->docker->run($imageRef, $volumes);
		$process->wait(
			function (string $type, $data): void {
				if ($type === Process::OUT) {
					$this->out->write($data);
				} else {
					$this->err->write($data);
				}
			}
		);
		if (!$process->isSuccessful()) {
			$this->out->writeln(sprintf('<error>Build failed</error>'));
		} else {
			$this->out->writeln(sprintf('<info>Build succeeded</info>'));
		}
	}

	private function dockerBuild(Job $job): string
	{
		$this->out->writeln(sprintf('Building docker image for job %s', $job->getId()));

		$dockerSteps = $this->buildDockerFile($job);
		$imageName = Strings::lower($job->getProjectName() . ':v' . $job->getId());

		$dockerStepsCount = count($dockerSteps);
		$progress = new ProgressBar($this->out, $dockerStepsCount);
		$process = $this->docker->build($imageName, $this->getDockerFile($job));
		$process->wait(function (string $type, $data) use ($progress, $dockerSteps, $dockerStepsCount): void {
			if ($type === Process::OUT) {
				foreach (explode("\n", $data) as $line) {
					if (preg_match('~^Step\\s+(\\d+)\\/' . $dockerStepsCount . '\\s+:~', $line, $m)) {
						$step = (int) $m[1];
						$stepMessageLength = $this->terminal->getWidth() - 44;
						$progress->setFormat($progress::getFormatDefinition($this->determineBestProgressFormat()) . '  ' . substr($dockerSteps[$step] ?? '', 0, $stepMessageLength));
						$progress->setProgress($step);
					}
				}
			}
		});

		if (!$process->isSuccessful()) {
			$this->out->writeln('');
			throw new ProcessFailedException($process);

		} else {
			$progress->finish();
			$this->out->writeln("\n");
		}

		$this->out->writeln(sprintf('<info>Successfully built image %s</info>', $imageName));

		return $imageName;
	}

	/**
	 * @return string[]
	 */
	private function buildDockerFile(Job $job): array
	{
		$projectTmpDir = $this->getProjectTmpDir($job);

		$this->copyProject($job, $projectTmpDir);
		$this->writeDockerIgnore($projectTmpDir);

		$dockerBuild = [];
		$dockerBuild[] = sprintf('FROM %s:%s', self::PHP_BASE_IMAGE, $job->getPhpVersion());
		foreach ($job->getEnv() as $key => $val) {
			$dockerBuild[] = sprintf('ENV %s %s', $key, $val);
		}

//		$userComposerCache = getenv('HOME') . '/.composer/cache';
//		if (file_exists($userComposerCache)) {
//			$dockerBuild[] = sprintf('COPY %s /usr/local/share/composer/cache', $userComposerCache);
//		}

		$dockerBuild[] = sprintf('COPY src/ /build');
		$dockerBuild[] = 'WORKDIR /build';

		foreach ($job->getBeforeInstallScripts() as $script) {
			$dockerBuild[] = sprintf('RUN %s', $script);
		}

		foreach ($job->getInstallScripts() as $script) {
			$dockerBuild[] = sprintf('RUN %s', $script);
		}

		foreach ($job->getBeforeScripts() as $script) {
			$dockerBuild[] = sprintf('RUN %s', $script);
		}

		$entryPointFile = $this->writeEntryPoint($job, $projectTmpDir);
		$dockerBuild[] = sprintf('COPY %s /usr/local/bin/', basename($entryPointFile));
		$dockerBuild[] = sprintf('CMD ["/usr/local/bin/%s"]', basename($entryPointFile));

		$dockerFileContents = implode("\n", $dockerBuild);
		file_put_contents($this->getDockerFile($job), $dockerFileContents);

		return $dockerBuild;
	}

	private function getProjectTmpDir(Job $job): string
	{
		$dir = $this->tempDir . '/' . $job->getProjectName();
		$this->fs->mkdir($dir);
		return $dir;
	}

	private function getDockerFile(Job $job): string
	{
		return $this->getProjectTmpDir($job) . '/Dockerfile.' . $job->getId();
	}

	private function copyProject(Job $job, string $projectTmpDir): void
	{
		$this->fs->remove($projectTmpDir . '/src/');
		foreach ($this->findProjectFiles($job->getProjectDir()) as $file) {
			$targetDir = $projectTmpDir . '/src/' . $file->getRelativePathname();
			if ($file->isDir()) {
				$this->fs->mirror($file->getPathname(), $targetDir);
			} else {
				$this->fs->copy($file->getPathname(), $targetDir);
			}
		}
	}

	/**
	 * @param string $projectDir
	 * @return \Traversable|\Symfony\Component\Finder\SplFileInfo[]
	 */
	private function findProjectFiles(string $projectDir): \Traversable
	{
		$gitFilesProcess = (new Process('git ls-files', $projectDir))->mustRun();
		$projectFiles = Strings::split(trim($gitFilesProcess->getOutput()), '~[\n\r]+~');

		$result = [];
		foreach ($projectFiles as $file) {
			$relativePath = (strpos($file, DIRECTORY_SEPARATOR) !== false)
				? dirname($file)
				: '';
			$result[] = new SplFileInfo($projectDir . '/' . $file, $relativePath, $file);
		}

		$composerLock = $projectDir . '/composer.lock';
		if (file_exists($composerLock)) {
			$result[] = new SplFileInfo($composerLock, '', basename($composerLock));
		}

		return new \ArrayIterator($result);
	}

	private function writeDockerIgnore(string $projectTmpDir): void
	{
		$ignore = [
			'src/.git',
			'src/vendor',
		];
		file_put_contents($projectTmpDir . '/.dockerignore', implode("\n", $ignore));
	}

	private function writeEntryPoint(Job $job, string $projectTmpDir): string
	{
		$cmd = ['#!/bin/bash', 'set -e', ''];
		foreach ($job->getScripts() as $script) {
			$cmd[] = sprintf('echo "";echo "";echo %s ;echo "";', escapeshellarg('> ' . $script));
			$cmd[] = $script . "\n";
		}
		$entryPointFile = $projectTmpDir . '/travis-entrypoint';
		file_put_contents($entryPointFile, implode("\n", $cmd));
		$this->fs->chmod($entryPointFile, 0755);
		return $entryPointFile;
	}

	private function determineBestProgressFormat()
	{
		switch ($this->out->getVerbosity()) {
			// OutputInterface::VERBOSITY_QUIET: display is disabled anyway
			case OutputInterface::VERBOSITY_VERBOSE:
				return 'verbose';
			case OutputInterface::VERBOSITY_VERY_VERBOSE:
				return 'very_verbose';
			case OutputInterface::VERBOSITY_DEBUG:
				return 'debug';
			default:
				return 'normal';
		}
	}

}
