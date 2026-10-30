<?php
return [
 'default'=>env('MAIL_MAILER','log'),
 'mailers'=>[
  'smtp'=>['transport'=>'smtp','scheme'=>env('MAIL_SCHEME','smtp'),'host'=>env('MAIL_HOST'),'port'=>(int)env('MAIL_PORT',587),'username'=>env('MAIL_USERNAME'),'password'=>env('MAIL_PASSWORD'),'timeout'=>15],
  'log'=>['transport'=>'log','channel'=>null],
  'array'=>['transport'=>'array'],
 ],
 'from'=>['address'=>env('MAIL_FROM_ADDRESS','noreply@example.test'),'name'=>env('MAIL_FROM_NAME','Sunrise Academy')],
];
