<?php

declare(strict_types=1);

namespace Drupal\canvas_pattern_components_test\Plugin\Adapter;

use Drupal\canvas\Plugin\Adapter\Adapter;
use Drupal\canvas\Plugin\Adapter\AdapterBase;
use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * An adapter that is not provided by Canvas, to see its provider as a dependency.
 */
#[Adapter(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Shout'),
  inputs: [
    'text' => ['type' => 'string'],
  ],
  requiredInputs: ['text'],
  output: ['type' => 'string'],
)]
final class ShoutAdapter extends AdapterBase {

  public const string PLUGIN_ID = 'pattern_test_shout';

  protected string $text;

  public function adapt(): EvaluationResult {
    return new EvaluationResult(\strtoupper($this->text));
  }

}
