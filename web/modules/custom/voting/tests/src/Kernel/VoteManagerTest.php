<?php

declare(strict_types=1);

namespace Drupal\Tests\voting\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use Drupal\voting\Entity\Option;
use Drupal\voting\Entity\Question;
use Drupal\voting\Entity\QuestionInterface;
use Drupal\voting\Exception\AlreadyVotedException;
use Drupal\voting\Exception\AuthenticationRequiredException;
use Drupal\voting\Exception\InvalidOptionException;
use Drupal\voting\Exception\QuestionInactiveException;
use Drupal\voting\Exception\ResultsUnavailableException;
use Drupal\voting\Exception\VoteRateLimitedException;
use Drupal\voting\Exception\VotingDisabledException;
use Drupal\voting\Service\VoteManagerInterface;
use Drupal\voting\Storage\VoteRepositoryInterface;

/**
 * Tests every business rule enforced by the vote manager.
 *
 * @group voting
 * @coversDefaultClass \Drupal\voting\Service\VoteManager
 */
final class VoteManagerTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'file', 'image', 'voting'];

  /**
   * The service under test.
   */
  private VoteManagerInterface $manager;

  /**
   * Direct access to the vote tables, for assertions and race simulation.
   */
  private VoteRepositoryInterface $repository;

  /**
   * An active question with three options.
   */
  private QuestionInterface $question;

  /**
   * Option IDs of the question, in order.
   *
   * @var int[]
   */
  private array $optionIds = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('voting_question');
    $this->installEntitySchema('voting_option');
    $this->installSchema('voting', ['voting_vote', 'voting_result']);
    $this->installConfig(['voting']);

    // User 1 bypasses every permission check; burn it so voters are regular.
    $this->createUser([], 'superuser');

    $this->manager = $this->container->get('voting.vote_manager');
    $this->repository = $this->container->get('voting.vote_repository');
    $this->question = $this->createQuestion('q1');
  }

  /**
   * @covers ::castVote
   */
  public function testCastVoteRecordsVoteAndCounter(): void {
    $user = $this->createVoter();

    $results = $this->manager->castVote($this->question, $this->optionIds[1], $user);

    $this->assertTrue($this->manager->hasVoted($this->question, $user));
    $this->assertTrue($results->countsVisible());
    $this->assertSame(1, $results->getTotal());
    $this->assertSame(1, $results->getVotes($this->optionIds[1]));
    $this->assertSame(0, $results->getVotes($this->optionIds[0]));
    $this->assertSame(100.0, $results->getPercentage($this->optionIds[1]));
    $this->assertSame($this->optionIds[1], $results->userOptionId);
    $this->assertSame(
      [$this->optionIds[1] => 1],
      $this->repository->countVotesByOption((int) $this->question->id()),
    );
  }

  /**
   * @covers ::castVote
   */
  public function testSecondVoteOnSameQuestionIsRejected(): void {
    $user = $this->createVoter();
    $this->manager->castVote($this->question, $this->optionIds[0], $user);

    $this->expectException(AlreadyVotedException::class);
    $this->manager->castVote($this->question, $this->optionIds[1], $user);
  }

  /**
   * The database unique key holds even when the pre-check is bypassed.
   */
  public function testRepositoryRejectsDuplicateAndKeepsCounterConsistent(): void {
    $questionId = (int) $this->question->id();
    $this->repository->recordVote($questionId, $this->optionIds[0], 42, 1);

    try {
      $this->repository->recordVote($questionId, $this->optionIds[1], 42, 2);
      $this->fail('Duplicate vote was not rejected.');
    }
    catch (AlreadyVotedException) {
      // Expected.
    }

    $this->assertSame([$this->optionIds[0] => 1, $this->optionIds[1] => 0, $this->optionIds[2] => 0], $this->repository->getTally($questionId));
    $this->assertSame(1, $this->repository->countVotes($questionId));
  }

  /**
   * @covers ::castVote
   */
  public function testOptionOfAnotherQuestionIsRejected(): void {
    $other = $this->createQuestion('q2');
    $foreignOption = array_key_first($other->getOptions());

    $this->expectException(InvalidOptionException::class);
    $this->manager->castVote($this->question, (int) $foreignOption, $this->createVoter());
  }

  /**
   * @covers ::castVote
   */
  public function testAnonymousCannotVote(): void {
    $this->expectException(AuthenticationRequiredException::class);
    $this->manager->castVote($this->question, $this->optionIds[0], $this->container->get('current_user'));
  }

  /**
   * @covers ::castVote
   */
  public function testInactiveQuestionRejectsVotes(): void {
    $this->question->setUnpublished()->save();

    $this->expectException(QuestionInactiveException::class);
    $this->manager->castVote($this->question, $this->optionIds[0], $this->createVoter());
  }

  /**
   * @covers ::castVote
   * @covers ::getResults
   */
  public function testGlobalSwitchBlocksVotingAndResults(): void {
    $user = $this->createVoter();
    $this->manager->castVote($this->question, $this->optionIds[0], $user);
    $this->config('voting.settings')->set('enabled', FALSE)->save();

    try {
      $this->manager->castVote($this->createQuestion('q3'), $this->optionIds[0], $user);
      $this->fail('Vote accepted while voting is disabled.');
    }
    catch (VotingDisabledException) {
      // Expected.
    }

    $this->expectException(VotingDisabledException::class);
    $this->manager->getResults($this->question, $user);
  }

  /**
   * @covers ::getResults
   */
  public function testResultsRequireVote(): void {
    $this->expectException(ResultsUnavailableException::class);
    $this->expectExceptionMessage('Vote on this question to see the results.');
    $this->manager->getResults($this->question, $this->createVoter());
  }

  /**
   * @covers ::getResults
   * @covers ::castVote
   */
  public function testHiddenResultsStayHiddenForVoters(): void {
    $this->question->set('show_results', FALSE)->save();
    $user = $this->createVoter();

    $results = $this->manager->castVote($this->question, $this->optionIds[0], $user);
    $this->assertFalse($results->countsVisible());
    $this->assertNull($results->getTotal());
    $this->assertSame($this->optionIds[0], $results->userOptionId);

    try {
      $this->manager->getResults($this->question, $user);
      $this->fail('Hidden results were exposed.');
    }
    catch (ResultsUnavailableException $e) {
      $this->assertSame(ResultsUnavailableException::RESULTS_HIDDEN, $e->getErrorCode());
    }

    // The bypass permission sees everything without voting.
    $admin = $this->createUser(['view voting results']);
    $results = $this->manager->getResults($this->question, $admin);
    $this->assertTrue($results->countsVisible());
    $this->assertSame(1, $results->getTotal());
    $this->assertNull($results->userOptionId);
  }

  /**
   * @covers ::castVote
   */
  public function testFloodControlLimitsAttempts(): void {
    $this->config('voting.settings')->set('flood.limit', 1)->save();
    $user = $this->createVoter();
    $this->manager->castVote($this->question, $this->optionIds[0], $user);

    $other = $this->createQuestion('q4');
    $this->expectException(VoteRateLimitedException::class);
    $this->manager->castVote($other, (int) array_key_first($other->getOptions()), $user);
  }

  /**
   * Deleting a question or an option removes its votes and counters.
   */
  public function testDeletionCascades(): void {
    $questionId = (int) $this->question->id();
    $this->manager->castVote($this->question, $this->optionIds[0], $this->createVoter());
    $this->manager->castVote($this->question, $this->optionIds[1], $this->createVoter());

    Option::load($this->optionIds[1])->delete();
    $this->assertSame(1, $this->repository->countVotes($questionId));
    $this->assertArrayNotHasKey($this->optionIds[1], $this->repository->getTally($questionId));

    $this->question->delete();
    $this->assertSame(0, $this->repository->countVotes($questionId));
    $this->assertSame([], $this->repository->countVotesByOption($questionId));
    $this->assertNull(Option::load($this->optionIds[0]));
  }

  /**
   * Creates an active question with three options.
   */
  private function createQuestion(string $identifier): QuestionInterface {
    $question = Question::create([
      'title' => 'Question ' . $identifier,
      'identifier' => $identifier,
      'status' => 1,
      'show_results' => TRUE,
    ]);
    $question->save();

    $ids = [];
    foreach (['A', 'B', 'C'] as $weight => $title) {
      $option = Option::create(['question_id' => $question->id(), 'title' => $title, 'weight' => $weight]);
      $option->save();
      $ids[] = (int) $option->id();
    }
    if ($identifier === 'q1') {
      $this->optionIds = $ids;
    }
    return $question;
  }

  /**
   * Creates a regular user allowed to vote.
   */
  private function createVoter(): UserInterface {
    return $this->createUser(['vote in voting questions']);
  }

}
