<?php
return ['default'=>env('LOG_CHANNEL','daily'),'channels'=>[
 'daily'=>['driver'=>'daily','path'=>storage_path('logs/laravel.log'),'days'=>14,'level'=>env('LOG_LEVEL','warning'),'replace_placeholders'=>true],
 'stack'=>['driver'=>'stack','channels'=>['single'],'ignore_exceptions'=>false],
 'single'=>['driver'=>'single','path'=>storage_path('logs/laravel.log'),'level'=>env('LOG_LEVEL','warning'),'replace_placeholders'=>true],
 'stderr'=>['driver'=>'monolog','handler'=>Monolog\Handler\StreamHandler::class,'with'=>['stream'=>'php://stderr']],
 'null'=>['driver'=>'monolog','handler'=>Monolog\Handler\NullHandler::class]]];
