<?php
return [
 'name'=>env('APP_NAME','Sunrise Academy'), 'env'=>env('APP_ENV','production'),
 'debug'=>(bool)env('APP_DEBUG',false), 'url'=>env('APP_URL','http://localhost:8000'),
 'timezone'=>'UTC', 'display_timezone'=>env('DISPLAY_TIMEZONE','Asia/Jakarta'),
 'locale'=>env('APP_LOCALE','id'), 'fallback_locale'=>'en', 'faker_locale'=>'id_ID',
 'cipher'=>'AES-256-CBC', 'key'=>env('APP_KEY'), 'previous_keys'=>[],
 'maintenance'=>['driver'=>'file'],
];
