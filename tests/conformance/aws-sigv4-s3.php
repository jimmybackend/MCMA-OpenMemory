<?php
declare(strict_types=1);
require_once __DIR__ . '/../../packages/core/src/Storage/AwsSigV4.php';
use MCMA\Core\Storage\AwsSigV4;

$body='Welcome to Amazon S3.';
$r=AwsSigV4::sign(
    'PUT',
    'examplebucket.s3.amazonaws.com',
    '/test$file.text',
    [],
    [
        'Date'=>'Fri, 24 May 2013 00:00:00 GMT',
        'x-amz-storage-class'=>'REDUCED_REDUNDANCY',
    ],
    $body,
    'AKIAIOSFODNN7EXAMPLE',
    'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
    'us-east-1',
    's3',
    '20130524T000000Z'
);
$expected='98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd';
if($r['signature']!==$expected){
    fwrite(STDERR,"FAIL AWS SigV4\nExpected: $expected\nActual:   {$r['signature']}\nCanonical:\n{$r['canonical_request']}\n");exit(1);
}
echo "AWS S3 SigV4 official vector passed.\n";
