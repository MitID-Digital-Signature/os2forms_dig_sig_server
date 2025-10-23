# OS2Forms Digital Signature server module

## Module purpose

This module provides Digital signature server functionality for Digital Signature clients, implemented by this module (https://github.com/OS2Forms/os2forms/tree/develop/modules/os2forms_digital_signature) and have to be installed on a server along with this backend component (https://github.com/MitID-Digital-Signature/Signing-Server/)

## How does it work

This module is passively waiting for the requests on endpoint `/sign`.
The request type is identified by the `action` argument.
Supported operations:
* `getcid`: Retrieves a correlation ID (randon UUID)
* `sign`: Initiates a signing process for the specified `URI`, `forward URL`, and `hash`.
* `cancel` or `result`: Processes the result or cancels an operation using information from the specified file.
* `download`: Send the file for downloading, optionally retaining it based on the `leave` parameter.

## Settings page

The module provides a configuration form at `/admin/os2forms_dig_sig_server/settings`
The following settings are available:

- **Signing server URL**

  The base URL of the signing service.
  Example: `https://signering.bellcom.dk`

- **Allowed domains**

  A comma-separated list of allowed domains from which signing requests are accepted.
  Example: `sign.localhost, sign.local, localhost, example.vhost.com`

- **Files working directory**

  Directory where the source and signed PDF files will be stored.
  For security reasons, use `private://` or a path outside the Drupal web root.

- **Hash Salt used for signature**

  Secret string used when generating and verifying hashes.
  Must match the hash salt configured on the signature client.

- **Enable debug mode**

  When enabled, debug messages about signing operations are logged to Drupal watchdog.


# Nginx configuration 

The installation implementing Digital Signature must have this canonical redirect for /sign.php

    if ($request_uri ~* ^/sign\.php(?:\?|$)) {

        return 301 /sign?$args;

    }



