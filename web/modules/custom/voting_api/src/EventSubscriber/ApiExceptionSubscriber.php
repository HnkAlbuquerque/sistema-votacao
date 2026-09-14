<?php

declare(strict_types=1);

namespace Drupal\voting_api\EventSubscriber;

use Drupal\voting\Exception\AlreadyVotedException;
use Drupal\voting\Exception\AuthenticationRequiredException;
use Drupal\voting\Exception\InvalidOptionException;
use Drupal\voting\Exception\QuestionInactiveException;
use Drupal\voting\Exception\ResultsUnavailableException;
use Drupal\voting\Exception\VoteRateLimitedException;
use Drupal\voting\Exception\VotingDisabledException;
use Drupal\voting\Exception\VotingException;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\voting\VotingSettings;
use Drupal\voting_api\Response\ApiResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Converts every exception raised under /api/ into the JSON error envelope.
 *
 * Controllers never catch anything: domain exceptions map to a status code
 * here, HTTP exceptions keep their status, and anything else becomes a
 * generic 500 without leaking internals.
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface {

  public const PATH_PREFIX = '/api/';

  /**
   * HTTP status per domain error code.
   */
  private const STATUS_MAP = [
    VotingDisabledException::class => 403,
    AuthenticationRequiredException::class => 401,
    QuestionInactiveException::class => 403,
    InvalidOptionException::class => 422,
    AlreadyVotedException::class => 409,
    VoteRateLimitedException::class => 429,
    ResultsUnavailableException::class => 403,
  ];

  public function __construct(
    private readonly VotingSettings $settings,
    private readonly LoggerInterface $logger,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::EXCEPTION => [
        // Before core's authentication subscriber (80) turns a 403 for an
        // anonymous client into a 401 challenge: while voting is disabled the
        // answer is "disabled", not "log in".
        ['onAccessDeniedWhileDisabled', 90],
        // Before core's logging subscriber (50): business rule violations are
        // expected and already logged by the domain, no backtrace needed.
        ['onVotingException', 60],
        // After core's logging subscriber, so HTTP and PHP errors get logged.
        ['onException', 40],
      ],
    ];
  }

  /**
   * Answers 403 voting_disabled to any access denial while the switch is off.
   */
  public function onAccessDeniedWhileDisabled(ExceptionEvent $event): void {
    if (!$event->getThrowable() instanceof AccessDeniedHttpException
      || $this->settings->isEnabled()
      || !$this->isApiRequest($event)) {
      return;
    }
    $event->setResponse(ApiResponse::error('voting_disabled', 'Voting is currently disabled.', 403));
    $event->stopPropagation();
  }

  /**
   * Maps domain exceptions to their status code.
   */
  public function onVotingException(ExceptionEvent $event): void {
    $exception = $event->getThrowable();
    if (!$exception instanceof VotingException || !$this->isApiRequest($event)) {
      return;
    }

    $status = self::STATUS_MAP[$exception::class] ?? 400;
    $event->setResponse(ApiResponse::error($exception->getErrorCode(), (string) $exception->getUserMessage(), $status));
    $event->stopPropagation();
  }

  /**
   * Maps HTTP exceptions (403, 404, 405…) and unexpected errors.
   */
  public function onException(ExceptionEvent $event): void {
    if (!$this->isApiRequest($event)) {
      return;
    }
    $exception = $event->getThrowable();

    if ($exception instanceof HttpExceptionInterface) {
      $status = $exception->getStatusCode();
      // Credentials were sent but did not authenticate anyone.
      if ($status === 403 && $this->currentUser->isAnonymous() && $event->getRequest()->headers->has('Authorization')) {
        $event->setResponse(ApiResponse::error('invalid_credentials', 'The provided credentials are invalid.', 401));
        $event->stopPropagation();
        return;
      }
      [$code, $default] = match ($status) {
        400 => ['invalid_payload', 'The request is malformed.'],
        401 => ['authentication_required', 'Authentication is required.'],
        403 => $this->settings->isEnabled()
          ? ['access_denied', 'You are not allowed to perform this action.']
          : ['voting_disabled', 'Voting is currently disabled.'],
        404 => ['not_found', 'The requested resource does not exist.'],
        405 => ['method_not_allowed', 'This method is not allowed on this resource.'],
        default => ['http_error', 'The request could not be processed.'],
      };
      $message = $exception->getMessage() !== '' && $status < 500 ? $exception->getMessage() : $default;
      $response = ApiResponse::error($code, $message, $status);
      $response->headers->add($exception->getHeaders());
    }
    else {
      $this->logger->error('Unhandled API exception: @message', ['@message' => $exception->getMessage()]);
      $response = ApiResponse::error('server_error', 'An unexpected error occurred.', 500);
    }

    $event->setResponse($response);
    $event->stopPropagation();
  }

  /**
   * Whether the failing request targets the API.
   */
  private function isApiRequest(ExceptionEvent $event): bool {
    return str_starts_with($event->getRequest()->getPathInfo(), self::PATH_PREFIX);
  }

}
