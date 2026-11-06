<!-- markdownlint-disable line-length -->
<!-- This file is copied from config/drupal-module-ddev/README.ddev.md in https://github.com/itk-dev/devops_itkdev-docker. -->
<!-- Feel free to edit the file, but consider making a pull request if you find a general issue with the file. -->
<!-- markdownlint-enable line-length -->

# DDEV

We use [DDEV Drupal Contrib](https://addons.ddev.com/addons/ddev/ddev-drupal-contrib) for development.

The DDEV environment has been created by following the template on
<https://github.com/ddev/ddev-drupal-contrib#install>:

```shell name=ddev-create
ddev config --project-type=drupal11 --docroot=web
ddev add-on get ddev/ddev-drupal-contrib
ddev start
ddev poser --no-interaction
ddev symlink-project
# Detect expected Drupal and PHP versions.
ddev config --update
```
