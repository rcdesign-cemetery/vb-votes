#!/usr/bin/php5
<?php

// run it from shell

error_reporting(E_ALL & ~E_NOTICE);
$host = 'localhost';
$login = 'login';
$pass = 'pass';
$shema = 'shema';
$db = new mysqli( $host, $login, $pass, $shema);
if( mysqli_connect_errno() )
{
    die( 'db :Could not connect: ' . mysqli_connect_error() );
}
define ('TABLE_PREFIX', '');
$limit = 1000;

echo "start convert thanks to positive votes\n";
// convert thanks
$sql = 'SELECT
            `id`
        FROM
            `' . TABLE_PREFIX . 'post_thanks` AS pt
        ORDER BY `id` DESC
        LIMIT 1';
$result_set = $db->query($sql);
$row = $result_set->fetch_array();
$max_id = $row['id'];

echo "max thank id = " . $max_id . "\n";

$offset = 0;
while ($offset <= $max_id)
{
    $sql = 'INSERT IGNORE INTO `' . TABLE_PREFIX . 'post_votes`
        SELECT 
            pt.`postid` AS targetid,
            "forum" AS targettype, 
            "1" AS vote,
            pt.`userid` ,
            p.`userid` AS postauthorid, 
            pt.`date`
        FROM 
            ' . TABLE_PREFIX . 'post_thanks AS pt
        LEFT JOIN post AS p USING ( `postid` )
        WHERE pt.id > ' . $offset . '
        LIMIT ' . $limit;
    $db->query($sql);
    echo '*';
    $offset += $limit;
}
echo "end convert thanks to positive votes\n\n";

echo "start convert groans to negative votes\n";
// convert grouns
$sql = 'SELECT
            `id`
        FROM
            ' . TABLE_PREFIX . 'post_groan AS pt
        ORDER BY `id` DESC
        LIMIT 1';
$result_set = $db->query($sql);
$row = $result_set->fetch_array();
$max_id = $row['id'];

echo "max groan id = " . $max_id . "\n";

$offset = 0;
while ($offset <= $max_id)
{
    $sql = 'INSERT IGNORE INTO ' . TABLE_PREFIX . 'post_votes
            SELECT
                pg.`postid` AS targetid,
                "forum" AS targettype,
                "-1" AS vote,
                pg.`userid` ,
                p.`userid` AS postauthorid,
                pg.`date`
            FROM
                ' . TABLE_PREFIX . 'post_groan AS pg
            LEFT JOIN post AS p USING ( `postid` )
            WHERE pg.id > ' . $offset . '
            LIMIT ' . $limit;
    $db->query($sql);
    $offset += $limit;
    echo '*';
}
echo "end convert groans to negative votes\n";
?>