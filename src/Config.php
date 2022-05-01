<?php

namespace LocalGovDrupal\GithubWorkflowManager;

/**
 * Stupidly simple config wrapper.
 */
class Config {

  /**
   * Config array.
   */
  protected array $config = [];

  /**
   * Initialise a config object.
   */
  function __construct() {

    // @todo Don't hard code this stuff.
    $this->config = [
      'organization' => 'localgovdrupal',
      'drupal_versions' => [
        '~9.3',
      ],
      'php_versions' => [
        '7.4',
        '8.1',
      ],
      'base_projects' => [
        'localgov_project' => [
          'template' => 'test_localgov.yml',
          'workflow_file' => '.github/workflows/test.yml',
          'versions' => [
            '2.x',
          ],
        ],
      ],
      'default_branch_name' => 'fix/github-workflow-update-' . date('Y-m-d'),
    ];
  }

  /**
   * Get a config option.
   *
   * @param $item string
   *   Config item to get.
   *
   * @return mixed
   *   Returns the config item.
   */
  public function get($item) {

    if (isset($this->config[$item])) {
      return $this->config[$item];
    }

    return NULL;
  }

}
