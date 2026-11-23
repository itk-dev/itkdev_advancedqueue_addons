<?php

declare(strict_types=1);

namespace Drupal\Tests\itkdev_advancedqueue_addons\Functional;

use Drupal\advancedqueue\Entity\Queue;
use Drupal\advancedqueue\Entity\QueueInterface;
use Drupal\advancedqueue\Job;
use Drupal\Tests\BrowserTestBase;
use Drush\TestTraits\DrushTestTrait;

/**
 * Test for JobSetPayloadValueCommand.
 */
class JobSetPayloadValueCommandTest extends BrowserTestBase {
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
   * Test set value.
   */
  public function testSetValue(): void {
    $getPayload = function () {
      $this->drush('advancedqueue:queue:list:jobs', ['first_queue', 1], ['format' => 'json', 'show-payload' => TRUE]);
      $output = $this->getOutputFromJSON();

      return $output[0]['Payload'];
    };

    $payload = [
      'id' => 'the first job',
    ];
    $job = Job::create(type: 'simple', payload: $payload);
    $this->firstQueue->enqueueJob($job);

    $payload = $getPayload();
    $this->assertArrayHasKey('id', $payload);
    $this->assertArrayNotHasKey('value', $payload);

    $this->drush('advancedqueue:queue:job:set-payload-value', ['first_queue', 1], [
      'set' => 'value=87',
    ]);

    $payload = $getPayload();
    $this->assertArrayHasKey('id', $payload);
    $this->assertArrayHasKey('value', $payload);
    $this->assertSame('87', $payload['value']);

    $this->drush('advancedqueue:queue:job:set-payload-value', ['first_queue', 1], [
      'set-int' => 'value=87',
    ]);

    $payload = $getPayload();
    $this->assertArrayHasKey('id', $payload);
    $this->assertArrayHasKey('value', $payload);
    $this->assertSame(87, $payload['value']);
  }

}
