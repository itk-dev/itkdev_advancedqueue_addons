<?php

namespace Drupal\itkdev_advancedqueue_addons\Drush\Commands;

use Drupal\advancedqueue\Entity\Queue;
use Drupal\advancedqueue\Plugin\AdvancedQueue\Backend\SupportsLoadingJobsInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\config\ConfigCommands;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\UserAbortException;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Additional advancedqueue Drush commands.
 */
final class AdvancedQueueCommands extends DrushCommands {
  use AutowireTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  // Command names matching the advancedqueue command naming.
  private const string COMMAND_SET_PAYLOAD_VALUE = 'advancedqueue:queue:job:set-payload-value';

  /**
   * Set job payload value.
   *
   * @phpstan-param array<string, mixed> $options
   *   The command options.
   */
  #[CLI\Command(name: self::COMMAND_SET_PAYLOAD_VALUE)]
  #[CLI\Argument(name: 'queue_id', description: 'The queue ID.')]
  #[CLI\Argument(name: 'job_id', description: 'Job ID')]
  #[CLI\Option(name: 'diff', description: 'Show payload diff')]
  #[CLI\Option(name: 'set', description: 'Set string value')]
  #[CLI\Option(name: 'set-int', description: 'Set integer value')]
  #[CLI\Option(name: 'edit', description: 'Edit payload interactively. Editing will be performed after all set and unset operations have been performed.')]
  #[CLI\Option(name: 'editor', description: 'The editor to use')]
  #[CLI\Option(name: 'unset', description: 'Unset value')]
  #[CLI\Usage(name: self::COMMAND_SET_PAYLOAD_VALUE . ' my_queue 87 --set person.name=James', description: 'Set string value')]
  #[CLI\Usage(name: self::COMMAND_SET_PAYLOAD_VALUE . ' my_queue 87 --set-int person.age=61', description: 'Set integer value')]
  #[CLI\Usage(name: self::COMMAND_SET_PAYLOAD_VALUE . ' my_queue 87 --unset person.address', description: 'Unset value')]
  #[CLI\Usage(name: self::COMMAND_SET_PAYLOAD_VALUE . ' my_queue 87 --set person.name=James --unset person.address --diff', description: 'Perform multiple operations and preview changes')]
  #[CLI\Usage(name: self::COMMAND_SET_PAYLOAD_VALUE . ' my_queue 87 --edit', description: 'Edit payload interactively in vi')]
  #[CLI\Usage(name: self::COMMAND_SET_PAYLOAD_VALUE . ' my_queue 87 --edit --diff', description: 'Edit payload interactively and preview final changes')]
  #[CLI\Usage(name: self::COMMAND_SET_PAYLOAD_VALUE . ' my_queue 87 --set name=test --edit', description: 'Set value and edit result interactively')]
  #[CLI\Usage(name: self::COMMAND_SET_PAYLOAD_VALUE . ' my_queue 87 --edit --editor=nano', description: 'Edit value using nano (for experts!)')]
  public function setJobPayloadValue(
    string $queue_id,
    string $job_id,
    $options = [
      'diff' => FALSE,
      'set' => [],
      'set-int' => [],
      'unset' => [],
      'edit' => FALSE,
      'editor' => 'vi',
    ],
  ): int {
    [$backend] = $this->loadQueueBackend($queue_id);
    try {
      $updateJob = new \ReflectionMethod($backend, 'updateJob');
    }
    catch (\ReflectionException $e) {
      throw new RuntimeException(dt('Cannot update job in queue (%message)', ['%message' => $e->getMessage()]));
    }

    /** @var \Drupal\advancedqueue\Job $job */
    $job = $backend->loadJob($job_id);
    $payload = $job->getPayload();

    foreach ($options['set'] as $spec) {
      [$path, $value] = explode('=', $spec, 2);
      NestedArray::setValue($payload, explode('.', $path), $value);
    }
    foreach ($options['set-int'] as $spec) {
      [$path, $value] = explode('=', $spec, 2);
      NestedArray::setValue($payload, explode('.', $path), (int) $value);
    }
    foreach ($options['unset'] as $path) {
      NestedArray::unsetValue($payload, explode('.', $path));
    }

    if ($options['edit']) {
      $payload = $this->editPayload($payload, editor: $options['editor']);
    }

    $diff = $this->renderDiff($job->getPayload(), $payload);
    if (empty($diff)) {
      $this->io()->info('No changes to apply');

      return self::EXIT_SUCCESS;
    }

    $question = dt('Apply the payload changes (use --diff to preview the changes)?');
    if ($options['diff']) {
      $this->io()->writeln($diff);
      $question = dt('Apply the listed payload changes?');
    }

    if (!$this->io()->confirm($question)) {
      throw new UserAbortException();
    }

    $job->setPayload($payload);
    try {
      $updateJob->invoke($backend, $job);
    }
    catch (\ReflectionException $e) {
      throw new RuntimeException(dt('Cannot update job: %message', ['%message' => $e->getMessage()]));
    }

    return self::EXIT_SUCCESS;
  }

  /**
   * Load a queue's backend.
   *
   * @return array{
   *   0: \Drupal\advancedqueue\Plugin\AdvancedQueue\Backend\BackendInterface&SupportsLoadingJobsInterface,
   *   1: \Drupal\advancedqueue\Entity\QueueInterface,
   *   }
   *   The backend and its queue.
   */
  private function loadQueueBackend(string $queueId): array {
    $queue = $this->entityTypeManager->getStorage('advancedqueue_queue')->load($queueId);
    if (NULL === $queue) {
      throw new RuntimeException(dt('Cannot load queue %id.', ['%id' => $queueId]));
    }
    assert($queue instanceof Queue);

    $backend = $queue->getBackend();
    if (!$backend instanceof SupportsLoadingJobsInterface) {
      throw new RuntimeException(dt('Backend (%backend_class) for %queue does not support loading jobs.', [
        '%backend_class' => $backend::class,
        '%queue' => $queue->label(),
      ]));
    }

    return [$backend, $queue];
  }

  /**
   * Lifted from Drush's config commands.
   *
   * @param array<array-key, mixed> $a
   *   The first value.
   * @param array<array-key, mixed> $b
   *   The second value.
   */
  private function renderDiff(array $a, array $b): string {
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
      $this->output()
    );
  }

  /**
   * Edit payload interactively.
   *
   * @param array<array-key, mixed> $payload
   *   The payload.
   * @param string $editor
   *   The editor to use.
   *
   * @return array<array-key, mixed>
   *   The edited payload.
   *
   * @throws \Symfony\Component\Console\Exception\RuntimeException
   */
  private function editPayload(array $payload, string $editor = 'vi'): array {
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
          $bootstrappedPayload
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
