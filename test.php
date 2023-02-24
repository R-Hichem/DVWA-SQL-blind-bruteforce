<?php
require 'vendor/autoload.php';

function test(...$args){
    echo sprintf("There are %s million bicycles in %s. %s",...$args);
}

test("hello");

dd(password_get_info("5f4dcc3b5aa765d61d8327deb882cf99"));