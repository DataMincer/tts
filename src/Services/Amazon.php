<?php

namespace DataMincerTts\Services;

use DirectoryIterator;
use Exception;
use DataMincerCore\Exception\PluginException;
use DataMincerCore\Plugin\PluginServiceBase;
use Aws\Exception\AwsException;
use Aws\Polly\PollyClient;
use DataMincerCore\Util;

/**
 * @property boolean cache
 * @property string cachePath
 * @property array requestOptions
 * @property array clientOptions
 */
class Amazon extends PluginServiceBase implements TtsPluginInterface {

  protected static $pluginId = 'tts.amazon';
  protected static $pluginType = 'service';
  /**
   * @var PollyClient
   */
  protected $client;

  const CLIENT_VERSION = '2016-06-10';
  const CACHE_BIN = 'amazon.polly';

  public function initialize() {
    parent::initialize();
    /**
     * How to setup credentials:
     * https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/guide_credentials.html
     */
    // Create a PollyClient
    $this->client = new PollyClient($this->clientOptions);
  }

  /**
   * @param $text
   * @param $options
   * @return array|mixed|null
   * @throws PluginException
   */
  function synthesize($text, $options) {
    $use_cache = boolval($this->cache ?? FALSE);
    $data = NULL;
    try {
      $request_options = ['Text' => $text] + Util::arrayMergeDeep($options, $this->requestOptions, TRUE);
      // Stringify options as Polly wants strings
      $request_options = array_map('strval', $request_options);
      $request_id = $this->getRequestHash($request_options);
      if ($use_cache
          && $this->cacheManager->exists($request_id, self::CACHE_BIN)
          && ($data = $this->cacheManager->getData($request_id, self::CACHE_BIN)) &&
          $data !== FALSE) {
        return $data;
      }
      $result = $this->client->synthesizeSpeech($request_options);
      $data = [
        'request_id' => $request_id,
        'data' => $result->get('AudioStream')->getContents(),
        'mime' => $result->get('ContentType')
      ];
      if ($use_cache) {
        $this->cacheManager->setData($request_id, $data, self::CACHE_BIN);
      }
    } catch (AwsException $e) {
      $this->error($e->getMessage());
    }
    return $data;
  }

  protected function getRequestHash($options) {
    ksort($options);
    return sha1(serialize($options));
  }

  protected function getCacheDir() {
    $cache_dir = $this->findCacheDir();
    if ($cache_dir === FALSE) {
      $cache_dir = $this->createCacheDir();
    }
    return $cache_dir;
  }

  protected function getCachePath() {
    if (isset($this->cachePath)) {
      $sys_temp_dir = $this->cachePath;
    }
    else {
      $sys_temp_dir = sys_get_temp_dir();
    }
    return $sys_temp_dir;
  }

  protected function findCacheDir() {
    $result = FALSE;
    foreach (new DirectoryIterator($this->getCachePath()) as $fileInfo) {
      if ($fileInfo->isDir() && !$fileInfo->isDot() && strpos($fileInfo->getFilename(), static::$pluginId) === 0) {
        $result = $fileInfo->getPathname();
        break;
      }
    }
    return $result;
  }

  protected function createCacheDir() {
    $temp_file = tempnam($this->getCachePath(), static::$pluginId);
    if (file_exists($temp_file)) {
      unlink($temp_file);
    }
    Util::prepareDir($temp_file);
    return $temp_file;
  }


  protected function defaultSynthesizeOptions() {
    return [
      'version' => self::CLIENT_VERSION
    ];
  }

  static function defaultConfig($data = NULL) {
    return [
      'clientOptions' => [
        'version' => self::CLIENT_VERSION,
      ],
      'requestOptions' => [
        'SampleRate' => 16000,
        'OutputFormat' => 'ogg_vorbis',
        //'TextType' => 'ssml'
      ]
    ];
  }

  static function getSchemaChildren() {
    return [
      'cache' => [ '_type' => 'boolean', '_required' => FALSE ],
      'cachePath' => [ '_type' => 'text', '_required' => FALSE ],
      'clientOptions' => [ '_type' => 'array', '_required' => FALSE,  '_ignore_extra_keys' => TRUE, '_children' => [
        // @see https://docs.aws.amazon.com/en_us/sdk-for-php/v3/developer-guide/guide_configuration.html
      ]],
      'requestOptions' => [ '_type' => 'array', '_required' => FALSE,  '_ignore_extra_keys' => TRUE, '_children' => [
        // @see https://docs.aws.amazon.com/aws-sdk-php/v3/api/api-polly-2016-06-10.html#synthesizespeech
      ]]
    ];

  }

}
