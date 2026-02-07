<?php

$this->extends('test::layout');

$this->block('content');
echo "Good morning {$this->title} {$this->name}.";
$this->endblock();
