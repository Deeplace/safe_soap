<?php

namespace Drupal\safe_soap;

use Drupal\safe_soap\Exception\NetworkError;
use Drupal\safe_soap\Exception\ServiceDescriptionUnavailable;

/**
 * A child of SoapClient with network failure handling support.
 */
class SafeSoapClient extends \SoapClient {

  /**
   * Option.
   */
  private array $options;

  /**
   * The cURL status code.
   */
  private ?int $curlStatusCode;

  /**
   * Extended SoapClient constructor.
   *
   * SafeSoapClient constructor following extensions over
   * SoapClient::__construct():
   *  - $options['certificate_chain'] - path to file containing both client
   *    auth certificate chain and server certificate chain. This is workaround
   *    for libcurl compiled against NSS (vs. OpenSSL) which does not send
   *    chain passed via CURLOPT_SSLCERT, and requires both chains set
   *    via CURLOPT_CAINFO.
   * - $options['certificate_chain'] - path to file containing both client auth
   *      certificate chain and server certificate chain. This is workaround
   *      for libcurl compiled against NSS (vs. OpenSSL) which does not send
   *      chain passed via CURLOPT_SSLCERT, and requires both chains set via
   *      CURLOPT_CAINFO.
   * - $options['local_cert'] -  path to PEM file with private key and X509
   *     authentication certificate.
   * - $options['local_pk'] -  path to PEM file with private key.
   * - $options['cafile'] -  path to PEM file with several PEM certs used to
   *     verify peer.
   * - $options['capath'] -  path to directory containing PEM files with certs
   *     used to verify peer.
   * - $options['passphrase'] - symetric key to decode private key of client
   *     auth certificate.
   * - $options['connect_timeout'] - connect timeout in seconds set via
   *                           CURLOPT_CONNECTTIMEOUT, default value is 15.
   * - $options['timeout'] - request timeout in seconds set via CURLOPT_TIMEOUT
   *                         default value is 240.
   *
   * @param string $wsdl
   *   The wsdl path.
   * @param array $options
   *   The client options.
   *
   * @throws \Drupal\safe_soap\ServiceDescriptionUnavailable.
   */
  public function __construct(string $wsdl, array $options = []) {
    $this->options = $options;
    $cacheFile = NULL;

    $wsdlAddrType = parse_url($wsdl, PHP_URL_SCHEME);

    if (strncmp($wsdlAddrType, 'http', 4) === 0) {
      $response = $this->callCurl($wsdl);
      $httpStatus = (int) $this->curlStatusCode;

      if (!empty($response) && $httpStatus >= 200 && $httpStatus < 300) {
        $cacheFile = sys_get_temp_dir() . "/safe_soap.wsdl-" . md5($wsdl);
        $wsdl = $cacheFile;
      }

      // Only fetch a new wsdl every hour.
      if (!empty($cacheFile) && (!file_exists($cacheFile) || filectime($cacheFile) < time() - 3600)) {
        if (!file_put_contents($cacheFile, $response)) {
          throw new ServiceDescriptionUnavailable();
        }
      }
    }

    parent::__construct($wsdl, $options);
  }

  /**
   * Call a url using curl.
   *
   * @param string $url
   *   URL to request.
   * @param string $data
   *   URL encoded POST params.
   * @param array $headers
   *   List of HTTP headers as strings "Key: value".
   *
   * @return string
   *   XML SOAP response.
   *
   * @throws \Drupal\safe_soap\Exception\NetworkError
   *    On curl connection error.
   */
  private function callCurl($url, $data = NULL, array $headers = []) {
    $options = $this->options;
    $handle = curl_init();

    curl_setopt($handle, CURLOPT_TIMEOUT, $options['timeout'] ?? 240);
    curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, $options['connect_timeout'] ?? 15);
    curl_setopt($handle, CURLINFO_HEADER_OUT, TRUE);
    curl_setopt($handle, CURLOPT_HEADER, FALSE);
    curl_setopt($handle, CURLOPT_URL, $url);
    curl_setopt($handle, CURLOPT_FAILONERROR, FALSE);
    curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, TRUE);

    if (array_key_exists('local_cert', $options)) {
      curl_setopt($handle, CURLOPT_SSLCERT, $options['local_cert']);

      if (array_key_exists('local_pk', $options)) {
        curl_setopt($handle, CURLOPT_SSLKEY, $options['local_pk']);
      }
      elseif (array_key_exists('certificate_chain', $options)) {
        curl_setopt($handle, CURLOPT_SSLKEY, $options['local_cert']);
      }

      if (array_key_exists('passphrase', $options)) {
        curl_setopt($handle, CURLOPT_KEYPASSWD, $options['passphrase']);
        curl_setopt($handle, CURLOPT_SSLCERTPASSWD, $options['passphrase']);
      }
    }

    if (!empty($options['certificate_chain'])) {
      curl_setopt($handle, CURLOPT_CAINFO, $options['certificate_chain']);
    }

    if (!empty($options['capath'])) {
      curl_setopt($handle, CURLOPT_CAPATH, $options['capath']);
    }

    if (!empty($options['cafile'])) {
      curl_setopt($handle, CURLOPT_CAINFO, $options['cafile']);
    }

    if (isset($data)) {
      curl_setopt($handle, CURLOPT_POSTFIELDS, $data);
    }

    $response = curl_exec($handle);

    $this->curlStatusCode = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    if (empty($response)) {
      $errorCode = curl_errno($handle);
      $errorMessage = curl_error($handle);
      curl_close($handle);

      throw new NetworkError($errorCode, sprintf('Network error (%s): %s', $url, $errorMessage));
    }

    curl_close($handle);

    return $response;
  }

  /**
   * Magic method.
   *
   * @throws \Drupal\safe_soap\Exception\NetworkError
   *    On curl connection error.
   */
  public function __doRequest(
    string $data,
    string $url,
    string $action,
    int $version,
    bool $oneWay = FALSE,
  ): ?string {
    $headers = ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: "' . $action . '"'];
    if ($oneWay) {
      $this->callCurl($url, $data, $headers);

      return NULL;
    }

    return $this->callCurl($url, $data, $headers);
  }

}
