<?php

declare(strict_types=1);

namespace Drupal\itkdev_advancedqueue_addons\Drush\Commands;

use Drush\Attributes as CLI;
use Consolidation\OutputFormatters\FormatterManager;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\advancedqueue\ProcessorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\AutowireTrait;
use Drush\Formatters\FormatterTrait;
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
#[CLI\Formatter(returnType: RowsOfFields::class, defaultFormatter: 'table')]
final class JobProcessCommand extends BaseCommand {
  use AutowireTrait;
  use FormatterTrait;

  // Command name matching the advancedqueue command naming.
  public const string NAME = 'advancedqueue:queue:job:process';

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    LoggerInterface $logger,
    // Used in FormatterTrait.
    private readonly FormatterManager $formatterManager,
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
      ->addArgument('job-id', InputArgument::REQUIRED | InputArgument::IS_ARRAY, description: 'A list of job IDs. Note: Jobs are processed in the order specified and not in queue order.')
      ->addOption('show-payload', description: 'Show payload');
  }

  /**
   * {@inheritdoc}
   */
  public function execute(InputInterface $input, OutputInterface $output): int {
    $data = $this->doExecute(
      io: new DrushStyle($input, $output),
      queueId: $input->getArgument('queue-id'),
      jobIds: $input->getArgument('job-id'),
      showPayload: $input->getOption('show-payload'), format: $input->getOption('format'),
    );

    $this->writeFormattedOutput($input, $output, $data);

    return self::SUCCESS;
  }

  /**
   * Do what must be done.
   *
   * @phpstan-param list<string> $jobIds
   */
  public function doExecute(DrushStyle $io, string $queueId, array $jobIds, ?bool $showPayload = FALSE, ?string $format = 'json'): RowsOfFields {
    [$backend, $queue] = $this->loadQueueBackend($queueId);

    $rows = new RowsOfFields([]);

    /** @var \Drupal\advancedqueue\Job[] $jobs */
    $jobs = array_map(static fn(string $id) => $backend->loadJob($id), $jobIds);

    foreach ($jobs as $job) {
      $row = [
        'ID' => $job->getId(),
      ];
      if ($showPayload) {
        $payload = $job->getPayload();
        $row['Payload'] = match ($format) {
          'json', 'yaml' => $payload,
          default => json_encode($payload, flags: JSON_THROW_ON_ERROR),
        };
      }

      $question = dt('Really process job %id', ['%id' => $job->getId()]);
      if (!$io->confirm($question, default: FALSE)) {
        $row['Result'] = 'skipped';
      }
      else {
        $result = $this->processor->processJob($job, $queue);
        $row['Result'] = $result->getState();
        if ($result->getMessage()) {
          $row['Result'] .= ': ' . $result->getMessage();
        }
      }

      $rows[] = $row;
    }

    return $rows;
  }

}
