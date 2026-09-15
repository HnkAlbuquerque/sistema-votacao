<?php

declare(strict_types=1);

namespace Drupal\Tests\voting_api\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;
use Drupal\voting\Entity\Option;
use Drupal\voting\Entity\Question;
use Psr\Http\Message\ResponseInterface;

/**
 * End-to-end tests of the external API over HTTP with Basic Auth.
 *
 * @group voting
 */
final class VotingApiTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['voting', 'voting_api', 'basic_auth'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A regular user allowed to vote.
   */
  private UserInterface $voter;

  /**
   * Option IDs of the public question.
   *
   * @var int[]
   */
  private array $optionIds = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->grantPermissions(Role::load(RoleInterface::ANONYMOUS_ID), ['view voting questions']);
    $this->grantPermissions(Role::load(RoleInterface::AUTHENTICATED_ID), [
      'view voting questions',
      'vote in voting questions',
    ]);
    $this->voter = $this->drupalCreateUser();

    $question = Question::create([
      'title' => 'Best language',
      'identifier' => 'best-language',
      'description' => 'Pick one.',
      'status' => 1,
      'show_results' => TRUE,
    ]);
    $question->save();
    foreach (['PHP', 'Go'] as $weight => $title) {
      $option = Option::create(['question_id' => $question->id(), 'title' => $title, 'weight' => $weight]);
      $option->save();
      $this->optionIds[] = (int) $option->id();
    }

    Question::create(['title' => 'Draft', 'identifier' => 'draft', 'status' => 0])->save();
  }

  /**
   * Lists only active questions, with their option count.
   */
  public function testListAndShow(): void {
    $response = $this->request('GET', '/api/v1/questions');
    $this->assertSame(200, $response->getStatusCode());
    $body = $this->decode($response);
    $this->assertSame(1, $body['meta']['count']);
    $this->assertSame('best-language', $body['data'][0]['id']);
    $this->assertSame(2, $body['data'][0]['options_count']);

    $response = $this->request('GET', '/api/v1/questions/best-language');
    $this->assertSame(200, $response->getStatusCode());
    $body = $this->decode($response);
    $this->assertSame(['PHP', 'Go'], array_column($body['data']['options'], 'title'));
    $this->assertNull($body['data']['options'][0]['image_url']);

    $this->assertSame(404, $this->request('GET', '/api/v1/questions/missing')->getStatusCode());
    $this->assertSame('not_found', $this->decode($this->request('GET', '/api/v1/questions/missing'))['error']['code']);
    // Inactive question: anonymous clients are challenged to authenticate.
    $this->assertSame(401, $this->request('GET', '/api/v1/questions/draft')->getStatusCode());
  }

  /**
   * The list is paginated through the page and limit parameters.
   */
  public function testListPagination(): void {
    foreach (['b', 'c', 'd', 'e'] as $suffix) {
      Question::create(['title' => "Question $suffix", 'identifier' => "question-$suffix", 'status' => 1])->save();
    }

    $response = $this->request('GET', '/api/v1/questions', ['query' => ['limit' => 2]]);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    $body = $this->decode($response);
    $this->assertCount(2, $body['data']);
    $this->assertSame(['count' => 2, 'total' => 5, 'page' => 1, 'limit' => 2, 'pages' => 3], $body['meta']);

    $body = $this->decode($this->request('GET', '/api/v1/questions', ['query' => ['limit' => 2, 'page' => 3]]));
    $this->assertCount(1, $body['data']);
    $this->assertSame(3, $body['meta']['page']);

    $response = $this->request('GET', '/api/v1/questions', ['query' => ['page' => 0]]);
    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame('invalid_payload', $this->decode($response)['error']['code']);
  }

  /**
   * The full vote flow: auth, validation, success, duplicate, results.
   */
  public function testVoteFlow(): void {
    $path = '/api/v1/questions/best-language/vote';
    $auth = [$this->voter->getAccountName(), $this->voter->passRaw];

    $this->assertSame(401, $this->request('POST', $path, ['json' => ['option_id' => $this->optionIds[0]]])->getStatusCode());
    $response = $this->request('POST', $path, [
      'auth' => [$this->voter->getAccountName(), 'wrong'],
      'json' => ['option_id' => 1],
    ]);
    $this->assertSame(401, $response->getStatusCode());

    $response = $this->request('POST', $path, [
      'auth' => $auth,
      'body' => '{oops',
      'headers' => ['Content-Type' => 'application/json'],
    ]);
    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame('invalid_payload', $this->decode($response)['error']['code']);

    $response = $this->request('POST', $path, ['auth' => $auth, 'json' => ['option_id' => 999999]]);
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('invalid_option', $this->decode($response)['error']['code']);

    $response = $this->request('GET', '/api/v1/questions/best-language/results', ['auth' => $auth]);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('vote_required', $this->decode($response)['error']['code']);

    $response = $this->request('POST', $path, ['auth' => $auth, 'json' => ['option_id' => $this->optionIds[1]]]);
    $this->assertSame(201, $response->getStatusCode());
    $body = $this->decode($response)['data'];
    $this->assertTrue($body['results_visible']);
    $this->assertSame(1, $body['total_votes']);
    $this->assertSame($this->optionIds[1], $body['your_vote']);
    $this->assertSame(100.0, (float) $body['options'][1]['percentage']);

    $response = $this->request('POST', $path, ['auth' => $auth, 'form_params' => ['option_id' => $this->optionIds[0]]]);
    $this->assertSame(409, $response->getStatusCode());
    $this->assertSame('already_voted', $this->decode($response)['error']['code']);

    $response = $this->request('GET', '/api/v1/questions/best-language/results', ['auth' => $auth]);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('no-store, private', $response->getHeaderLine('Cache-Control'));
    $this->assertSame(1, $this->decode($response)['data']['total_votes']);
  }

  /**
   * Hidden results are acknowledged but not exposed.
   */
  public function testHiddenResults(): void {
    Question::load(1)->set('show_results', FALSE)->save();
    $auth = [$this->voter->getAccountName(), $this->voter->passRaw];

    $response = $this->request('POST', '/api/v1/questions/best-language/vote', [
      'auth' => $auth,
      'json' => ['option_id' => $this->optionIds[0]],
    ]);
    $this->assertSame(201, $response->getStatusCode());
    $body = $this->decode($response)['data'];
    $this->assertFalse($body['results_visible']);
    $this->assertNull($body['total_votes']);
    $this->assertArrayNotHasKey('votes', $body['options'][0]);

    $response = $this->request('GET', '/api/v1/questions/best-language/results', ['auth' => $auth]);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('results_hidden', $this->decode($response)['error']['code']);
  }

  /**
   * The kill switch closes every endpoint except health.
   */
  public function testKillSwitch(): void {
    $this->config('voting.settings')->set('enabled', FALSE)->save();
    $auth = [$this->voter->getAccountName(), $this->voter->passRaw];

    foreach ([
      ['GET', '/api/v1/questions', []],
      ['GET', '/api/v1/questions/best-language', []],
      [
        'POST',
        '/api/v1/questions/best-language/vote',
        ['auth' => $auth, 'json' => ['option_id' => $this->optionIds[0]]],
      ],
      ['GET', '/api/v1/questions/best-language/results', ['auth' => $auth]],
    ] as [$method, $path, $options]) {
      $response = $this->request($method, $path, $options);
      $this->assertSame(403, $response->getStatusCode(), $path);
      $this->assertSame('voting_disabled', $this->decode($response)['error']['code'], $path);
    }

    $response = $this->request('GET', '/api/v1/health');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertFalse($this->decode($response)['data']['voting_enabled']);
  }

  /**
   * Sends a request without throwing on error statuses.
   */
  private function request(string $method, string $path, array $options = []): ResponseInterface {
    return $this->getHttpClient()->request($method, $this->buildUrl($path), $options + ['http_errors' => FALSE]);
  }

  /**
   * Decodes a JSON response body.
   */
  private function decode(ResponseInterface $response): array {
    return json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
  }

}
