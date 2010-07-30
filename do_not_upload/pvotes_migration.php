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

// define vote types
define('VOTES_NEGATIVE', '-1');
define('VOTES_POSITIVE', '1');

echo "get content type id for vBForum Post\n";

$sql = 'SELECT
            ct.contenttypeid
        FROM ' . TABLE_PREFIX . 'contenttype As ct
        LEFT JOIN
            ' . TABLE_PREFIX . 'package AS p USING(`packageid`)
        WHERE
            ct.`class` = "Post" AND
            p.`productid` = "vbulletin"';
$result_set = $db->query($sql);
$row = $result_set->fetch_array();
$content_type_id = $row['id'];


echo "start convert thanks to positive votes\n";
// convert thanks
$sql = 'SELECT
            `id`
        FROM
            ' . TABLE_PREFIX . 'post_thanks AS pt
        ORDER BY `id` DESC
        LIMIT 1';
$result_set = $db->query($sql);
$row = $result_set->fetch_array();
$max_id = $row['id'];

echo "max thank id = " . $max_id . "\n";

$offset = 0;
while ($offset <= $max_id)
{
    $sql = 'INSERT IGNORE INTO ' . TABLE_PREFIX . 'votes
        SELECT 
            pt.`postid` AS targetid,
            ' . $content_type_id . ' AS contenttypeid,
            ' . VOTES_POSITIVE . ' AS vote,
            pt.`userid` AS fromuserid,
            p.`userid` AS touserid,
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
    $sql = 'INSERT IGNORE INTO ' . TABLE_PREFIX . 'votes
            SELECT
                pg.`postid` AS targetid,
                ' . $content_type_id . ' AS contenttypeid,
                ' . VOTES_NEGATIVE . ' AS vote,
                pg.`userid` AS fromuserid,
                p.`userid` AS touseridid,
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