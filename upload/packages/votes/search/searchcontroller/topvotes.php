<?php

if (!defined('VB_ENTRY'))
    die('Access denied.');


/**
 * @package Votes
 * @author rcdesign
 * @version 
 * @copyright 
 */
require_once(DIR . '/vb/search/searchcontroller.php');

class vBForum_Search_SearchController_TopVotes extends vB_Search_SearchController
{

    public function get_results($user, $criteria)
    {
        global $vbulletin;
        $db = $vbulletin->db;
        
        $results = array();

        $content_type_id = $criteria->get_contenttypeid();

        // vote value
        $equals_filters = $criteria->get_equals_filters();
        $value = $equals_filters['value'];
        $time_line = TIMENOW - 24 * 60 * 60 * $vbulletin->options['vbv_top_voted_days'];

        // get ids for top
        $sql = 'SELECT
                    DISTINCT `targetid`
                FROM
                    `' . TABLE_PREFIX . 'votes`
                WHERE
                    `date` > ' . $time_line . ' AND `vote` = "' . $value . '" AND `contenttypeid` = "' . $content_type_id . '"
                LIMIT ' . ($vbulletin->options['maxresults']);

        $target_id_list = array();
        $targets = $db->query_read_slave($sql);
        while ($target = $db->fetch_array($targets))
        {
            $target_id_list[] = $target['targetid'];
        }
        // did we get some results?
        if (empty($target_id_list))
        {
            return $results;
        }
        // sort by vote count
        $sql = 'SELECT
                    `targetid`, count(`vote`) AS vote_count
                FROM
                    `' . TABLE_PREFIX . 'votes`
                WHERE
                    `targetid` IN (' . implode($target_id_list, ', ') . ')  AND `contenttypeid` = "' . $content_type_id . '" AND `vote` = "' . $value . '"
                GROUP BY
                    `targetid`
                ORDER BY
                    vote_count DESC';

        
        $posts = $db->query_read($sql);
        while ($post = $db->fetch_array($posts))
        {
            $results[] = array($content_type_id, $post['targetid']);
        }
        return $results;
    }

}
