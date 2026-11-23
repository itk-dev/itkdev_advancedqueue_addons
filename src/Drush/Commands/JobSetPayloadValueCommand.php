<?php

declare(strict_types=1);

namespace Drupal\itkdev_advancedqueue_addons\Drush\Commands;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageInterface;
use Drush\Commands\AutowireTrait;
use Drush\Commands\config\ConfigCommands;
use Drush\Exceptions\UserAbortException;
use Drush\Style\DrushStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\OutputStyle;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

// phpcs:disable Drupal.Commenting.ClassComment.Missing
#[AsCommand(
  name: self::NAME,
)]
final class JobSetPayloadValueCommand extends BaseCommand {
  use AutowireTrait;

  // Command name matching the advancedqueue command naming.
  public const string NAME = 'advancedqueue:queue:job:set-payload-value';

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->setDescription('Process job in a queue.')
      ->addArgument('queue-id', InputArgument::REQUIRED, description: 'The queue ID.')
      ->addArgument('job-id', InputArgument::REQUIRED, description: 'The job ID.')
      ->addOption('diff', description: 'Show payload diff')
      ->addOption('set', mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, description: 'Set string value')
      ->addOption('set-int', mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, description: 'Set integer value')
      ->addOption('edit', description: 'Edit payload interactively. Editing will be performed after all set and unset operations have been performed.')
      ->addOption('editor', mode: InputOption::VALUE_REQUIRED, description: 'The editor to use', default: 'vi')
      ->addOption('unset', mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, description: 'Unset value');
  }

  /**
   * {@inheritdoc}
   */
  public function execute(InputInterface $input, OutputInterface $output): int {
    $this->doExecute(
      io: new DrushStyle($input, $output),
      queueId: $input->getArgument('queue-id'),
      jobId: (int) $input->getArgument('job-id'),
      showDiff: $input->getOption('diff'),
      set: $input->getOption('set'),
      setInt: $input->getOption('set-int'),
      edit: $input->getOption('edit'),
      editor: $input->getOption('editor'),
      unset: $input->getOption('unset'),
    );

    return self::SUCCESS;
  }

  /**
   * Do what must be done.
   *
   * @phpstan-param list<string> $set,
   * @phpstan-param list<string> $setInt,
   * @phpstan-param list<string> $unset,
   */
  public function doExecute(
    DrushStyle $io,
    string $queueId,
    int $jobId,
    ?bool $showDiff = FALSE,
    ?array $set = [],
    ?array $setInt = [],
    ?bool $edit = FALSE,
    ?string $editor = 'vi',
    ?array $unset = [],
  ): void {
    [$backend] = $this->loadQueueBackend($queueId);
    try {
      $updateJob = new \ReflectionMethod($backend, 'updateJob');
    }
    catch (\ReflectionException $e) {
      throw new RuntimeException(dt('Cannot update job in queue (%message)', ['%message' => $e->getMessage()]));
    }

    /** @var \Drupal\advancedqueue\Job $job */
    $job = $backend->loadJob((string) $jobId);
    $payload = $job->getPayload();

    foreach ($set as $spec) {
      [$path, $value] = explode('=', $spec, 2);
      NestedArray::setValue($payload, explode('.', $path), $value);
    }
    foreach ($setInt as $spec) {
      [$path, $value] = explode('=', $spec, 2);
      NestedArray::setValue($payload, explode('.', $path), (int) $value);
    }
    foreach ($unset as $path) {
      NestedArray::unsetValue($payload, explode('.', $path));
    }

    if ($edit) {
      $payload = $this->editPayload($payload, $editor, $io);
    }

    $diff = $this->renderDiff($job->getPayload(), $payload, $io);
    if (empty($diff)) {
      $io->info('No changes to apply');

      return;
    }

    $question = dt('Apply the payload changes (use --diff to preview the changes)?');
    if ($showDiff) {
      $io->writeln($diff);
      $question = dt('Apply the listed payload changes?');
    }

    if (!$io->confirm($question)) {
      throw new UserAbortException();
    }

    $job->setPayload($payload);
    try {
      $updateJob->invoke($backend, $job);
    }
    catch (\ReflectionException $e) {
      throw new RuntimeException(dt('Cannot update job: %message', ['%message' => $e->getMessage()]));
    }

  }

  /**
   * Lifted from Drush's config commands.
   *
   * @phpstan-param array<array-key, mixed> $a
   * @phpstan-param array<array-key, mixed> $b
   */
  private function renderDiff(array $a, array $b, OutputStyle $io): string {
    $createConfigStorage = function (array $values): StorageInterface {
      $storage = new MemoryStorage();

      foreach ($values as $key => $value) {
        $storage->write($key, (array) $value);
      }

      return $storage;
    };

    return ConfigCommands::getDiff(
      $createConfigStorage([__FUNCTION__ => $a]),
      $createConfigStorage([__FUNCTION__ => $b]),
      $io,
    );
  }

  /**
   * Edit payload interactively.
   *
   * @phpstan-param array<array-key, mixed> $payload
   *
   * @phpstan-return array<array-key, mixed>
   *
   * @throws \Symfony\Component\Console\Exception\RuntimeException
   */
  private function editPayload(array $payload, string $editor, OutputStyle $io): array {
    try {
      $process = Process::fromShellCommandline(sprintf('command -v %s >/dev/null 2>&1', $editor));
      $process->mustRun();
    }
    catch (\Throwable) {
      throw new RuntimeException(sprintf('Cannot run editor %s', $editor));
    }
    try {
      // @todo Check that we can run editor.
      $prettyPrintedPayload = json_encode($payload, JSON_PRETTY_PRINT);
      // Check that payload down not change when decoded and encoded.
      $bootstrappedPayload = json_decode($prettyPrintedPayload, TRUE);
      if ($bootstrappedPayload !== $payload) {
        $diff = $this->renderDiff(
          $payload,
          $bootstrappedPayload,
          $io,
        );
        throw new RuntimeException(sprintf('Cannot bootstrap payload: %s.', $diff));
      }

      $payloadFilename = tempnam(sys_get_temp_dir(), __FILE__);
      file_put_contents($payloadFilename, $prettyPrintedPayload);
      $process = new Process([$editor, $payloadFilename]);
      $process->setTty(TRUE);
      $process->run();

      if (!$process->isSuccessful()) {
        throw new ProcessFailedException($process);
      }

      $content = file_get_contents($payloadFilename);

      try {
        $editedPayload = json_decode($content, TRUE, flags: JSON_THROW_ON_ERROR);
      }
      catch (\JsonException $jsonException) {
        throw new RuntimeException('Payload must be valid JSON', previous: $jsonException);
      }

      if (!is_array($editedPayload)) {
        throw new RuntimeException(sprintf('Payload must be an array; got %s', var_export($editedPayload, TRUE)));
      }

      return $editedPayload;
    }
    catch (ProcessFailedException $exception) {
      throw new RuntimeException(sprintf('Error editing payload: %s', $exception->getMessage()), previous: $exception);
    } finally {
      if (isset($payloadFilename)) {
        unlink($payloadFilename);
      }
    }
  }

}
