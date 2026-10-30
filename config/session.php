<?php
return ['driver'=>env('SESSION_DRIVER','file'),'lifetime'=>(int)env('SESSION_LIFETIME',120),
 'expire_on_close'=>false,'encrypt'=>true,'files'=>storage_path('framework/sessions'),
 'connection'=>env('SESSION_CONNECTION'),'table'=>'sessions','store'=>env('SESSION_STORE'),
 'lottery'=>[2,100],'cookie'=>'sunrise_academy_session','path'=>'/','domain'=>null,
 'secure'=>env('APP_ENV','production') === 'local' ? (bool) env('SESSION_SECURE_COOKIE',false) : true,'http_only'=>true,'same_site'=>'lax','partitioned'=>false];
