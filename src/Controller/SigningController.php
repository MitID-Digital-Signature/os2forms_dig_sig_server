<?php

namespace Drupal\os2forms_dig_sig_server\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\os2forms_dig_sig_server\Service\SigningService;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class SigningController extends ControllerBase {

  public function __construct(
    private readonly SigningService $signingService,
    private readonly RequestStack $requestStack,
  ) {
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('os2forms_dig_sig_server.signing_service'),
      $container->get('request_stack'),
    );
  }

  /**
   * Handles signing-related actions based on the 'action' parameter in the request.
   *
   * Supports various operations:
   * - 'getcid': Retrieves a correlation ID.
   * - 'sign': Initiates a signing process for the specified URI, forward URL, and hash.
   * - 'cancel' or 'result': Processes the result or cancels an operation using information from the specified file.
   * - 'download': Downloads a file, optionally retaining it based on the 'leave' parameter.
   *
   * Throws an exception for unexpected or invalid actions or if required parameters are missing.
   *
   * @return Response
   *    Returns a JSON response containing the result of the specified action.
   *    Or TrustedRedirectResponse.
   */
  public function signingCallback() {
    $request = $this->requestStack->getCurrentRequest();
    $action = $request->query->get('action');

    try {
      switch ($action) {
        case 'getcid':
          $json = ['cid' => $this->signingService->getCorrelationId()];
          return new JsonResponse($json);

        case 'sign':
          $uri = $request->query->get('uri');
          $forward_url = $request->query->get('forward_url');
          $hash = $request->query->get('hash');

          if (empty($uri)) {
            throw new BadRequestHttpException('Parameter uri is required.');
          }
          if (empty($forward_url)) {
            throw new BadRequestHttpException('Parameter forward_url is required.');
          }
          if (empty($hash)) {
            throw new BadRequestHttpException('Parameter forward_url is required.');
          }

          return $this->signingService->sign($uri, $forward_url, $hash);

        case 'cancel':
        case 'result':
          $file = $request->query->get('file');
          if (empty($file)) {
            throw new BadRequestHttpException('Parameter file is required.');
          }
          return $this->signingService->result($file, $action);

        case 'download':
          $file = $request->query->get('file');
          $leave = $request->query->get('leave');

          if (empty($file)) {
            throw new BadRequestHttpException('Parameter file is required.');
          }
          if ($leave === null) {
            throw new BadRequestHttpException('Parameter leave is required.');
          }

          return $this->signingService->download($file, $leave);

        default:
          throw new BadRequestHttpException('Unexpected action');
      }

    } catch (Exception $e) {
      $return = [
        'error' => TRUE,
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
      ];
      $this->signingService->debug('ERROR: %file %message %trace', ['%file' => $e->getFile(), '%message' => $e->getMessage(), '%trace' => $e->getTraceAsString()]);

      return new JsonResponse($return);
    }
  }

}
