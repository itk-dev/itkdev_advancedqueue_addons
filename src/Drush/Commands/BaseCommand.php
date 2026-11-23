<?php

declare(strict_types=1);

namespace Drupal\itkdev_advancedqueue_addons\Drush\Commands;

use Drupal\advancedqueue\Entity\Queue;
use Drupal\advancedqueue\Plugin\AdvancedQueue\Backend\SupportsLoadingJobsInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Exception\RuntimeException;

/**
 * Base command for advanced queue add-on commands.
 */
class BaseCommand extends SymfonyCommand {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly LoggerInterface $logger,
  ) {
    parent::__construct();
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
  protected function loadQueueBackend(string $queueId): array {
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
   * Format a timestamp.
   */
  protected function formatDateTime(int|string $timestamp): ?string {
    return $timestamp ? DrupalDateTime::createFromTimestamp((int) $timestamp)->format(DrupalDateTime::FORMAT) : NULL;
  }

}
