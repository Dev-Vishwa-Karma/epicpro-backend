<?php         

require_once('vendor/autoload.php');
$config = require __DIR__ . '/config.php';

  $options = array(
    'cluster' => 'ap2',
    'useTLS' => true
  );
  $pusher = new Pusher\Pusher(
    $config['pusher']['key'],
    $config['pusher']['secret'],
    $config['pusher']['app_id'],
    $options
  );

?>
