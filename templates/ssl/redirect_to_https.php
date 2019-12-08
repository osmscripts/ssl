<?php
/* @var string $domain */
?>


server {
    if ($host = <?php echo $domain ?>) {
        return 301 https://$host$request_uri;
    }


    listen 80;
    listen [::]:80;
    server_name <?php echo $domain ?>;
    return 404;
}

