<?php         
require_once __DIR__ . '/vendor/autoload.php';
// $config = require __DIR__ . '/config.php';

function getPusher($config) {

    $options = [
        'cluster' => $config['pusher']['cluster'],
        'useTLS' => $config['pusher']['useTLS'] ?? true,
    ];

    return new Pusher\Pusher(
        $config['pusher']['key'],
        $config['pusher']['secret'],
        $config['pusher']['app_id'],
        $options
    );
}

?>
