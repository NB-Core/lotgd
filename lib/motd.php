<?php
use Lotgd\Motd;

function motd_admin($id, $poll = false)
{
    Motd::motd_admin($id, $poll);
}

function motditem($subject, $body, $author, $date, $id)
{
    Motd::motditem($subject, $body, $author, $date, $id);
}

function pollitem($id, $subject, $body, $author, $date, $showpoll = true)
{
    Motd::pollitem($id, $subject, $body, $author, $date, $showpoll);
}

function motd_form($id)
{
    Motd::motd_form($id);
}

function motd_poll_form()
{
    Motd::motd_poll_form();
}

function motd_del($id)
{
    Motd::motd_del($id);
}
