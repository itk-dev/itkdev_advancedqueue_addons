<?php

declare(strict_types=1);

namespace Drupal\itkdev_advancedqueue_addons\Drush\Commands;

use Consolidation\OutputFormatters\FormatterManager;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Formatters\FormatterTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

// phpcs:disable Drupal.Commenting.ClassComment.Missing
#[AsCommand(
  name: self::NAME,
)]
#[CLI\Formatter(returnType: RowsOfFields::class, defaultFormatter: 'table')]
final class ListJobsCommand extends BaseCommand {
  use AutowireTrait;
  use FormatterTrait;

  // Command name matching the advancedqueue command naming.
  public const string NAME = 'advancedqueue:queue:list:jobs';

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    LoggerInterface $logger,
    private readonly Connection $connection,
    // Used in FormatterTrait.
    private readonly FormatterManager $formatterManager,
  ) {
    parent::__construct($entityTypeManager, $logger);
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->setDescription('List jobs in a queue.')
      ->addArgument('queue-id', InputArgument::REQUIRED, description: 'The queue ID.')
      ->addArgument('job-id', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, description: 'An optional list of job IDs.')
      ->addOption('show-payload', description: 'Show payload')
      ->addOption('search', NULL, InputOption::VALUE_REQUIRED, description: 'Search for a value, by path or by a JSON object')
      ->addOption('limit', NULL, InputOption::VALUE_REQUIRED, description: 'Maximum number of jobs to return (at most 100)');
  }

  /**
   * {@inheritdoc}
   */
  public function execute(InputInterface $input, OutputInterface $output): int {
    $data = $this->doExecute(
      queueId: $input->getArgument('queue-id'),
      jobIds: $input->getArgument('job-id'),
      search: $input->getOption('search'),
      limit: (int) $input->getOption('limit'),
      showPayload: $input->getOption('show-payload'),
      format: $input->getOption('format'),
    );
    $this->writeFormattedOutput($input, $output, $data);

    return self::SUCCESS;
  }

  /**
   * Do what must be done.
   *
   * @phpstan-param list<string> $jobIds
   */
  public function doExecute(string $queueId, ?array $jobIds = [], ?string $search = NULL, ?int $limit = 1, ?bool $showPayload = FALSE, ?string $format = 'json'): RowsOfFields {
    [$backend] = $this->loadQueueBackend($queueId);

    // Build the query by hand to use JSON functions (cf.
    // https://www.drupal.org/project/drupal/issues/3378275)
    $query = 'SELECT job_id FROM {advancedqueue} WHERE queue_id = :queue_id';
    $params = [
      ':queue_id' => $queueId,
    ];
    if (count($jobIds) > 0) {
      $query .= ' AND job_id IN (:job_ids[])';
      $params[':job_ids[]'] = $jobIds;
    }
    if (NULL !== $search) {
      $obj = json_decode($search, flags: JSON_THROW_ON_ERROR);
      if ($obj && is_object($obj)) {
        // Search by JSON object, e.g
        // --search '{"submissionId": "1"}'
        // https://mariadb.com/docs/server/reference/sql-functions/special-functions/json-functions/json_contains
        $query .= ' AND JSON_CONTAINS(payload, JSON_EXTRACT(:value, \'$\'))';
        $params[':value'] = json_encode($obj);
      }
      elseif (preg_match('/^(?P<path>[^=]+)=(?P<value>.+)/', $search, $matches)) {
        // Search by path and value, e.g
        // --search handlerSettings.handler_id=fordelingskomponent_sf2900_1
        // https://mariadb.com/docs/server/reference/sql-functions/special-functions/json-functions/json_extract
        $query .= ' AND JSON_EXTRACT(payload, :path) = :value';
        $params[':path'] = '$.' . $matches['path'];
        $params[':value'] = $matches['value'];
      }
      else {
        // Search by value, e.g
        // --search fordelingskomponent_sf2900_1
        // https://mariadb.com/docs/server/reference/sql-functions/special-functions/json-functions/json_search
        $query .= ' AND JSON_SEARCH(payload, \'one\', :value) IS NOT NULL';
        $params[':value'] = $search;
      }
    }
    // Clamp limit between 1 and 100 (both inclusive).
    $limit = max(max(1, count($jobIds)), min(100, $limit));
    // If job IDs are specified, we allow listing them all.
    if (count($jobIds) > 0) {
      $limit = max($limit, count($jobIds));
    }
    // @todo Can we pass limit as a parameter?
    $query .= ' ORDER BY job_id DESC LIMIT ' . $limit;

    $jobIds = $this->connection->query($query, $params)->fetchCol();

    $rows = new RowsOfFields([]);

    if (empty($jobIds)) {
      $this->logger->notice('No jobs found.');

      return $rows;
    }

    /** @var \Drupal\advancedqueue\Job[] $jobs */
    $jobs = array_map(static fn(string $id) => $backend->loadJob($id), $jobIds);

    foreach ($jobs as $job) {
      $row = [
        'ID' => $job->getId(),
        'State' => $job->getState(),
        'Message' => $job->getMessage(),
        'Processed time' => $this->formatDateTime($job->getProcessedTime()),
        'Available time' => $this->formatDateTime($job->getAvailableTime()),
        'Expires time' => $this->formatDateTime($job->getExpiresTime()),
        'Num retries' => $job->getNumRetries(),
      ];
      if ($showPayload) {
        $payload = $job->getPayload();
        $row['Payload'] = match ($format) {
          'json', 'yaml' => $payload,
          default => json_encode($payload, flags: JSON_THROW_ON_ERROR),
        };
      }
      $rows->append($row);
    }

    if (1 === $limit) {
      $this->logger->notice('Only one job listed. Use `--limit` to show more.');
    }

    return $rows;
  }

}
