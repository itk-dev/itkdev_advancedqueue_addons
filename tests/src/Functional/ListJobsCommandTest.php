<?php

declare(strict_types=1);

namespace Drupal\Tests\itkdev_advancedqueue_addons\Functional;

use Drupal\advancedqueue\Entity\Queue;
use Drupal\advancedqueue\Entity\QueueInterface;
use Drupal\advancedqueue\Job;
use Drupal\Tests\BrowserTestBase;
use Drush\TestTraits\DrushTestTrait;

/**
 * Test for ListJobsCommand.
 */
class ListJobsCommandTest extends BrowserTestBase {
  use DrushTestTrait;

  /**
   * The first tested queue.
   */
  protected QueueInterface $firstQueue;

  /**
   * The second tested queue.
   */
  protected QueueInterface $secondQueue;

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

    $this->secondQueue = Queue::create([
      'id' => 'second_queue',
      'label' => 'Second queue',
      'backend' => 'database',
      'backend_configuration' => [
        'lease_time' => 5,
      ],
    ]);
    $this->secondQueue->save();
  }

  /**
   * Test list jobs.
   */
  public function testListJobs(): void {
    $this->drush(
      'advancedqueue:queue:list:jobs',
      ['first_queue'],
      ['format' => 'json'],
    );
    $output = $this->getOutputFromJSON();
    $this->assertSame([], $output);

    $payload = [
      'id' => 'the first job',
    ];
    $job = Job::create(type: 'simple', payload: $payload);
    $this->firstQueue->enqueueJob($job);

    $payload = [
      'id' => 'the second job',
    ];
    $job = Job::create(type: 'simple', payload: $payload);
    $this->firstQueue->enqueueJob($job);

    $this->drush(
      'advancedqueue:queue:list:jobs',
      ['first_queue'],
      ['format' => 'json'],
    );
    $output = $this->getOutputFromJSON();
    $this->assertCount(1, $output);

    $this->drush(
      'advancedqueue:queue:list:jobs',
      ['first_queue'],
      ['format' => 'json', 'limit' => 10],
    );
    $output = $this->getOutputFromJSON();
    $this->assertCount(2, $output);

    $this->drush(
      'advancedqueue:queue:list:jobs',
      ['first_queue', 1],
      ['format' => 'json', 'limit' => 10, 'show-payload' => TRUE],
    );
    $output = $this->getOutputFromJSON();
    $this->assertCount(1, $output);
    $this->assertArrayHasKey('Payload', $output[0]);
    $payload = $output[0]['Payload'];
    $this->assertArrayHasKey('id', $payload);
    $this->assertSame('the first job', $payload['id']);

    $this->drush(
      'advancedqueue:queue:list:jobs',
      ['first_queue', 2],
      ['format' => 'json', 'limit' => 10, 'show-payload' => TRUE],
    );
    $output = $this->getOutputFromJSON();
    $this->assertCount(1, $output);
    $this->assertArrayHasKey('Payload', $output[0]);
    $payload = $output[0]['Payload'];
    $this->assertArrayHasKey('id', $payload);
    $this->assertSame('the second job', $payload['id']);

    $this->drush(
      'advancedqueue:queue:list:jobs',
      ['first_queue', 1, 2],
      ['format' => 'json'],
    );
    $output = $this->getOutputFromJSON();
    $this->assertCount(2, $output);

    $this->drush(
      'advancedqueue:queue:list:jobs',
      ['first_queue', 1, 2],
      ['format' => 'json', 'limit' => 1],
    );
    $output = $this->getOutputFromJSON();
    $this->assertCount(2, $output);
  }

}
