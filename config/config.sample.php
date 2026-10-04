<?php
/**
 * AutoCare Cloud - SAMPLE configuration.
 * Copy this file to config.local.php and enter your real values.
 * Do NOT put AWS access keys here. On EC2 the IAM role provides credentials.
 */
return [
    'db_host'   => 'localhost',
    'db_name'   => 'autocare_cloud',
    'db_user'   => 'autocare_user',
    'db_pass'   => 'change_me',
    's3_bucket' => 'your-unique-bucket-name',
    's3_region' => 'ap-south-1',
];
