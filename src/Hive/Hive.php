<?php

namespace Hive;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request as ClientRequest;
use GuzzleHttp\Exception\RequestException;

class Hive {

  /** @var GuzzleHttp\Client The Guzzle client. */
  protected $client;

  /** @var array Current session authentication credentials. */
  protected $credentials = null;

  /** @var array Default settings. */
  protected $defaults = [
    'base' => 'https://api-prod.bgchprod.info:443/omnia/',
    'timeout' => 4, // seconds
    'headers' => [
      'Content-Type' => 'application/vnd.alertme.zoo-6.5+json',
      'Accept' => 'application/vnd.alertme.zoo-6.5+json',
      'X-Omnia-Client' => 'Hive Web Dashboard',
    ],
    'requestJsonOptions' => JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
  ];

  /** @var array Current settings. */
  protected $settings;

  /**
   * Constructor.
   *
   * @param array $options Options for this instance.
  **/
  public function __construct(array $options = []) {
    $this->settings = array_merge($this->defaults, $options);
    $this->setClient();
  }

  /**
   * Set the client.
   *
   * @param array $options Options for this instance.
  **/
  public function setClient() {
    $this->client = new Client([
      'base_uri' => $this->settings['base'],
      'timeout' => $this->settings['timeout'],
      'headers' => $this->settings['headers'],
    ]);
  }

  /**
   * Authenticate a user.
   *
   * @param string User's username (email address).
   * @param string User's password.
  **/
  public function authenticate($username, $password) {
    $method = 'POST';
    $path = 'auth/sessions';
    $data = [
      'sessions' => [[
        'username' => $username,
        'password' => $password,
        'caller' => 'WEB', // @TODO check this
      ]],
    ];
    $request = $this->withJsonBody(new ClientRequest($method, $path), $data);
    /*
    id: string(32) Appears to be equal to sessionId
    username: string
    userId:	string(36)
    extCustomerLevel:	 integer (e.g. 1)
    latestSupportedApiVersion: string (e.g. "6")
    sessionId:	string(32)
    */
    try {
      $response = $this->send($request);
      $this->credentials = $response['json']['sessions']['0'];
      $this->settings['headers']['X-Omnia-Access-Token'] = $this->credentials['sessionId'];
      $this->setClient();
      return $response;
    } catch (\Throwable $error) {
      throw $error;
      return $error;
    }
  }

  public function withJsonBody($request, $data) {
    $request->getBody()->write(json_encode($data, $this->settings['requestJsonOptions']));
    return $request;
  }


  protected function send($request) {
    try {
      return $this->response($this->client->send($request));
    } catch (RequestException $error) {
      throw $error;
      return $this->errorResponse($error);
    } catch (\Throwable $error) {
      throw $error;
    }
  }

  protected function errorResponse($error) {
    if ($error->hasResponse()) {
      $response = $error->getResponse();
      return [
        'status' => $response->getStatusCode(),
        'text' => $response->getReasonPhrase(),
        // 'json' => json_decode((string) $response->getBody(), true),
        'response' => $response,
      ];
    }
    throw $error;
  }

  protected function response($response) {
    return [
      'status' => $response->getStatusCode(),
      'text' => $response->getReasonPhrase(),
      'json' => json_decode((string) $response->getBody(), true),
      'response' => $response,
    ];
  }

  /**
   * List nodes (devices) for the current session.
  **/
  public function listDevices() {
    $method = 'GET';
    $path = 'nodess';
    $request = new ClientRequest($method, $path);

    $response = $this->send($request);
    $response['data'] = $response['json']['nodes'];
    return $response;
  }

  /**
   * Retrieve list of supported channels (time series data) for each device.
  **/
  public function getChannels() {
    $method = 'GET';
    $path = 'channels';
    $request = new ClientRequest($method, $path);

    $response = $this->response($this->client->send($request));
    $response['data'] = $response['json']['channels'];
    return $response;
  }

  /**
   * Retrieve list of supported channels (time series data) for each device.
   * @param string|array|null Channel Id, or list of Ids, or null for all channels.
  **/
  public function getChannelValues($channelId = null) {
    if ($channelId === null) {
      $channels = $this->getChannels();
      $channels = $channels['data']['channels'];
      $channelId = [];
      foreach ($channels as $channel) {
        // @TODO get MAX, MIN, AVG too
        // if (in_array('DATASET', $channel['supportedOperations'])) {
        // targetTemperature, controllerState, rssi, signal
        if (preg_match('/(temperature|battery|targetTemperature|signal)@/', $channel['id'])) {
          $channelId[] = $channel['id'];
        }
        $channelId = array_flip(array_flip($channelId));
      }
    }
    if (is_array($channelId)) {
      $channelId = implode(',', $channelId);
    }
    $method = 'GET';
    $path = "channels/$channelId";
    try {
      $start = (time() - 60 * 60 * 24 * 7) * 1000; // unix timestamp **in milliseconds**
      $duration = 60 * 60 * 24 * 10 * 1000;
      $response = $this->response($this->client->request($method, $path, [
        'query' => [
          'start' => $start, // unix timestamp **in milliseconds**
          'end' => $start + $duration, // unix timestamp **in milliseconds**
          'timeUnit' => 'SECONDS',
          'rate' => 1,
          'operation' => 'AVG',
        ],
      ]));
      $response['data'] = $response['json']['channels'];
      return $response;
    } catch (\Throwable $error) {
      return $error;
    }

  }
}
