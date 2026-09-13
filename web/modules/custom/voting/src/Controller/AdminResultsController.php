<?php

declare(strict_types=1);

namespace Drupal\voting\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\voting\Entity\QuestionInterface;
use Drupal\voting\Storage\VoteRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Results tab: totals per option plus a counter integrity check.
 */
final class AdminResultsController extends ControllerBase {

  public function __construct(
    private readonly VoteRepositoryInterface $repository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('voting.vote_repository'));
  }

  /**
   * Title callback.
   */
  public function title(QuestionInterface $voting_question): TranslatableMarkup {
    return $this->t('Results of %question', ['%question' => $voting_question->label()]);
  }

  /**
   * Builds the results table.
   */
  public function results(QuestionInterface $voting_question): array {
    $questionId = (int) $voting_question->id();
    $tally = $this->repository->getTally($questionId);
    $raw = $this->repository->countVotesByOption($questionId);
    $total = array_sum($tally);

    $rows = [];
    $mismatches = 0;
    foreach ($voting_question->getOptions() as $id => $option) {
      $counter = $tally[(int) $id] ?? 0;
      $actual = $raw[(int) $id] ?? 0;
      $ok = $counter === $actual;
      $mismatches += $ok ? 0 : 1;
      $rows[] = [
        $option->getTitle(),
        $counter,
        $total > 0 ? $this->t('@percent%', ['@percent' => round($counter * 100 / $total, 1)]) : '0%',
        $actual,
        $ok ? $this->t('OK') : $this->t('Mismatch'),
      ];
    }

    $build['summary'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->formatPlural($total, '1 vote in total.', '@count votes in total.'),
    ];
    if ($mismatches > 0) {
      $this->messenger()->addWarning($this->t('Counters diverge from the raw votes. Run <code>drush voting:integrity --repair</code> to rebuild them.'));
    }
    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Option'),
        $this->t('Votes (counter)'),
        $this->t('Share'),
        $this->t('Votes (raw rows)'),
        $this->t('Integrity'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('This question has no options.'),
      '#cache' => ['max-age' => 0],
    ];

    return $build;
  }

}
