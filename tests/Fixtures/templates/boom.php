partial output<?php
ob_start();
echo 'nested';
throw new \RuntimeException('x');
