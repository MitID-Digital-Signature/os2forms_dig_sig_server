<?php

namespace Drupal\os2forms_dig_sig_server\Service;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\os2forms_dig_sig_server\Exception\SigningException;
use Drupal\os2forms_dig_sig_server\Form\SettingsForm;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class SigningService {

  /**
   * Cookie name used to store the forward URL for digital signatures.
   */
  public const FORWARD_URL_COOKIE = 'os2forms_dig_sig_server_forward_url';

  /**
   * The config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  private readonly ImmutableConfig $config;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly UuidInterface $uuid,
    private readonly RequestStack $requestStack,
    private readonly LoggerChannelInterface $logger,
    private readonly FileSystemInterface $fileSystem,
    ConfigFactoryInterface $configFactory,
  ) {
    $this->config = $configFactory->get(SettingsForm::$configName);
  }

  /**
   * Gets the Signing correlation id.
   *
   * This id is used in later calls to the signing services.
   *
   * @return string $id
   *   The id, e.g. '7f03374d-5488-49cc-b952-0abfa297e3df'.
   */
  public function getCorrelationId() : string {
    return $this->uuid->generate();
  }

  /**
   * Handles the signature process.
   *
   * Check the params and sends the request to a sign server.
   *
   * @param string $uri
   *   File URI in base64.
   * @param string $forward_url
   *   Redirect URL in base64.
   * @param string $hash
   *   Hash to validate that request is of the trusted source.
   *
   * @return TrustedRedirectResponse
   *   Redirect to the signing process.
   * @throws \Exception
   */
  public function sign(string $uri, string $forward_url, string $hash) : TrustedRedirectResponse {
    $filename = base64_decode($uri);
    if (empty($filename) || !preg_match('@^https?://.*\.pdf$|^/[a-zA-Z].*/.*\.pdf$@', $filename)) {
      throw new BadRequestHttpException("Invalid filename given ($filename).");
    }

    $forward = base64_decode($forward_url);
    if (!$this->validateHash($hash, $forward)) {
      throw new BadRequestHttpException('Incorrect hash value');
    }

    try {
      $response = $this->doSign($filename);
    }
    catch (\Throwable $e) {
      $this->logger->error('Signing failed: %message', ['%message' => $e->getMessage()]);
      throw new \Exception('Signing failed');
    }

    // Setting cookies.
    $response->headers->setCookie(new Cookie(SigningService::FORWARD_URL_COOKIE, $forward_url));

    return $response;
  }

  /**
   * Handle the input file.
   *
   * @param string $filename
   *   The filename. It will be prefixed by SIGN_UPLOADED_PDF_DIR.
   *   It may point to an external file (https://...) if php.ini allow_url_fopen is 'On' and the
   *   domain name is on our list of valid domains, SIGN_ALLOWED_DOMAINS.
   * @return TrustedRedirectResponse
   *   Redirect response.
   *
   * @throws SigningException
   * @throws GuzzleException
   */
  private function doSign(string $filename) : TrustedRedirectResponse {
    // Generate a temporary filename in the SIGN_PDF_SOURCE_DIR. Crash out after n seconds if we still don't have a unique name.
    $loop = 10;

    // We use md5 here to generate a temp filename instead of sha1 because since it's shorter
    // and the Java implementation has a limit of filename length.
    while (file_exists($signingPdf = $this->getSourceFilesDir() . '/' . md5($filename . time()) . '.pdf')) {
      // Note: We shouldn't really reach this point.
      if (!--$loop) {
        throw new SigningException('Unexpected existing temp file');
      }
      sleep(1);
    }
    $external = (bool) preg_match('!^https?://!i', $filename);

    if ($external && !$this->isValidUrl($filename)) {
      throw new SigningException("Invalid url for external file: $filename. Valid URLs must be defined configuration");
    }
    if(!$external && preg_match('!^[a-z]{2,}://!i', $filename)) {
      throw new SigningException('Invalid protocol. Only file://, http:// and https:// are supported.');
    }
    if(!$external && preg_match('!^[\/\\\]!', $filename)) {
      throw new SigningException('File path is not allowed');
    }
    if ($external) {
      $this->debug('Fetching %filename to %signing_pdf.', ['%filename' => $filename, '%signing_pdf' => $signingPdf]);
      $response = $this->httpClient->get($filename);
      $data = $response->getBody()->getContents();
      $this->fileSystem->saveData($data, $signingPdf, FileExists::Replace);
    }
    else {
      $this->debug('Moving %filename to %signing_pdf.', ['%filename' => $this->getUploadFilesDir() . $filename, '%signing_pdf' => $signingPdf]);
      $this->fileSystem->move($this->getUploadFilesDir() . '/' . $filename, $signingPdf);
    }

    $cid = $this->getCorrelationId();
    $serviceUrl = $this->config->get('signing_service_url');
    if (!$serviceUrl) {
      throw new SigningException('Signing service URL not set. Please set it in the settings form.');
    }

    $url = $serviceUrl . '/sign/pdf/' . basename($signingPdf) . '?' . http_build_query(['correlationId' => $cid]);

    $this->debug('Signing file %url', ['%url' => $url]);

    return new TrustedRedirectResponse($url);
  }

  /**
   * Result callback.
   *
   * User has returned from a signing service, clearing cookies, redirecting
   * to the forward url.
   *
   * @params string $file
   *   File name.
   * @oaram string $action
   *   Expected action is 'result' or 'cancel'.
   *
   * @return TrustedRedirectResponse
   *   Redirect response.
   */
  public function result(string $file, string $action) : TrustedRedirectResponse {
    $request = $this->requestStack->getCurrentRequest();

    if (empty($cookie = $request->cookies->get(SigningService::FORWARD_URL_COOKIE) ?? NULL)) {
      throw new AccessDeniedHttpException('Required cookie not found.');
    }

    $url = base64_decode($cookie);
    if (!$this->isValidUrl($url)) {
      throw new AccessDeniedHttpException("Provided cookie forward url value not accepted: $url");
    }

    $sep = str_contains($url, '?') ? '&' : '?';
    $response = new TrustedRedirectResponse($url . $sep . http_build_query(['file' => $file, 'action' => $action]));

    // Deleting cookies.
    $response->headers->clearCookie(SigningService::FORWARD_URL_COOKIE);

    return $response;
  }

  /**
   * Download the signed file.
   */

  /**
   * Sends the file as a binary response.
   *
   * @param string $file
   *   File to attempt download for.
   * @param bool $leave
   *   If a file should be left on the server.
   *   TRUE - file remains untouched.
   *   FALSE - file will be deleted after sending.
   * @return BinaryFileResponse
   *   Found a file.
   */
  public function download(string $file, bool $leave) : BinaryFileResponse {
    if (!preg_match('/^[a-z0-9]{32}\.pdf$/', $file)) {
      throw new BadRequestHttpException("Invalid file name: $file. Must be contain letters or numbers and be 32 chars long");
    }

    $signedPdf = $this->getSignedFilesDir() . '/' . substr($file, 0, 32) . '-signed.pdf';

    $filesize = (int) @filesize($signedPdf);
    $this->debug('Sending %signed_pdf (%filesize bytes)', ['%signed_pdf' => $signedPdf, '%filesize' => $filesize]);
    if ($filesize) {
      $response = new BinaryFileResponse($signedPdf);
      $response->headers->set('Content-Type', 'application/pdf');
      $response->headers->set('Content-Length', $filesize);

      // Unless told otherwise, remove the file after sending.
      $deleteFileAfterSend = !$leave;
      $response->deleteFileAfterSend($deleteFileAfterSend);

      return $response;
    }
    else {
      $this->debug('Attempt to download empty or nonexisting file %file', ['%file' => $file]);
      throw new AccessDeniedHttpException('Attempt to download empty or nonexisting file');
    }
  }

  /**
   * Checks the URL.
   *
   * Checks if URL belongs to the allowed domains.
   *
   * @param string $url
   *   The url to check.
   *
   * @return bool
   *   TRUE if url is known, otherwise FALSE.
   */
  private function isValidUrl(string $url) : bool {
    $prefix = preg_replace('@https?://([^/:]*).*@', '$1', $url);

    if ($allowedDomains = $this->config->get('allowed_domains')) {
      $allowedDomains = preg_split('/, */', $allowedDomains);
      return in_array($prefix, $allowedDomains);
    }

    $this->logger->warning('List of allowed domains is empty. It is recommended to provide it. Allowing request from %url', ['%url' => $url]);
    return TRUE;
  }

  /**
   * Validates that a given value matches the expected hash.
   *
   * @param string $expectedHash
   *   The hash string to validate against.
   * @param string $value
   *   The raw input value that will be hashed and compared.
   *
   * @return bool
   *   TRUE if the computed hash of $value matches $expectedHash,
   *   FALSE otherwise.
   */
  private function validateHash(string $expectedHash, string $value) : bool {
    return hash_equals($expectedHash, $this->createHash($value));
  }

  /**
   * Generates a salted SHA-1 hash for a given value.
   *
   * @param string $value
   *   The raw input value to be hashed.
   *
   * @return string
   *   The salted SHA-1 hash of the value.
   */
  private function createHash(string $value) : string {
    $signingHashSalt = $this->config->get('signing_hash_salt');

    if (!$signingHashSalt) {
      $this->logger->error("Signing hash salt not set. Please set it in the settings form.");
    }

    return sha1($signingHashSalt . $value);
  }

  /**
   * Returns source files directory.
   *
   * Trailing slash removed.
   *
   * @return string
   */
  private function getSourceFilesDir() : string{
    $workingDir = $this->config->get('working_dir');
    $sourceDir = $workingDir . '/source';
    if (!$workingDir || !$this->fileSystem->prepareDirectory($sourceDir, FileSystemInterface::CREATE_DIRECTORY)) {
      $this->logger->error('Source files directory not set or is not writable: %directory', ['%directory' => $sourceDir]);
    }

    return $sourceDir;
  }

  /**
   * Returns upload files directory.
   *
   * Trailing slash removed.
   *
   * @return string
   */
  private function getUploadFilesDir() : string {
    $workingDir = $this->config->get('working_dir');
    $uploadDir = $workingDir . '/upload';
    if (!$workingDir || !$this->fileSystem->prepareDirectory($uploadDir, FileSystemInterface::CREATE_DIRECTORY)) {
      $this->logger->error('Upload files directory not set or is not writable: %directory', ['%directory' => $uploadDir]);
    }

    return $uploadDir;
  }

  /**
   * Returns signed files directory.
   *
   * Trailing slash removed.
   *
   * @return string
   */
  private function getSignedFilesDir() : string {
    $workingDir = $this->config->get('working_dir');
    $signedDir = $workingDir . '/signed';
    if (!$workingDir || !$this->fileSystem->prepareDirectory($signedDir, FileSystemInterface::CREATE_DIRECTORY)) {
      $this->logger->error('Signed files directory not set or is not writable: %directory', ['%directory' => $signedDir]);
    }

    return $signedDir;
  }

  /**
   * Writes a message if debug mode is ON.
   *
   * @param string $message
   *   Message to debug.
   * @param array $context
   *   Message context
   * @return void
   */
  public function debug(string $message, array $context = []) : void {
    if ($this->config->get('debug_mode')) {
      $this->logger->info($message, $context);
    }
  }

}
