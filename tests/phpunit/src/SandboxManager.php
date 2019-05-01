<?php

namespace Acquia\Blt\Tests;

use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Manage BLT testing sandbox.
 */
class SandboxManager {

  /** @var \Symfony\Component\Filesystem\Filesystem*/
  protected $fs;
  protected $bltDir;
  protected $bltRequireDevPackageDir;
  protected $sandboxMaster;
  protected $sandboxInstance;
  /** @var \Symfony\Component\Console\Output\ConsoleOutput*/
  protected $output;
  protected $tmp;

  /**
   * SandboxManager constructor.
   */
  public function __construct() {
    $this->output = new ConsoleOutput();
    $this->fs = new Filesystem();
    $this->tmp = sys_get_temp_dir();
    $this->sandboxMaster = $this->tmp . "/blt-sandbox-master";
    $this->sandboxInstance = $this->tmp . "/blt-sandbox-instance";
    $this->bltRequireDevPackageDir = $this->tmp . '/blt-require-dev';
    $this->bltDir = realpath(dirname(__FILE__) . '/../../../');
  }

  /**
   * Ensures that sandbox master exists and is up to date.
   */
  public function bootstrap() {
    $this->output->writeln("Bootstrapping BLT testing framework...");
    $recreate_master = getenv('BLT_RECREATE_SANDBOX_MASTER');
    if (!file_exists($this->sandboxMaster) || $recreate_master) {
      $this->output->writeln("<comment>To prevent recreation of sandbox master on each bootstrap, set BLT_RECREATE_SANDBOX_MASTER=0</comment>");
      $this->createSandboxMaster();
    }
    else {
      $this->output->writeln("<comment>Skipping master sandbox creation, BLT_RECREATE_SANDBOX_MASTER is disabled.");
    }
  }

  /**
   * Creates a new master sandbox.
   */
  public function createSandboxMaster() {
    $this->output->writeln("Creating master sandbox in <comment>{$this->sandboxMaster}</comment>...");
    $fixture = $this->bltDir . "/tests/phpunit/fixtures/sandbox";

    $this->fs->remove($this->sandboxMaster);
    $this->fs->mirror($fixture, $this->sandboxMaster);
    $this->fs->copy($this->bltDir . '/subtree-splits/blt-project/composer.json', $this->sandboxMaster . '/composer.json');

    $this->createBltRequireDevPackage();
    $this->updateSandboxMasterBltRepoSymlink();
    $this->installSandboxMasterDependencies();
    $this->removeSandboxInstance();
  }

  /**
   * Removes an existing sandbox instance.
   */
  public function removeSandboxInstance() {
    if (file_exists($this->sandboxInstance)) {
      $this->debug("Removing sandbox instance...");
      $this->makeSandboxInstanceWritable();
      $process = new Process("rm -r " . $this->sandboxInstance);
      $process->run();
    }
  }

  /**
   * Outputs debugging message.
   *
   * @param $message
   */
  public function debug($message) {
    if (getenv('BLT_PRINT_COMMAND_OUTPUT')) {
      $this->output->writeln($message);
    }
  }

  /**
   * Makes sandbox instance writable.
   */
  public function makeSandboxInstanceWritable() {
    $sites_dir = $this->sandboxInstance . "/docroot/sites";
    if (file_exists($sites_dir)) {
      $this->fs->chmod($sites_dir, 0755, 0000, TRUE);
    }
  }

  /**
   * Creates a new sandbox instance using master as a reference.
   *
   * This will not overwrite existing files. Will delete files in destination
   * that are not in source.
   */
  public function refreshSandboxInstance() {
    try {
      $this->makeSandboxInstanceWritable();
      // TODO: Currently refreshSandboxInstance() is never called. If we start
      // using it, note that copySandboxMasterToInstance() doesn't remove files.
      $this->copySandboxMasterToInstance();
      chdir($this->sandboxInstance);
    }
    catch (\Exception $e) {
      $this->replaceSandboxInstance();
    }
  }

  /**
   * Copies all files and dirs from master sandbox to instance.
   *
   * This is a dumb copy, not an rsync. Existing files won't be deleted.
   */
  protected function copySandboxMasterToInstance() {
    $this->debug("Copying sandbox master to sandbox instance...");
    $process = new Process("cp -r " . $this->sandboxMaster . " " . $this->sandboxInstance);
    $process->run();
  }

  /**
   * Overwrites all files in sandbox instance.
   */
  public function replaceSandboxInstance() {
    $this->removeSandboxInstance();
    $this->copySandboxMasterToInstance();
  }

  /**
   * @return mixed
   */
  public function getSandboxInstance() {
    return $this->sandboxInstance;
  }

  /**
   * Updates composer.json in sandbox master to reference BLT via symlink.
   */
  protected function updateSandboxMasterBltRepoSymlink() {
    $composer_json_path = $this->sandboxMaster . "/composer.json";
    $composer_json_contents = json_decode(file_get_contents($composer_json_path));
    $composer_json_contents->repositories->blt = (object) [
      'type' => 'path',
      'url' => $this->bltDir,
      'options' => [
        'symlink' => TRUE,
      ],
    ];
    $composer_json_contents->require->{'acquia/blt'} = '*@dev';
    $composer_json_contents->repositories->{'blt-require-dev'} = (object) [
      'type' => 'path',
      'url' => $this->bltRequireDevPackageDir,
      'options' => [
        'symlink' => TRUE,
      ],
    ];
    $composer_json_contents->{'require-dev'}->{'acquia/blt-require-dev'} = '*@dev';
    $this->fs->dumpFile($composer_json_path,
      json_encode($composer_json_contents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  }

  /**
   * Installs composer dependencies in sandbox master dir.
   */
  protected function installSandboxMasterDependencies() {
    $command = '';
    $drupal_core_version = getenv('DRUPAL_CORE_VERSION');
    if ($drupal_core_version && $drupal_core_version != 'default') {
      $command .= 'composer require "drupal/core:' . $drupal_core_version . '" --no-update --no-interaction && ';
    }
    $command .= 'composer install --prefer-dist --no-progress --no-suggest';

    $process = new Process($command, $this->sandboxMaster);
    $process->setTimeout(60 * 60);
    $process->run(function ($type, $buffer) {
      $this->output->write($buffer);
    });
    if (!$process->isSuccessful()) {
      throw new \Exception("Composer installation failed.");
    }
  }

  /**
   * Create temporary copy of blt-require-dev.
   *
   * This new dir will be used as the reference path for acquia/blt-require-dev
   * in local testing. It cannot be a subdir of blt because Composer cannot
   * reference a package nested within another package.
   */
  protected function createBltRequireDevPackage() {
    $this->fs->mirror($this->bltDir . '/subtree-splits/blt-require-dev',
      $this->bltRequireDevPackageDir);
  }

}
