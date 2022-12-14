<?php

// autoload_psr4.php @generated by Composer

$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);

return array(
    'Symfony\\Contracts\\Service\\' => array($vendorDir . '/symfony/service-contracts'),
    'Symfony\\Contracts\\HttpClient\\' => array($vendorDir . '/symfony/http-client-contracts'),
    'Symfony\\Component\\Mailer\\Bridge\\Amazon\\' => array($vendorDir . '/symfony/amazon-mailer'),
    'Psr\\Log\\' => array($vendorDir . '/psr/log/Psr/Log'),
    'Psr\\Container\\' => array($vendorDir . '/psr/container/src'),
    'Psr\\Cache\\' => array($vendorDir . '/psr/cache/src'),
    'Grav\\Plugin\\EmailAmazon\\' => array($baseDir . '/classes'),
    'AsyncAws\\Ses\\' => array($vendorDir . '/async-aws/ses/src'),
    'AsyncAws\\Core\\' => array($vendorDir . '/async-aws/core/src'),
);
