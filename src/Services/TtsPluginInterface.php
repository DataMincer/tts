<?php

namespace DataMincerTts\Services;

interface TtsPluginInterface {

  function synthesize($text, $options);

}
