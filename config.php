<?php
// config.php
return [
    'email' => [
        'transport' => 'smtp',
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'username' => 'testing.developer01@gmail.com',
        'password' => 'ryvbrhcpojistdjf',
        'from_email' => 'testing.developer01@gmail.com',
        'from_name' => 'hr-profilics',
        'subject' => 'connect@profilics',
        'redirect_path' => 'https://hr.profilics.com/notifications'
    ],
    'pusher' => [
        'app_id' => '2138923',
        'key' => 'f77b8bad1d56965b1b7c',
        'secret' => '89524600f019f2273441',
        'cluster' => 'ap2',
        'useTLS' => true,
        'channel' => 'my-channel',
    ],
    'database' => [
        'host' => 'localhost',
        'username' => 'root',
        'password' => '',
        'name' => 'epic_hrr',
    ],
];