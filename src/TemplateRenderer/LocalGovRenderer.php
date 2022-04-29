<?php

namespace LocalGovDrupal\GithubWorkflowManager\TemplateRenderer;

/**
 * Render LocalGov Drupal workflow templates.
 */
class LocalGovRenderer extends AbstractRender {

  /**
   * @inheritdoc
   */
  public function set_variables(array $vars): array {

    $this->vars = $vars;
    $this->vars['drupal_versions'] = $this->config->get('drupal_versions');
    $this->vars['localgov_versions'] = $vars['base_versions'];
    $this->vars['php_versions'] = $this->config->get('php_versions');
    $this->vars['project_path'] = match ($vars['project_type']) {
      'drupal-module' => 'web/modules/contrib/' . $vars['repo'],
      'drupal-profile' => 'web/profiles/contrib/' . $vars['repo'],
      'drupal-theme' => 'web/themes/contrib/' . $vars['repo'],
      default =>  ''
    };

    return $this->vars;
  }

}
