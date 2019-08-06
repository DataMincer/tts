<?php

namespace DataMincerTts\Services;

use DirectoryIterator;
use Exception;
use DataMincerCore\Exception\PluginException;
use DataMincerCore\Plugin\PluginServiceBase;
use Aws\Exception\AwsException;
use Aws\Polly\PollyClient;
use DataMincerCore\Util;

class Amazon extends PluginServiceBase implements TtsPluginInterface {

  protected static $pluginId = 'tts.amazon';
  protected static $pluginType = 'service';
  /**
   * @var PollyClient
   */
  protected $client;

  const VERSION = '2016-06-10';

  public function initialize() {
    parent::initialize();
    /**
     * How to setup credentials:
     * https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/guide_credentials.html
     */
    // Create a PollyClient
    $this->client = new PollyClient($this->config['clientOptions']);
  }

  /**
   * @param $text
   * @param $options
   * @return array|mixed|null
   * @throws PluginException
   */
  function synthesize($text, $options) {
    $use_cache = boolval($this->config['cache'] ?? FALSE);
    // TODO: Add logger with log levels
    if ($use_cache) {
      $cache_dir = $this->getCacheDir();
    }
    $data = NULL;
    try {
      $request_options = ['Text' => $text] + Util::arrayMergeDeep($options, $this->config['requestOptions'], TRUE);
      // Stringify options as Polly wants strings
      $request_options = array_map('strval', $request_options);
      $request_id = $this->getRequestHash($request_options);
      if ($use_cache) {
        /** @noinspection PhpUndefinedVariableInspection */
        if (file_exists($cache_file_name = $cache_dir . '/' . $request_id)) {
          try {
            $data = unserialize(file_get_contents($cache_file_name));
          }
          catch (Exception $e) {
            $this->error('Cannot read file contents: ' . $cache_file_name . "\n" . $e->getMessage());
          }
          /** @noinspection PhpUndefinedVariableInspection */
          return $data;
        }
      }
      $result = $this->client->synthesizeSpeech($request_options);
      $data = [
        'request_id' => $request_id,
        'data' => $result->get('AudioStream')->getContents(),
        'mime' => $result->get('ContentType')
      ];
      if ($use_cache) {
        /** @noinspection PhpUndefinedVariableInspection */
        file_put_contents($cache_file_name, serialize($data));
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
    if (array_key_exists('cachePath', $this->config)) {
      $sys_temp_dir = $this->config['cachePath'];
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
      'version' => self::VERSION
    ];
  }

  static function defaultConfig($data = NULL) {
    return [
      'clientOptions' => [
        'version' => self::VERSION,
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
