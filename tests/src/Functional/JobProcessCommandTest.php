<?php

declare(strict_types=1);

namespace Drupal\Tests\itkdev_advancedqueue_addons\Functional;

use Drupal\advancedqueue\Entity\Queue;
use Drupal\advancedqueue\Entity\QueueInterface;
use Drupal\advancedqueue\Job;
use Drupal\Tests\BrowserTestBase;
use Drush\TestTraits\DrushTestTrait;

/**
 * Test for JobProcessCommand.
 */
class JobProcessCommandTest extends BrowserTestBase {
  use DrushTestTrait;

  /**
   * The first tested queue.
   */
  protected QueueInterface $firstQueue;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'advancedqueue',
    'advancedqueue_test',
    'itkdev_advancedqueue_addons',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->firstQueue = Queue::create([
      'id' => 'first_queue',
      'label' => 'First queue',
      'backend' => 'database',
      'backend_configuration' => [
        'lease_time' => 5,
      ],
    ]);
    $this->firstQueue->save();
  }

  /**
   * Test list jobs.
   */
  public function testJobProcess(): void {
    $payload = [
      'id' => 'the first job',
      'expected_state' => 'success',
    ];
    $job = Job::create(type: 'flexible', payload: $payload);
    $this->firstQueue->enqueueJob($job);

    $payload = [
      'id' => 'the second job',
      'expected_exception' => 'The job failed',
    ];
    $job = Job::create(type: 'flexible', payload: $payload);
    $this->firstQueue->enqueueJob($job);

    $payload = [
      'id' => 'a third job',
      'expected_state' => 'success',
      'expected_message' => 'The third time is the charm',
    ];
    $job = Job::create(type: 'flexible', payload: $payload);
    $this->firstQueue->enqueueJob($job);

    $this->drush(
      'advancedqueue:queue:job:process',
      ['first_queue', 1],
      ['yes' => TRUE],
    );
    $output = $this->getSimplifiedOutput();
    $this->assertMatchesRegularExpression('/^1 success$/m', $output);

    $this->drush(
      'advancedqueue:queue:job:process',
      ['first_queue', 1, 2],
      ['yes' => TRUE],
    );
    $output = $this->getSimplifiedOutput();
    $this->assertMatchesRegularExpression('/^1 success$/m', $output);
    $this->assertMatchesRegularExpression('/^2 failure/m', $output);
    $this->assertMatchesRegularExpression('/^2 failure: The job failed$/m', $output);

    $this->drush(
      'advancedqueue:queue:job:process',
      ['first_queue', 1, 2],
      ['yes' => TRUE, 'show-payload' => TRUE],
    );
    $output = $this->getSimplifiedOutput();
    $this->assertMatchesRegularExpression('/^1 {.+} success$/m', $output);
    $this->assertMatchesRegularExpression('/^2 {.+} failure/m', $output);
    $this->assertMatchesRegularExpression('/^2 {.+} failure: The job failed$/m', $output);

    $this->drush(
      'advancedqueue:queue:job:process',
      ['first_queue', 3],
      ['yes' => TRUE],
    );
    $output = $this->getSimplifiedOutput();
    $this->assertMatchesRegularExpression('/^3 success: The third time is the charm$/m', $output);
  }

}
