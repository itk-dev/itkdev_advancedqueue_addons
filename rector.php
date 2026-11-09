<?php
// This file is copied from config/drupal-module-ddev/rector.php in https://github.com/itk-dev/devops_itkdev-docker.
// Feel free to edit the file, but consider making a pull request if you find a general issue with the file.

/**
 * @file
 * Rector config.
 */

declare(strict_types=1);

use DrupalRector\Set\Drupal11SetList;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
  ->withPaths([
    __DIR__ . '/src',
  ])
  ->withSets([
    Drupal11SetList::DRUPAL_114,
  ])
  ->withPhpSets()
  ->withTypeCoverageLevel(0);
