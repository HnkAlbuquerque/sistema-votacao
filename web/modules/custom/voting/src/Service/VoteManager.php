<?php

declare(strict_types=1);

namespace Drupal\voting\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\voting\Entity\OptionInterface;
use Drupal\voting\Entity\QuestionInterface;
use Drupal\voting\Event\VoteCastEvent;
use Drupal\voting\Exception\AlreadyVotedException;
use Drupal\voting\Exception\AuthenticationRequiredException;
use Drupal\voting\Exception\InvalidOptionException;
use Drupal\voting\Exception\QuestionInactiveException;
use Drupal\voting\Exception\ResultsUnavailableException;
use Drupal\voting\Exception\VoteRateLimitedException;
use Drupal\voting\Exception\VotingDisabledException;
use Drupal\voting\Exception\VotingException;
use Drupal\voting\Storage\OptionStorageInterface;
use Drupal\voting\Storage\VoteRepositoryInterface;
use Drupal\voting\VotingSettings;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Default implementation of the vote manager.
 */
final class VoteManager implements VoteManagerInterface {

  public const BYPASS_PERMISSION = 'view voting results';

  private const FLOOD_EVENT = 'voting.cast_vote';

  public function __construct(
    private readonly VoteRepositoryInterface $repository,
    private readonly VotingSettings $settings,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FloodInterface $flood,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly LoggerInterface $logger,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function castVote(QuestionInterface $question, int $optionId, AccountInterface $account): QuestionResults {
    $context = [
      '@question' => $question->getIdentifier(),
      '@option' => $optionId,
      '@uid' => $account->id(),
    ];

    if (!$this->settings->isEnabled()) {
      throw $this->reject(new VotingDisabledException(), $context);
    }
    if (!$account->isAuthenticated()) {
      throw $this->reject(new AuthenticationRequiredException(), $context);
    }

    // Rate limit every attempt, valid or not, so that malformed or repeated
    // requests cannot be used to hammer the endpoint for free.
    $uid = (int) $account->id();
    $window = $this->settings->getFloodWindow();
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, $this->settings->getFloodLimit(), $window, (string) $uid)) {
      throw $this->reject(new VoteRateLimitedException(), $context);
    }
    $this->flood->register(self::FLOOD_EVENT, $window, (string) $uid);

    if (!$question->isPublished()) {
      throw $this->reject(new QuestionInactiveException(), $context);
    }

    $option = $this->findOption($question, $optionId);
    if ($option === NULL) {
      throw $this->reject(new InvalidOptionException(), $context);
    }

    $questionId = (int) $question->id();

    // Cheap early exit; the unique key in the repository remains the guard.
    if ($this->repository->hasVoted($questionId, $uid)) {
      throw $this->reject(new AlreadyVotedException(), $context);
    }

    try {
      $this->repository->recordVote($questionId, (int) $option->id(), $uid, $this->time->getRequestTime());
    }
    catch (AlreadyVotedException $e) {
      // Lost a race against a concurrent request of the same user.
      throw $this->reject($e, $context + ['@race' => 'yes']);
    }

    $this->logger->info('Vote recorded on question @question for option @option by user @uid.', $context);
    $this->eventDispatcher->dispatch(new VoteCastEvent($question, $option, $account));

    return $this->buildResults($question, $this->countsVisibleFor($question, $account), (int) $option->id());
  }

  /**
   * {@inheritdoc}
   */
  public function hasVoted(QuestionInterface $question, AccountInterface $account): bool {
    return $account->isAuthenticated()
      && $this->repository->hasVoted((int) $question->id(), (int) $account->id());
  }

  /**
   * {@inheritdoc}
   */
  public function getResults(QuestionInterface $question, AccountInterface $account): QuestionResults {
    if (!$this->settings->isEnabled()) {
      throw new VotingDisabledException();
    }

    $userOptionId = $account->isAuthenticated()
      ? $this->repository->getVotedOptionId((int) $question->id(), (int) $account->id())
      : NULL;

    if (!$account->hasPermission(self::BYPASS_PERMISSION)) {
      if ($userOptionId === NULL) {
        throw ResultsUnavailableException::voteRequired();
      }
      if (!$question->showsResults()) {
        throw ResultsUnavailableException::hidden();
      }
    }

    return $this->buildResults($question, TRUE, $userOptionId);
  }

  /**
   * {@inheritdoc}
   */
  public function countsVisibleFor(QuestionInterface $question, AccountInterface $account): bool {
    return $question->showsResults() || $account->hasPermission(self::BYPASS_PERMISSION);
  }

  /**
   * Loads an option and checks that it belongs to the question.
   */
  private function findOption(QuestionInterface $question, int $optionId): ?OptionInterface {
    $option = $this->optionStorage()->load($optionId);
    if (!$option instanceof OptionInterface || $option->getQuestionId() !== (int) $question->id()) {
      return NULL;
    }
    return $option;
  }

  /**
   * Assembles the results snapshot.
   */
  private function buildResults(QuestionInterface $question, bool $withCounts, ?int $userOptionId): QuestionResults {
    $options = $this->optionStorage()->loadByQuestion($question);
    $counts = NULL;
    if ($withCounts) {
      $tally = $this->repository->getTally((int) $question->id());
      $counts = [];
      foreach ($options as $id => $option) {
        $counts[(int) $id] = $tally[(int) $id] ?? 0;
      }
    }
    return new QuestionResults($question, $options, $counts, $userOptionId);
  }

  /**
   * Logs a rejected vote and returns the exception for the caller to throw.
   */
  private function reject(VotingException $exception, array $context): VotingException {
    $this->logger->notice('Vote rejected (@code) on question @question, option @option, user @uid.', $context + [
      '@code' => $exception->getErrorCode(),
    ]);
    return $exception;
  }

  /**
   * The option storage handler.
   */
  private function optionStorage(): OptionStorageInterface {
    /** @var \Drupal\voting\Storage\OptionStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('voting_option');
    return $storage;
  }

}
