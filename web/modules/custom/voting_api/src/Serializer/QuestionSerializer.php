<?php

declare(strict_types=1);

namespace Drupal\voting_api\Serializer;

use Drupal\voting\Entity\OptionInterface;
use Drupal\voting\Entity\QuestionInterface;
use Drupal\voting\Service\QuestionResults;

/**
 * Turns domain objects into plain arrays for the JSON API.
 *
 * Deliberately dumb: no access checks, no business rules, no I/O.
 */
final class QuestionSerializer {

  /**
   * Compact representation used in listings.
   */
  public function summary(QuestionInterface $question, int $optionCount): array {
    return [
      'id' => $question->getIdentifier(),
      'title' => $question->getTitle(),
      'description' => $question->getDescription(),
      'show_results' => $question->showsResults(),
      'options_count' => $optionCount,
    ];
  }

  /**
   * Full representation with options.
   *
   * @param \Drupal\voting\Entity\QuestionInterface $question
   *   The question.
   * @param \Drupal\voting\Entity\OptionInterface[] $options
   *   The question options in display order.
   */
  public function detail(QuestionInterface $question, array $options): array {
    return [
      'id' => $question->getIdentifier(),
      'title' => $question->getTitle(),
      'description' => $question->getDescription(),
      'show_results' => $question->showsResults(),
      'options' => array_values(array_map($this->option(...), $options)),
    ];
  }

  /**
   * One answer option.
   */
  public function option(OptionInterface $option): array {
    return [
      'id' => (int) $option->id(),
      'title' => $option->getTitle(),
      'description' => $option->getDescription(),
      'image_url' => $option->getImageUrl(),
    ];
  }

  /**
   * Results as seen by the current account.
   */
  public function results(QuestionResults $results): array {
    $options = [];
    foreach ($results->options as $id => $option) {
      $row = [
        'id' => (int) $id,
        'title' => $option->getTitle(),
      ];
      if ($results->countsVisible()) {
        $row['votes'] = $results->getVotes($option);
        $row['percentage'] = $results->getPercentage($option);
      }
      $options[] = $row;
    }

    return [
      'question_id' => $results->question->getIdentifier(),
      'results_visible' => $results->countsVisible(),
      'total_votes' => $results->getTotal(),
      'your_vote' => $results->userOptionId,
      'options' => $options,
    ];
  }

}
