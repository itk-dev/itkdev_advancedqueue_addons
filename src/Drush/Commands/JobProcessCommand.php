<?php

declare(strict_types=1);

namespace Drupal\itkdev_advancedqueue_addons\Drush\Commands;

use Drupal\advancedqueue\ProcessorInterface;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\AutowireTrait;
use Drush\Style\DrushStyle;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// phpcs:disable Drupal.Commenting.ClassComment.Missing
#[AsCommand(
  name: self::NAME,
)]
final class JobProcessCommand extends BaseCommand {
  use AutowireTrait;

  // Command name matching the advancedqueue command naming.
  public const string NAME = 'advancedqueue:queue:job:process';

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    LoggerInterface $logger,
    #[Autowire(service: 'advancedqueue.processor')]
    private readonly ProcessorInterface $processor,
  ) {
    parent::__construct($entityTypeManager, $logger);
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->setDescription('Process job in a queue.')
      ->addArgument('queue-id', InputArgument::REQUIRED, description: 'The queue ID.')
      ->addArgument('job-id', InputArgument::REQUIRED | InputArgument::IS_ARRAY, description: 'A list of job IDs.')
      ->addOption('show-payload', description: 'Show payload');
  }

  /**
   * {@inheritdoc}
   */
  public function execute(InputInterface $input, OutputInterface $output): int {
    $this->doExecute(
      io: new DrushStyle($input, $output),
      queueId: $input->getArgument('queue-id'),
      jobIds: $input->getArgument('job-id'),
      showPayload: $input->getOption('show-payload'),
    );

    return self::SUCCESS;
  }

  /**
   * Do what must be done.
   *
   * @phpstan-param list<string> $jobIds
   */
  public function doExecute(DrushStyle $io, string $queueId, array $jobIds, ?string $search = NULL, ?int $limit = 1, ?bool $showPayload = FALSE): void {
    [$backend, $queue] = $this->loadQueueBackend($queueId);
    foreach ($jobIds as $jobId) {
      /** @var \Drupal\advancedqueue\Job $job */
      $job = $backend->loadJob($jobId);

      if ($showPayload) {
        $io->section('Payload');
        $io->writeln(Yaml::encode($job->getPayload()));
      }

      $question = dt('Really process job %id', ['%id' => $job->getId()]);
      if (!$io->confirm($question, default: FALSE)) {
        continue;
      }

      $this->processor->processJob($job, $queue);
    }
  }

}
