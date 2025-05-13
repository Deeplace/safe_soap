<?php

namespace Drupal\safe_soap\Exception;

use Drupal\safe_soap\SafeSoapConstants;

/**
 * The service description unavailable exception.
 */
class ServiceDescriptionUnavailable extends \SoapFault {

  public function __construct(string $message = "Service description unavailable") {
    parent::__construct(SafeSoapConstants::SAFE_SOAP_CACHE_ERROR, $message);
  }

}
