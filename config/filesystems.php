<?php
return ['default'=>env('FILESYSTEM_DISK','local'),'disks'=>[
 'local'=>['driver'=>'local','root'=>storage_path('app/private'),'serve'=>false,'throw'=>true,'visibility'=>'private'],
 'identity_quarantine'=>['driver'=>'local','root'=>storage_path('app/private/identity-quarantine'),'serve'=>false,'throw'=>true,'visibility'=>'private'],
 'identity_private'=>['driver'=>'local','root'=>storage_path('app/private/identity-private'),'serve'=>false,'throw'=>true,'visibility'=>'private'],
 'certificates'=>['driver'=>'local','root'=>storage_path('app/private/certificates'),'serve'=>false,'throw'=>true,'visibility'=>'private'],
 'helpdesk_quarantine'=>['driver'=>'local','root'=>storage_path('app/private/helpdesk-quarantine'),'serve'=>false,'throw'=>true,'visibility'=>'private'],
 'helpdesk_private'=>['driver'=>'local','root'=>storage_path('app/private/helpdesk-private'),'serve'=>false,'throw'=>true,'visibility'=>'private'],
 'store_quarantine'=>['driver'=>'local','root'=>storage_path('app/private/store-quarantine'),'serve'=>false,'throw'=>true,'visibility'=>'private'],
 'store_private'=>['driver'=>'local','root'=>storage_path('app/private/store-private'),'serve'=>false,'throw'=>true,'visibility'=>'private'],
 'exports'=>['driver'=>'local','root'=>storage_path('app/private/exports'),'serve'=>false,'throw'=>true,'visibility'=>'private']]];
