<?php

namespace DrupalArtifactBuilder;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Generates an artifact from a site already that is already setup.
 */
class BaseCommand extends Command {

  protected static $defaultName = 'build';

  const ARTIFACT_FOLDER = 'deploy-artifact';

  const ARTIFACT_REPOSITORY_FOLDER = 'deploy-artifact-repository';

  const FILES_TO_CLEAN = [
    'CHANGELOG.txt',
    'COPYRIGHT.txt',
    'INSTALL.txt',
    'INSTALL.mysql.txt',
    'INSTALL.pgsql.txt',
    'INSTALL.sqlite.txt',
    'LICENSE.txt',
    'README.txt',
    'CHANGELOG.txt',
    'UPDATE.txt',
    'USAGE.txt',
    'PATCHES.txt',
  ];

  /**
   * Branch where the artifact will be created.
   *
   * Its value will be set with the current branch.
   *
   * @var string
   */
  protected string $branch;

  /**
   * URL / SSH of the repository.
   *
   * @var string
   */
  protected string $repository;

  /**
   * Extra paths which will be used to generate the artifact.
   */
  protected ?string $extraPaths;

  /**
   * Folder with the codebase.
   *
   * @var string
   */
  protected string $rootFolder;

  /**
   * Used to show messages during the artifact building.
   *
   * @var OutputInterface
   */
  protected OutputInterface $output;

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    parent::configure();
    $this->addOption('extra-paths', 'ef', InputOption::VALUE_OPTIONAL, 'Separated by commas list of extra paths that must be copied.');
  }

  /**
   * {@inheritdoc}
   */
  protected function initialize(InputInterface $input, OutputInterface $output) {
    // Variables initialization.
    $this->output = $output;
    $this->rootFolder = getcwd();
    $this->extraPaths = $input->getOption('extra-paths');

    // Assert the site is working okay before starting to create the artifact
    $this->assertRootLocation();
    $this->assertArtifactContentIsClean();
  }

  /**
   * Runs a shell command.
   *
   * @param string $command
   *   Command.
   *
   * @return Process
   *   It can be used to obtain the command output if needed.
   *
   * @throws ProcessFailedException
   *   When the command fails.
   */
  protected function runCommand(string $command) {
    $process = Process::fromShellCommandline($command);
    $process->setTimeout(300);
    $process->run();
    if (!$process->isSuccessful()) {
      throw new ProcessFailedException($process);
    }
    return $process;
  }

  /**
   * Logs that will show the user the artifact building progress.
   *
   * @param string $message
   *   Message.
   */
  protected function log(string $message) {
    $this->output->writeln(sprintf('[-->] %s', $message));
  }

  /**
   * Get the name of the codebase current branch.
   *
   * @return string
   *   Branch name.
   */
  protected function getCurrentBranch() {
    $branch = trim($this->runCommand('echo ${GIT_BRANCH:-$(git branch --show-current)}')->getOutput());
    if (empty($branch)) {
      throw new \RuntimeException("Could not detect the selected branch. Either you didn't set GIT_BRANCH environment variable or you are in deatached mode");
    }

    return $branch;
  }

  /**
   * Assert the script is launched inside a codebase and not in an arbitrary folder.
   */
  protected function assertRootLocation() {
    if (!file_exists('docroot') && !file_exists('web')) {
      throw new \RuntimeException('It seems this command has not been launched the repository root folder. Please run it from root folder.');
    }

    foreach (['config', 'composer.json'] as $path) {
      if (!file_exists($path)) {
        throw new \RuntimeException('It seems this command has not been launched the repository root folder. Please run it from root folder.');
      }
    }
  }

  /**
   * Assert the repository does not contains changes / untracked files.
   *
   * This is only checked in the artifact managed files. Files that are
   * not added to the artifacct are ignored.
   *
   * @throws \Exception
   */
  protected function assertArtifactContentIsClean() {
    $artifact_content = array_unique(array_merge(
      [$this->calculateDocrootFolder()],
      $this->getRequiredFiles(),
      $this->getSymlinks(),
      $this->getExtraPaths(),
    ));
    $filter_artifact_command = sprintf('grep -E "^(%s)"', implode('|', $artifact_content));
    $git_status_command = sprintf("git status -s | awk '{print $2}' | %s", $filter_artifact_command);
    $num_changes = (int) trim($this->runCommand(sprintf("%s | wc -l", $git_status_command))->getOutput());
    if ($num_changes > 0) {
      $files_changed = trim($this->runCommand($git_status_command)->getOutput());
      throw new \Exception("There are changes in the repository (changed and/or untracked files), please run this artifact generation script with folder tree clean. Files changed: \n $files_changed");
    }
  }

  /**
   * Calculate where is the docroot folder.
   *
   * @return string
   *   Docroot folder location.
   */
  protected function calculateDocrootFolder() {
    foreach (['docroot', 'web'] as $docrootFolder) {
      if (file_exists($docrootFolder) && !is_link($docrootFolder)) {
        return $docrootFolder;
      }
    }
    throw new \Exception('Docroot folder not found');
  }

  /**
   * Get the file or folders that are required to add to the artifact.
   *
   * @return string[]
   *   Relative path.
   */
  protected function getRequiredFiles() {
    return [
      'config',
      'drush',
      'vendor',
      'scripts',
      'composer.json',
      'composer.json',
    ];
  }

  /**
   * Get the symlinks that may be commited to the artifact.
   *
   * @return string[]
   *   Relative path of the symlinks.
   */
  protected function getSymlinks() {
    return ['docroot', 'web', 'public_html'];
  }

  /**
   * Extra file/folders that will be added to the artifact.
   *
   * @return array|string[]
   *   Relative path to the file or folder.
   */
  protected function getExtraPaths() {
    return !empty($this->extraPaths) ? explode(',', $this->extraPaths) : [];
  }

}
