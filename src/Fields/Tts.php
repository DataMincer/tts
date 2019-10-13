<?php

namespace DataMincerTts\Fields;

use DataMincerCore\Plugin\PluginFieldBase;
use DataMincerCore\Timer;

/**
 * @property mixed|null text
 * @property array|null requestOptions
 */
class Tts extends PluginFieldBase {

  protected static $pluginId = 'tts';
  protected static $pluginType = 'field';
  protected static $pluginDeps = [
    [
      'type' => 'service',
      'name' => 'tts.*'
    ]
  ];

  function getValue($data) {
    $text = $this->text->value($data);
    // Validate XML
    $fake_header = '<?xml version="1.0" encoding="UTF-8"?>';
    /** @noinspection PhpComposerExtensionStubsInspection */
    $result = @simplexml_load_string($fake_header . $text);
    if (!$result) {
      $this->error("Received not valid XML:\n\n$text\n");
    }
    $tts = current($this->_dependencies['service']);
    $_probe_name = "TTS {$tts->name}({$tts::pluginId()})";
    Timer::begin($_probe_name);
    $result = $tts->synthesize($text, $this->requestOptions);
    Timer::end($_probe_name);
    return $result;
  }

  static function getSchemaChildren() {
    return [
      'text' => [ '_type' => 'partial', '_required' => TRUE, '_partial' => 'field'],
      'requestOptions' => [ '_type' => 'array', '_required' => FALSE,  '_ignore_extra_keys' => TRUE, '_children' => [
        // @see https://docs.aws.amazon.com/aws-sdk-php/v3/api/api-polly-2016-06-10.html#synthesizespeech
      ]]
    ];
  }

}
