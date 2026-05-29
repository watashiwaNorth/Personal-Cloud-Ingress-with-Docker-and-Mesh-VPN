<?php
$CONFIG = array (
  'instanceid' => '{example_instance_id}',
  'passwordsalt' => '{example_password_salt}',
  'secret' => '{example_secret_key}',

  // Security Layer 1: Access control
  'trusted_domains' =>
  array (
    0 => 'localhost:8080',
    1 => '{example_node_tailscale_ip}:8080',
    2 => '{example_device_name}.{example_tailscale_subdomain}.ts.net',
  ),

  'datadirectory' => '/var/www/html/data',
  'dbtype' => 'mysql',
  'version' => '33.0.3.2',

  // Security Layer 2: Reverse proxy overwrite settings
  'overwrite.cli.url' => 'https://{example_device_name}.{example_tailscale_subdomain}.ts.net',
  'overwritehost' => '{example_device_name}.{example_tailscale_subdomain}.ts.net',
  'overwriteprotocol' => 'https',

  'dbname' => 'nextcloud',
  'dbhost' => 'db',
  'dbtableprefix' => 'oc_',
  'mysql.utf8mb4' => true,
  'dbuser' => 'nextcloud',
  'dbpassword' => '{example_db_user_password}',
  'installed' => true,
);
