<?php

namespace Drupal\safe_soap\Exception;

/**
 * The network error exception.
 */
class NetworkError extends \SoapFault {

  public function __construct(int $code, string $message = "Network error") {
    parent::__construct($code, $message);
  }

}
