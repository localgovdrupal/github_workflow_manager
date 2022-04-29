<?php

namespace LocalGovDrupal\GithubWorkflowManager\TemplateRenderer;

/**
 * Interface for template renderers.
 */
interface TemplateRendererInterface {

  /**
   * Render template.
   *
   * @param $template string
   *   Name of template file in template directory.
   *
   * @return string
   *   Returns the rendered template file.
   */
  public function render(string $template): string;

  /**
   * Set variables.
   *
   * @param $vars array
   *   Project variables to transform into template variables.
   *
   * @return array Return an array of template variables.
   *   Return an array of template variables.
   */
  public function set_variables(array $vars): array;

}
