<?php

namespace LocalGovDrupal\GithubWorkflowManager;

use Symfony\Component\Yaml\Yaml;

/**
 * Simple config wrapper.
 */
class Config {

  /**
   * Config array.
   */
  protected array $config = [];

  /**
   * Initialise a config object.
   *
   * @param $path string
   *   Path to YAML config file.
   */
  function __construct(string $path) {

    $this->config = Yaml::parseFile($path);
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

  /**
   * Get branch name.
   *
   * @return string
   */
  public function get_branch(): string {

    return $this->config['default_branch_prefix'] . date($this->config['default_branch_date_prefix']);
  }

}
