<?php

// Optional one-time CLI bootstrap. Remove SETUP_* after the first installation.
return [
    'name'=>env('SETUP_ADMIN_NAME'),
    'email'=>env('SETUP_ADMIN_EMAIL'),
    'password'=>env('SETUP_ADMIN_PASSWORD'),
    'organization'=>env('SETUP_ORGANIZATION'),
    'school'=>env('SETUP_SCHOOL'),
];
