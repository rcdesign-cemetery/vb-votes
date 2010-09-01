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

        // sort by vote count
        $sql = 'SELECT
                    `targetid`, count(`vote`) AS vote_count
                FROM
                    `' . TABLE_PREFIX . 'votes` as `votes`
                WHERE
                     `targetid` IN (SELECT DISTINCT `targetid`
                                    FROM
                                    `' . TABLE_PREFIX . 'votes`
                                    WHERE
                                    `date` > ' . $time_line . ' AND `vote` = "' . $value . '" AND `contenttypeid` = "' . $content_type_id . '") 
                     AND `contenttypeid` = "' . $content_type_id . '" AND `vote` = "' . $value . '"
                GROUP BY
                    `targetid`
                ORDER BY
                    vote_count DESC
                LIMIT ' . $vbulletin->options['maxresults'];

        
        $posts = $db->query_read($sql);
        while ($post = $db->fetch_array($posts))
        {
            $results[] = array($content_type_id, $post['targetid']);
        }
        return $results;
    }

}
